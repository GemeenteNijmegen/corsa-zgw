<?php

use App\Jobs\ProcessNotification;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\BatchingService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Log::spy());

// ─── helpers ────────────────────────────────────────────────────────────────

function makeNotification(Batch $batch, string $actie = 'create', string $resource = 'zaak'): Notification
{
    return Notification::create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => $batch->zaak_identificatie,
        'notification' => ['actie' => $actie, 'resource' => $resource],
        'processed' => false,
    ]);
}

function makeBatch(string $zaakId = 'ZAAK-001', array $attributes = []): Batch
{
    return Batch::create(array_merge([
        'zaak_identificatie' => $zaakId,
        'status' => 'pending',
    ], $attributes));
}

// ─── getOrCreateBatch ────────────────────────────────────────────────────────

test('getOrCreateBatch creates a new batch when none exists', function () {
    $service = new BatchingService;

    $batch = $service->getOrCreateBatch('ZAAK-001');

    expect(Batch::count())->toBe(1)
        ->and($batch->zaak_identificatie)->toBe('ZAAK-001')
        ->and($batch->status)->toBe('pending');
});

test('getOrCreateBatch returns existing unlocked batch', function () {
    $existing = makeBatch('ZAAK-001');
    $service = new BatchingService;

    $batch = $service->getOrCreateBatch('ZAAK-001');

    expect(Batch::count())->toBe(1)
        ->and($batch->id)->toBe($existing->id);
});

test('getOrCreateBatch creates a new batch when existing batch is locked', function () {
    makeBatch('ZAAK-001', ['locked_at' => now()]);
    $service = new BatchingService;

    $batch = $service->getOrCreateBatch('ZAAK-001');

    expect(Batch::count())->toBe(2)
        ->and($batch->locked_at)->toBeNull();
});

test('getOrCreateBatch ignores batches from other zaak identificaties', function () {
    makeBatch('ZAAK-OTHER');
    $service = new BatchingService;

    $batch = $service->getOrCreateBatch('ZAAK-001');

    expect(Batch::count())->toBe(2)
        ->and($batch->zaak_identificatie)->toBe('ZAAK-001');
});

// ─── addNotificationToBatch ──────────────────────────────────────────────────

test('addNotificationToBatch associates notification with batch', function () {
    $batch = makeBatch();
    $notification = Notification::create([
        'zaak_identificatie' => 'ZAAK-001',
        'notification' => ['actie' => 'create', 'resource' => 'zaak'],
        'processed' => false,
    ]);
    $service = new BatchingService;

    $service->addNotificationToBatch($notification, $batch);

    expect($notification->fresh()->batch_id)->toBe($batch->id);
});

test('addNotificationToBatch does not update notification already in batch', function () {
    $batch = makeBatch();
    $notification = makeNotification($batch);
    $service = new BatchingService;

    $this->travel(1)->seconds();
    $originalUpdatedAt = $notification->fresh()->updated_at;

    $service->addNotificationToBatch($notification, $batch);

    expect($notification->fresh()->updated_at->eq($originalUpdatedAt))->toBeTrue();
});

test('addNotificationToBatch resets the timer', function () {
    $batch = makeBatch();
    $notification = makeNotification($batch);
    $service = new BatchingService;

    $service->addNotificationToBatch($notification, $batch);

    expect(Cache::has("batch_timer:{$batch->id}"))->toBeTrue();
});

// ─── resetTimer ──────────────────────────────────────────────────────────────

test('resetTimer stores a cache entry for the batch', function () {
    $batch = makeBatch();
    $service = new BatchingService;

    $service->resetTimer($batch);

    expect(Cache::has("batch_timer:{$batch->id}"))->toBeTrue();
});

test('resetTimer uses the configured timeout', function () {
    config(['batching.batch_timeout' => 5]);
    $batch = makeBatch();
    $service = new BatchingService;

    $service->resetTimer($batch);
    expect(Cache::has("batch_timer:{$batch->id}"))->toBeTrue();

    $this->travel(6)->seconds();
    expect(Cache::has("batch_timer:{$batch->id}"))->toBeFalse();
});

test('resetTimer overwrites previous timer entry', function () {
    config(['batching.batch_timeout' => 10]);
    $batch = makeBatch();
    $service = new BatchingService;

    $service->resetTimer($batch);
    $this->travel(8)->seconds();
    $service->resetTimer($batch);

    $this->travel(8)->seconds();
    expect(Cache::has("batch_timer:{$batch->id}"))->toBeTrue();
});

// ─── hasActiveTimer ──────────────────────────────────────────────────────────

test('hasActiveTimer returns true when timer is in cache', function () {
    $batch = makeBatch();
    Cache::put("batch_timer:{$batch->id}", true, 60);
    $service = new BatchingService;

    expect($service->hasActiveTimer($batch))->toBeTrue();
});

