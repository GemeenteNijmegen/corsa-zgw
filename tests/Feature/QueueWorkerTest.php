<?php

use App\Exceptions\DocumentLockedException;
use App\Jobs\Notifications\HandleNotification;
use App\Jobs\Notifications\ProcessDocumentDirectly;
use App\Models\Notification;
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
