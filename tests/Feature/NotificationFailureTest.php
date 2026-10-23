<?php

use App\Enums\NotificationRoute;
use App\Enums\NotificationStatus;
use App\Jobs\Notifications\HandleNotification;
use App\Jobs\Notifications\ProcessDocumentDirectly;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\BatchingService;
use App\Services\CorsaZaakdmsService;
use App\Support\FailureDescription;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Recording a job that failed for good on its notification, and clearing it again when a later
 * run processes the notification.
 */

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Log::spy();
});

function failingNotification(array $attributes = []): Notification
{
    return Notification::factory()->direct()->create([
        'zaak_identificatie' => 'ZAAK-0001',
        ...$attributes,
    ]);
}

// ─── Recording a failure ─────────────────────────────────────────────────────

test('a direct job that fails for good records the failure with the id of the failed job', function () {
    $notification = failingNotification();
    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('addDocumentToZaak')
        ->andThrow(new RuntimeException('Missing inhoud url for document download'));

    $failedJobUuid = null;
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failedJobUuid) {
        $failedJobUuid = $event->job->uuid();
    });

    // The sync queue runs the job and its failed() hook the way a worker does.
    try {
        ProcessDocumentDirectly::dispatch($notification);
    } catch (Throwable) {
        // The sync queue rethrows the failure.
    }

    $notification->refresh();

    expect($notification->failed_at)->not->toBeNull()
        ->and($notification->failure)->toBe('RuntimeException: Missing inhoud url for document download')
        ->and($notification->failed_job_id)->toBe($failedJobUuid)
        ->and(Str::isUuid($notification->failed_job_id))->toBeTrue()
        ->and($notification->processed)->toBeFalse()
        ->and($notification->status())->toBe(NotificationStatus::Failed);
});

test('a batch job that fails for good records the failure with the id of the failed job', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $notification = Notification::factory()->forZaak()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => 'ZAAK-0001',
    ]);
    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('processZaakAangemaakt')
        ->andThrow(new RuntimeException('Fo02 refused'));

    $failedJobUuid = null;
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failedJobUuid) {
        $failedJobUuid = $event->job->uuid();
    });

    try {
        HandleNotification::dispatch($notification);
    } catch (Throwable) {
        // The sync queue rethrows the failure.
    }

    $notification->refresh();

    expect($notification->failed_at)->not->toBeNull()
        ->and($notification->failure)->toBe('RuntimeException: Fo02 refused')
        ->and($notification->failed_job_id)->toBe($failedJobUuid)
        ->and($notification->status())->toBe(NotificationStatus::Failed);
});

test('a direct job that is retried after a temporary error records no failure', function () {
    $notification = failingNotification();
    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('addDocumentToZaak')
        ->andThrow(new ConnectionException('timed out'));

    $job = (new ProcessDocumentDirectly($notification))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(CorsaZaakdmsService::class), app(BatchingService::class)))
        ->toThrow(ConnectionException::class);

    expect($notification->fresh()->failed_at)->toBeNull()
        ->and($notification->fresh()->status())->toBe(NotificationStatus::Pending);
});

// ─── Clearing a failure ──────────────────────────────────────────────────────

test('a later successful direct run clears the failure', function () {
    $notification = failingNotification();
    $notification->update(['failed_at' => now(), 'failure' => 'RuntimeException: earlier', 'failed_job_id' => (string) Str::uuid()]);

    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('addDocumentToZaak')
        ->andReturn(App\Enums\DocumentSyncOutcome::Added);

    (new ProcessDocumentDirectly($notification))->withFakeQueueInteractions()
        ->handle(app(CorsaZaakdmsService::class), app(BatchingService::class));

    $notification->refresh();

    expect($notification->processed)->toBeTrue()
        ->and($notification->failed_at)->toBeNull()
        ->and($notification->failure)->toBeNull()
        ->and($notification->failed_job_id)->toBeNull()
        ->and($notification->status())->toBe(NotificationStatus::Processed);
});