test('hasActiveTimer returns false when no timer in cache', function () {
    $batch = makeBatch();
    $service = new BatchingService;

    expect($service->hasActiveTimer($batch))->toBeFalse();
});

test('hasActiveTimer returns false after timer expires', function () {
    config(['batching.batch_timeout' => 1]);
    $batch = makeBatch();
    $service = new BatchingService;

    $service->resetTimer($batch);
    $this->travel(2)->seconds();

    expect($service->hasActiveTimer($batch))->toBeFalse();
});

// ─── getUnprocessedBatches ───────────────────────────────────────────────────

test('getUnprocessedBatches returns pending batches with no active timer', function () {
    $batch = makeBatch();
    $service = new BatchingService;

    $results = $service->getUnprocessedBatches();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($batch->id);
});

test('getUnprocessedBatches excludes batches with an active timer', function () {
    $batch = makeBatch();
    Cache::put("batch_timer:{$batch->id}", true, 60);
    $service = new BatchingService;

    expect($service->getUnprocessedBatches())->toHaveCount(0);
});

test('getUnprocessedBatches excludes locked batches', function () {
    makeBatch('ZAAK-001', ['locked_at' => now()]);
    $service = new BatchingService;

    expect($service->getUnprocessedBatches())->toHaveCount(0);
});

test('getUnprocessedBatches excludes non-pending batches', function () {
    makeBatch('ZAAK-001', ['status' => 'processed']);
    $service = new BatchingService;

    expect($service->getUnprocessedBatches())->toHaveCount(0);
});

test('getUnprocessedBatches returns multiple eligible batches', function () {
    makeBatch('ZAAK-001');
    makeBatch('ZAAK-002');
    $excluded = makeBatch('ZAAK-003');
    Cache::put("batch_timer:{$excluded->id}", true, 60);
    $service = new BatchingService;

    expect($service->getUnprocessedBatches())->toHaveCount(2);
});

// ─── processBatch ────────────────────────────────────────────────────────────

test('processBatch returns early without dispatching jobs when batch is already locked', function () {
    Bus::fake();
    $batch = makeBatch('ZAAK-001', ['locked_at' => now()]);
    $service = new BatchingService;

    $service->processBatch($batch);

    Bus::assertNothingDispatched();
    expect($batch->fresh()->status)->toBe('pending');
});

test('processBatch locks the batch and sets status to processing then processed', function () {
    Bus::fake();
    $batch = makeBatch();
    makeNotification($batch);
    $service = new BatchingService;

    $service->processBatch($batch);

    $fresh = $batch->fresh();
    expect($fresh->isLocked())->toBeTrue()
        ->and($fresh->isProcessed())->toBeTrue();
});

test('processBatch marks batch as processed with no jobs when it has no notifications', function () {
    Bus::fake();
    $batch = makeBatch();
    $service = new BatchingService;

    $service->processBatch($batch);

    Bus::assertNothingDispatched();
    expect($batch->fresh()->isProcessed())->toBeTrue()
        ->and($batch->fresh()->status)->toBe('processed');
});

// ─── processBatch – with zaak aangemaakt ─────────────────────────────────────

test('processBatch dispatches a chain when batch contains zaak aangemaakt', function () {
    Bus::fake();
    $batch = makeBatch();
    makeNotification($batch, 'create', 'zaak');
    $service = new BatchingService;

    $service->processBatch($batch);

    Bus::assertChained([ProcessNotification::class]);
});

test('processBatch chains one job per notification when batch contains zaak aangemaakt with other notifications', function () {
    Bus::fake();
    $batch = makeBatch();
    makeNotification($batch, 'create', 'status');
    makeNotification($batch, 'create', 'zaak');
    $service = new BatchingService;

    $service->processBatch($batch);

    Bus::assertChained([ProcessNotification::class, ProcessNotification::class]);
});

test('processBatch marks batch as processed after dispatching jobs', function () {
    Bus::fake();
    $batch = makeBatch();
    makeNotification($batch, 'create', 'zaak');
    $service = new BatchingService;

    $service->processBatch($batch);

    expect($batch->fresh()->isProcessed())->toBeTrue();
});

// ─── processBatch – without zaak aangemaakt ──────────────────────────────────

test('processBatch dispatches one chained job per notification when no zaak aangemaakt', function () {
    Bus::fake();
    $batch = makeBatch();
    makeNotification($batch, 'create', 'status');
    makeNotification($batch, 'create', 'zaakinformatieobject');
    $service = new BatchingService;

    $service->processBatch($batch);

    Bus::assertChained([ProcessNotification::class, ProcessNotification::class]);
});

test('processBatch dispatches jobs on the configured queue', function () {
    Bus::fake();
    config(['batching.queue' => 'notifications']);
    $batch = makeBatch();
    makeNotification($batch, 'update', 'status');
    $service = new BatchingService;

    $service->processBatch($batch);

    Bus::assertChained([ProcessNotification::class]);
});
