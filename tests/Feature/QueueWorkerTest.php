<?php

use App\Exceptions\DocumentLockedException;
use App\Jobs\Notifications\HandleNotification;
use App\Jobs\Notifications\ProcessDocumentDirectly;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\BatchingService;
use App\Services\CorsaZaakdmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Retries and failures of the notification jobs under the real queue worker. The sync queue used
 * by the other tests fails a job on any exception, regardless of its tries and maxExceptions, so
 * it cannot show how releases and errors are counted. These tests use the database queue on the
 * test database and run the worker one job at a time.
 */

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Log::spy();

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config(['queue.default' => 'database', 'queue.failed.driver' => 'null']);
});

/**
 * Let the worker take one job from the queue. The supervisor in production also runs with one try;
 * the jobs set their own limits.
 */
function workOneJob(string $queue): void
{
    test()->artisan('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--queue' => $queue,
        '--tries' => 1,
        '--sleep' => 0,
    ])->run();
}

// ─── Direct job ──────────────────────────────────────────────────────────────

test('waiting for the lock does not use up the retries of the direct job for temporary errors', function () {
    $notification = Notification::factory()->direct()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $calls = 0;
    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('addDocumentToZaak')
        ->andReturnUsing(function () use (&$calls) {
            if (++$calls <= 3) {
                throw new DocumentLockedException;
            }

            throw new ConnectionException('timed out');
        });

    ProcessDocumentDirectly::dispatch($notification);

    // Three waits for the lock, then one temporary error.
    for ($pickup = 1; $pickup <= 4; $pickup++) {
        workOneJob(ProcessDocumentDirectly::QUEUE);
        $this->travel(ProcessDocumentDirectly::LOCK_RETRY_SECONDS + 1)->seconds();
    }

    expect($calls)->toBe(4)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($notification->fresh()->failed_at)->toBeNull();
});

test('the direct job fails at the fourth temporary error in a row and records it', function () {
    $notification = Notification::factory()->direct()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $calls = 0;
    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('addDocumentToZaak')
        ->andReturnUsing(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('timed out');
        });

    ProcessDocumentDirectly::dispatch($notification);
    $uuid = json_decode((string) DB::table('jobs')->value('payload'), true)['uuid'];

    for ($pickup = 1; $pickup <= 3; $pickup++) {
        workOneJob(ProcessDocumentDirectly::QUEUE);
        expect($notification->fresh()->failed_at)->toBeNull();
        // Past the longest backoff.
        $this->travel(301)->seconds();
    }

    workOneJob(ProcessDocumentDirectly::QUEUE);

    expect($calls)->toBe(ProcessDocumentDirectly::ERROR_ATTEMPTS)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($notification->fresh()->failure)->toBe('ConnectionException: timed out')
        ->and($notification->fresh()->failed_job_id)->toBe($uuid);
});

// ─── Batch job ───────────────────────────────────────────────────────────────

test('an error still fails the batch job after one attempt and records it', function () {
    $notification = Notification::factory()->forZaak()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('processZaakAangemaakt')
        ->once()
        ->andThrow(new RuntimeException('Fo02 refused'));

    HandleNotification::dispatch($notification);
    $uuid = json_decode((string) DB::table('jobs')->value('payload'), true)['uuid'];

    workOneJob('default');

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($notification->fresh()->failure)->toBe('RuntimeException: Fo02 refused')
        ->and($notification->fresh()->failed_job_id)->toBe($uuid);
});

test('the batch job waits for the lock without failing, up to the duration of the lock', function () {
    $notification = Notification::factory()->forZaak()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('processZaakAangemaakt')
        ->andThrow(new DocumentLockedException);

    HandleNotification::dispatch($notification);

    $job = new HandleNotification($notification);
    for ($pickup = 1; $pickup <= $job->tries; $pickup++) {
        workOneJob('default');

        expect(DB::table('jobs')->value('attempts'))->toBe($pickup)
            ->and($notification->fresh()->failed_at)->toBeNull();

        $this->travel(HandleNotification::LOCK_RETRY_SECONDS + 1)->seconds();
    }

    // A lock that is still held after that is not released by its job any more; give up.
    workOneJob('default');

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($notification->fresh()->failure)->toContain('MaxAttemptsExceededException');
});

// ─── Batch chain ─────────────────────────────────────────────────────────────

/**
 * @return array{0: Batch, 1: Notification, 2: Notification}
 */
function batchWithZaakAndStatus(): array
{
    $batch = Batch::factory()->pending()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $zaak = Notification::factory()->forZaak()->create(['batch_id' => $batch->id, 'zaak_identificatie' => 'ZAAK-0001']);
    $status = Notification::factory()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => 'ZAAK-0001',
        'notification' => ['actie' => 'create', 'kanaal' => 'zaken', 'resource' => 'status'],
    ]);

    return [$batch, $zaak, $status];
}

test('a batch is marked as processed only after the last job of its chain, also when a job waited for the lock', function () {
    [$batch, $zaak, $status] = batchWithZaakAndStatus();
    $calls = 0;
    $service = $this->mock(CorsaZaakdmsService::class);
    $service->shouldReceive('processZaakAangemaakt')
        ->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new DocumentLockedException;
            }
        });
    $service->shouldReceive('processZaakPartialUpdate')->once();

    app(BatchingService::class)->processBatch($batch);

    // The first job waits for the lock: it is released, and the chain does not continue.
    workOneJob('default');
    expect($batch->fresh()->status)->toBe('processing')
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('payload'))->toContain('HandleNotification');

    $this->travel(HandleNotification::LOCK_RETRY_SECONDS + 1)->seconds();
    workOneJob('default');
    expect($zaak->fresh()->processed)->toBeTrue()
        ->and($batch->fresh()->status)->toBe('processing');

    workOneJob('default');
    expect($status->fresh()->processed)->toBeTrue()
        ->and($batch->fresh()->status)->toBe('processing')
        ->and(DB::table('jobs')->value('payload'))->toContain('MarkBatchProcessed');

    workOneJob('default');
    expect($batch->fresh()->status)->toBe('processed')
        ->and($batch->fresh()->processed_at)->not->toBeNull()
        ->and($batch->fresh()->isIncomplete())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a batch whose chain stops at a failed job stays processing, with the failure as its signal', function () {
    [$batch, $zaak, $status] = batchWithZaakAndStatus();
    $service = $this->mock(CorsaZaakdmsService::class);
    $service->shouldReceive('processZaakAangemaakt')->once()->andThrow(new RuntimeException('Fo02 refused'));
    $service->shouldNotReceive('processZaakPartialUpdate');

    app(BatchingService::class)->processBatch($batch);

    workOneJob('default');

    $fresh = $batch->fresh();
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($fresh->status)->toBe('processing')
        ->and($fresh->processed_at)->toBeNull()
        ->and($fresh->failedNotificationsCount())->toBe(1)
        ->and($fresh->isIncomplete())->toBeFalse()
        ->and($status->fresh()->status())->toBe(App\Enums\NotificationStatus::Pending);
});