test('a later direct run that moves the notification to the batch clears the failure', function () {
    $notification = failingNotification();
    $notification->update(['failed_at' => now(), 'failure' => 'RuntimeException: earlier', 'failed_job_id' => (string) Str::uuid()]);

    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('addDocumentToZaak')
        ->andReturn(App\Enums\DocumentSyncOutcome::ZaakNotInCorsa);

    (new ProcessDocumentDirectly($notification))->withFakeQueueInteractions()
        ->handle(app(CorsaZaakdmsService::class), app(BatchingService::class));

    $notification->refresh();

    expect($notification->route)->toBe(NotificationRoute::Batch)
        ->and($notification->failed_at)->toBeNull()
        ->and($notification->failed_job_id)->toBeNull()
        ->and($notification->status())->toBe(NotificationStatus::Pending);
});

test('a later successful batch run clears the failure', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => 'ZAAK-0001']);
    $notification = Notification::factory()->forZaak()->failed()->create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => 'ZAAK-0001',
    ]);
    $this->mock(CorsaZaakdmsService::class)->shouldReceive('processZaakAangemaakt')->once();

    (new HandleNotification($notification))->withFakeQueueInteractions()
        ->handle(app(CorsaZaakdmsService::class));

    $notification->refresh();

    expect($notification->processed)->toBeTrue()
        ->and($notification->failed_at)->toBeNull()
        ->and($notification->failure)->toBeNull()
        ->and($notification->failed_job_id)->toBeNull();
});

// ─── Description and link ────────────────────────────────────────────────────

test('the failure description of an HTTP error keeps the status and not the response body', function () {
    $response = new Response(new Psr7Response(500, [], '{"detail":"Testpersoon 000000000"}'));

    expect(FailureDescription::from(new RequestException($response)))->toBe('RequestException: HTTP 500');
});

test('the failure description of a database error keeps the SQLSTATE and not the connection or the query', function () {
    try {
        DB::table('table_that_does_not_exist')->where('zaak_identificatie', 'ZAAK-0001')->get();
        $this->fail('The query should have failed');
    } catch (QueryException $e) {
        $description = FailureDescription::from($e);
    }

    expect($description)->toBe('QueryException: SQLSTATE HY000')
        ->and($description)->not->toContain('Connection:')
        ->and($description)->not->toContain('SQL:');
});

test('the failure description masks personal numbers and e-mail addresses and is shortened', function () {
    $description = FailureDescription::from(new RuntimeException(
        "Fo02: person 000000000 with\naddress test@example.com refused ".str_repeat('x', 400)
    ));

    expect($description)->toStartWith('RuntimeException: Fo02: person [nummer] with address [e-mail] refused')
        ->and($description)->not->toContain('000000000')
        ->and($description)->not->toContain('example.com')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(FailureDescription::MAX_LENGTH);
});

test('the failure description leaves the numbers in a url alone', function () {
    $description = FailureDescription::from(new ConnectionException(
        'cURL error 28 for https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000011'
    ));

    expect($description)->toContain('00000000-0000-0000-0000-000000000011');
});

test('the link to the failed job uses the path of the job monitor from the config', function () {
    $notification = Notification::factory()->failed()->create();

    expect($notification->failedJobUrl())
        ->toBe(url(config('horizon.path').'/failed/'.$notification->failed_job_id))
        ->and(Notification::factory()->create()->failedJobUrl())->toBeNull();
});

test('the status of a notification is processed, failed or not processed yet', function () {
    expect(Notification::factory()->processed()->make()->status())->toBe(NotificationStatus::Processed)
        ->and(Notification::factory()->failed()->make()->status())->toBe(NotificationStatus::Failed)
        ->and(Notification::factory()->make()->status())->toBe(NotificationStatus::Pending);
});

test('rolling back the failure columns migration removes them', function () {
    $migration = require database_path('migrations/2026_10_23_120200_add_failure_columns_to_notifications_table.php');

    expect(Schema::hasColumns('notifications', ['failed_at', 'failure', 'failed_job_id']))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('notifications', 'failed_at'))->toBeFalse()
        ->and(Schema::hasColumn('notifications', 'failed_job_id'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('notifications', ['failed_at', 'failure', 'failed_job_id']))->toBeTrue();
});
