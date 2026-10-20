<?php

use App\Jobs\Notifications\HandleNotification;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\CorsaZaakdmsService;
use Illuminate\Support\Facades\Log;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Log::spy());

function makeRoutedNotification(string $actie, string $resource): Notification
{
    $batch = Batch::create(['zaak_identificatie' => 'ZAAK-2026-0001', 'status' => 'processing']);

    return Notification::create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => 'ZAAK-2026-0001',
        'notification' => [
            'actie' => $actie,
            'kanaal' => 'zaken',
            'resource' => $resource,
            'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001',
            'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/'.$resource.'/00000000-0000-0000-0000-000000000002',
            'aanmaakdatum' => '2026-01-15T10:00:00Z',
        ],
        'processed' => false,
    ]);
}

function runHandleNotification(Notification $notification): void
{
    app()->call([new HandleNotification($notification), 'handle']);
}

test('it routes each supported create notification to the matching service method', function (string $resource, string $method) {
    $notification = makeRoutedNotification('create', $resource);

    $service = $this->mock(CorsaZaakdmsService::class);
    $service->shouldReceive($method)
        ->once()
        ->withArgs(fn (Notification $received) => $received->is($notification));
    foreach (['processZaakAangemaakt', 'processZaakPartialUpdate', 'processDocumentAangemaakt', 'processResultaatAangemaakt'] as $other) {
        if ($other !== $method) {
            $service->shouldNotReceive($other);
        }
    }

    runHandleNotification($notification);

    $fresh = $notification->fresh();
    expect($fresh->processed)->toBeTrue()
        ->and($fresh->processed_at)->not->toBeNull();
})->with([
    'zaak' => ['zaak', 'processZaakAangemaakt'],
    'status' => ['status', 'processZaakPartialUpdate'],
    'zaakinformatieobject' => ['zaakinformatieobject', 'processDocumentAangemaakt'],
    'resultaat' => ['resultaat', 'processResultaatAangemaakt'],
]);

test('known bug: an unsupported action/resource combination is only logged and marked as processed', function (string $actie, string $resource) {
    $notification = makeRoutedNotification($actie, $resource);

    $service = $this->mock(CorsaZaakdmsService::class);
    $service->shouldNotReceive('processZaakAangemaakt', 'processZaakPartialUpdate', 'processDocumentAangemaakt', 'processResultaatAangemaakt');

    runHandleNotification($notification);

    Log::shouldHaveReceived('warning')
        ->with('Unknown notification action/resource combination', Mockery::on(
            fn (array $context) => $context['actie'] === $actie && $context['resource'] === $resource
        ))
        ->once();

    // Current behaviour: the notification is silently treated as done, so it is never retried.
    expect($notification->fresh()->processed)->toBeTrue();
})->with([
    'update:zaak' => ['update', 'zaak'],
    'delete:zaakinformatieobject' => ['delete', 'zaakinformatieobject'],
]);

test('it does not mark the notification as processed and rethrows when the service fails', function () {
    $notification = makeRoutedNotification('create', 'zaak');

    $this->mock(CorsaZaakdmsService::class)
        ->shouldReceive('processZaakAangemaakt')
        ->once()
        ->andThrow(new RuntimeException('Downstream system unavailable'));

    expect(fn () => runHandleNotification($notification))
        ->toThrow(RuntimeException::class, 'Downstream system unavailable');

    Log::shouldHaveReceived('error')
        ->with('Error processing notification', Mockery::on(
            fn (array $context) => $context['error'] === 'Downstream system unavailable'
        ))
        ->once();

    $fresh = $notification->fresh();
    expect($fresh->processed)->toBeFalse()
        ->and($fresh->processed_at)->toBeNull();
});

test('it exposes a display name and tags based on the notification', function () {
    $notification = makeRoutedNotification('create', 'status');
    $job = new HandleNotification($notification);

    expect($job->displayName())->toBe('Handle create:status')
        ->and($job->tags())->toBe(['zaak:ZAAK-2026-0001', 'action:create:status']);
});
