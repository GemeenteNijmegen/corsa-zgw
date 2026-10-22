<?php

use App\Jobs\Notifications\IngestNotification;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\ZaaktypeMapping;
use App\Services\BatchingService;
use App\Services\Zgw\ZgwReader;
use App\ValueObjects\OpenNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Http::preventStrayRequests());

test('it skips processing when kanaal is not zaken', function () {
    Http::fake();

    $openNotification = new OpenNotification(
        actie: 'create',
        kanaal: 'documenten',
        resource: 'document',
        hoofdObject: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        resourceUrl: 'https://openzaak.example.com/documenten/api/v1/enkelvoudiginformatieobjecten/456',
        aanmaakdatum: '2024-01-01T10:00:00Z',
    );

    $batchingServiceSpy = Mockery::spy(BatchingService::class);

    $job = new IngestNotification($openNotification);
    $job->handle(app(ZgwReader::class), $batchingServiceSpy);

    Http::assertNothingSent();
    $batchingServiceSpy->shouldNotHaveReceived('getOrCreateBatch');

    expect(Notification::count())->toBe(0);
});

test('it processes when kanaal is zaken', function () {
    Log::spy();

    $openNotification = new OpenNotification(
        actie: 'create',
        kanaal: 'zaken',
        resource: 'zaak',
        hoofdObject: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        resourceUrl: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        aanmaakdatum: '2024-01-01T10:00:00Z',
    );

    $zaakData = [
        'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'url' => 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        'identificatie' => 'ZAAK-2024-001',
        'zaaktype' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/123',
        'omschrijving' => 'Test zaak',
        'startdatum' => '2024-01-15',
        'registratiedatum' => '2024-01-15T10:30:00Z',
        'einddatum' => null,
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'bronorganisatie' => null,
        'zaakgeometrie' => null,
    ];

    Http::fake([
        $openNotification->hoofdObject => Http::response($zaakData),
    ]);

    // Create an active zaaktype mapping so the notification is not filtered
    ZaaktypeMapping::factory()->active()->create([
        'zaaktype_url' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/123',
    ]);

    // Don't set ID, let Laravel generate it
    $fakeBatch = Batch::create([
        'zaak_identificatie' => 'ZAAK-2024-001',
    ]);

    $batchingServiceMock = Mockery::mock(BatchingService::class);
    $batchingServiceMock->shouldReceive('getOrCreateBatch')
        ->once()
        ->with('ZAAK-2024-001')
        ->andReturn($fakeBatch);

    $batchingServiceMock->shouldReceive('addNotificationToBatch')
        ->once()
        ->withArgs(function ($notification, $batch) use ($fakeBatch) {
            return $notification instanceof Notification
                && $batch->id === $fakeBatch->id;
        });

    $job = new IngestNotification($openNotification);
    $job->handle(app(ZgwReader::class), $batchingServiceMock);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === $openNotification->hoofdObject);

    expect(Notification::count())->toBe(1);

    $notification = Notification::first();
    expect($notification->zaak_identificatie)->toBe('ZAAK-2024-001')
        ->and($notification->processed)->toBeFalse();
});

test('it fails without a retry and sends no request when the hoofdObject is not on a configured ZGW host', function () {
    Log::spy();
    Http::fake();

    $openNotification = new OpenNotification(
        actie: 'create',
        kanaal: 'zaken',
        resource: 'zaak',
        hoofdObject: 'https://elsewhere.example.com/zaken/api/v1/zaken/123',
        resourceUrl: 'https://elsewhere.example.com/zaken/api/v1/zaken/123',
        aanmaakdatum: '2024-01-01T10:00:00Z',
    );

    $batchingServiceSpy = Mockery::spy(BatchingService::class);

    $job = (new IngestNotification($openNotification))->withFakeQueueInteractions();
    $job->handle(app(ZgwReader::class), $batchingServiceSpy);

    Http::assertNothingSent();
    $job->assertFailed();
    $batchingServiceSpy->shouldNotHaveReceived('getOrCreateBatch');
    expect(Notification::count())->toBe(0);

    // The log names the refused origin, not the full URL.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Notification refers to a host that is not a configured ZGW host'
            && ! str_contains(json_encode($context), '/zaken/api/v1/zaken/123'))
        ->once();
});
