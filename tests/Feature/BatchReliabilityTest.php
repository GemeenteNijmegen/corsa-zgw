<?php

use App\Enums\NotificationIgnoreReason;
use App\Jobs\Notifications\DispatchBatch;
use App\Jobs\Notifications\FlushExpiredBatches;
use App\Jobs\Notifications\HandleNotification;
use App\Jobs\Notifications\MarkBatchProcessed;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\BatchingService;
use App\Services\CorsaZaakdmsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Log::spy());

// These tests run on SQLite, in one process. They show the logic of the lock and of linking a
// notification to a batch, not two workers at the same time: SQLite has no row locks and a test
// cannot run a second worker in between. The row lock itself is checked on PostgreSQL outside the
// suite.

function reliabilityBatch(array $attributes = []): Batch
{
    return Batch::create(array_merge([
        'zaak_identificatie' => 'ZAAK-2026-0101',
        'status' => 'pending',
    ], $attributes));
}

function reliabilityNotification(Batch $batch, string $actie = 'create', string $resource = 'zaak', array $attributes = []): Notification
{
    return Notification::create(array_merge([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
        'notification' => ['actie' => $actie, 'kanaal' => 'zaken', 'resource' => $resource],
        'processed' => false,
    ], $attributes));
}

// ─── Atomic lock of a batch ──────────────────────────────────────────────────

test('a batch is locked by one conditional update, so only the first of two calls locks it', function () {
    $batch = reliabilityBatch();
    $stale = Batch::find($batch->id);

    expect($batch->lockForProcessing())->toBeTrue()
        ->and($stale->lockForProcessing())->toBeFalse();

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe('processing')
        ->and($fresh->locked_at)->not->toBeNull()
        ->and($stale->isLocked())->toBeTrue();
});

test('a second job for the same batch does not build a second chain, even with a copy loaded before the lock', function () {
    Bus::fake();
    $batch = reliabilityBatch();
    reliabilityNotification($batch);

    // Both jobs loaded the batch before either locked it, as two workers do.
    $first = Batch::find($batch->id);
    $second = Batch::find($batch->id);

    app(BatchingService::class)->processBatch($first);
    app(BatchingService::class)->processBatch($second);

    Bus::assertDispatchedTimes(HandleNotification::class, 1);
    Log::shouldHaveReceived('warning')
        ->with('Attempted to process already locked batch', ['batch_id' => $batch->id])
        ->once();
});

test('the lock of a batch keeps the time of the first lock', function () {
    $lockedAt = Carbon::parse('2026-01-15 10:00:00');
    $batch = reliabilityBatch(['locked_at' => $lockedAt, 'status' => 'processing']);

    expect($batch->lockForProcessing())->toBeFalse()
        ->and($batch->fresh()->locked_at->equalTo($lockedAt))->toBeTrue();
});

test('the scheduler does not dispatch a batch again once it is locked', function () {
    Bus::fake();
    $batch = reliabilityBatch();
    reliabilityNotification($batch);

    app(BatchingService::class)->processBatch($batch);
    app()->call([new FlushExpiredBatches, 'handle']);

    Bus::assertNotDispatched(DispatchBatch::class);
});

// ─── Linking a notification to a batch ───────────────────────────────────────

test('a notification that arrives after its batch is locked goes to a new batch', function () {
    Bus::fake();
    $service = app(BatchingService::class);
    $batch = reliabilityBatch();
    reliabilityNotification($batch);

    $service->processBatch($batch);

    $late = DB::transaction(function () use ($service): array {
        $open = $service->getOrCreateBatch('ZAAK-2026-0101');
        $notification = Notification::create([
            'zaak_identificatie' => 'ZAAK-2026-0101',
            'notification' => ['actie' => 'create', 'kanaal' => 'zaken', 'resource' => 'status'],
            'processed' => false,
        ]);
        $service->addNotificationToBatch($notification, $open);

        return [$open, $notification];
    });

    [$open, $notification] = $late;
    expect($open->id)->not->toBe($batch->id)
        ->and($open->isLocked())->toBeFalse()
        ->and($notification->fresh()->batch_id)->toBe($open->id)
        ->and($service->hasActiveTimer($open))->toBeTrue();
});

// ─── Stopping early ──────────────────────────────────────────────────────────

test('a batch job for a notification that is already processed or ignored does nothing and lets the chain go on', function (array $attributes) {
    $batch = reliabilityBatch(['status' => 'processing', 'locked_at' => now()]);
    $notification = reliabilityNotification($batch);
    $job = (new HandleNotification($notification))->withFakeQueueInteractions();

    // The job carries the notification as it was when the chain was built.
    $notification->newQuery()->whereKey($notification->id)->update($attributes);

    $this->mock(CorsaZaakdmsService::class)->shouldNotReceive(
        'processZaakAangemaakt', 'processZaakPartialUpdate', 'processDocumentAangemaakt', 'processResultaatAangemaakt', 'updateDocument',
    );

    app()->call([$job, 'handle']);

    $job->assertNotFailed();
    $job->assertNotReleased();
    Log::shouldHaveReceived('info')
        ->with('Notification already handled, skipping', Mockery::on(fn (array $context) => $context['notification_id'] === $notification->id))
        ->once();
})->with([
    'processed' => [['processed' => true, 'processed_at' => '2026-01-15 10:00:00']],
    'ignored' => [['ignored_at' => '2026-01-15 10:00:00', 'ignored_reason' => NotificationIgnoreReason::UnsupportedAction->value]],
]);

test('a processed notification keeps the time it was first processed when a second chain runs it', function () {
    $batch = reliabilityBatch(['status' => 'processing', 'locked_at' => now()]);
    $notification = reliabilityNotification($batch, attributes: ['processed' => true, 'processed_at' => '2026-01-15 10:00:00']);

    $this->mock(CorsaZaakdmsService::class)->shouldNotReceive('processZaakAangemaakt');

    app()->call([new HandleNotification($notification), 'handle']);

    expect($notification->fresh()->processed_at->equalTo(Carbon::parse('2026-01-15 10:00:00')))->toBeTrue();
});

test('the last job of a second chain keeps the time the batch was first processed', function () {
    $processedAt = Carbon::parse('2026-01-15 10:00:00');
    $batch = reliabilityBatch(['status' => 'processed', 'locked_at' => $processedAt, 'processed_at' => $processedAt]);

    (new MarkBatchProcessed($batch))->handle();

    expect($batch->fresh()->processed_at->equalTo($processedAt))->toBeTrue()
        ->and($batch->fresh()->status)->toBe('processed');
    Log::shouldHaveReceived('info')->with('Batch already processed, skipping', ['batch_id' => $batch->id])->once();
});

test('the last job of the chain marks a batch that is not processed yet', function () {
    $batch = reliabilityBatch(['status' => 'processing', 'locked_at' => now()]);

    (new MarkBatchProcessed($batch))->handle();

    expect($batch->fresh()->status)->toBe('processed')
        ->and($batch->fresh()->processed_at)->not->toBeNull();
    Log::shouldNotHaveReceived('info', ['Batch already processed, skipping', ['batch_id' => $batch->id]]);
});
