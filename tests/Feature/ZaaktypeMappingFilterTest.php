<?php

use App\Jobs\CheckIncomingNotification;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\ZaaktypeMapping;
use App\Services\BatchingService;
use App\ValueObjects\OpenNotification;
use Illuminate\Support\Facades\Log;
use Woweb\Openzaak\Openzaak;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeZaakNotification(string $zaaktypeUrl = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/aaa-111'): array
{
    return [
        'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'url' => 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        'identificatie' => 'ZAAK-2024-001',
        'zaaktype' => $zaaktypeUrl,
        'omschrijving' => 'Test zaak',
        'startdatum' => '2024-01-15',
        'registratiedatum' => '2024-01-15T10:30:00Z',
        'einddatum' => null,
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'bronorganisatie' => null,
        'zaakgeometrie' => null,
    ];
}

test('it ignores notification when zaaktype has no active mapping', function () {
    Log::spy();

    $openNotification = new OpenNotification(
        actie: 'create',
        kanaal: 'zaken',
        resource: 'zaak',
        hoofdObject: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        resourceUrl: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        aanmaakdatum: '2024-01-01T10:00:00Z',
    );

    $openzaakMock = Mockery::mock(Openzaak::class);
    $openzaakMock->shouldReceive('get')
        ->once()
        ->andReturn(collect(makeZaakNotification()));

    $batchingServiceSpy = Mockery::spy(BatchingService::class);

    $job = new CheckIncomingNotification($openNotification);
    $job->handle($openzaakMock, $batchingServiceSpy);

    $batchingServiceSpy->shouldNotHaveReceived('getOrCreateBatch');
    expect(Notification::count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->with('Zaaktype not active or unknown, ignoring notification', Mockery::any())
        ->once();
});

test('it ignores notification when zaaktype mapping exists but is inactive', function () {
    Log::spy();

    $zaaktypeUrl = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/aaa-111';

    ZaaktypeMapping::factory()->create([
        'zaaktype_url' => $zaaktypeUrl,
        'is_active' => false,
        'corsa_zaaktype_code' => 'CODE_01',
    ]);

    $openNotification = new OpenNotification(
        actie: 'create',
        kanaal: 'zaken',
        resource: 'zaak',
        hoofdObject: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        resourceUrl: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        aanmaakdatum: '2024-01-01T10:00:00Z',
    );

    $openzaakMock = Mockery::mock(Openzaak::class);
    $openzaakMock->shouldReceive('get')
        ->once()
        ->andReturn(collect(makeZaakNotification($zaaktypeUrl)));

    $batchingServiceSpy = Mockery::spy(BatchingService::class);

    $job = new CheckIncomingNotification($openNotification);
    $job->handle($openzaakMock, $batchingServiceSpy);

    $batchingServiceSpy->shouldNotHaveReceived('getOrCreateBatch');
    expect(Notification::count())->toBe(0);
});

test('it processes notification when zaaktype has an active mapping', function () {
    Log::spy();

    $zaaktypeUrl = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/aaa-111';

    ZaaktypeMapping::factory()->active()->create([
        'zaaktype_url' => $zaaktypeUrl,
    ]);

    $openNotification = new OpenNotification(
        actie: 'create',
        kanaal: 'zaken',
        resource: 'zaak',
        hoofdObject: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        resourceUrl: 'https://openzaak.example.com/zaken/api/v1/zaken/123',
        aanmaakdatum: '2024-01-01T10:00:00Z',
    );

    $openzaakMock = Mockery::mock(Openzaak::class);
    $openzaakMock->shouldReceive('get')
        ->once()
        ->andReturn(collect(makeZaakNotification($zaaktypeUrl)));

    $fakeBatch = Batch::create(['zaak_identificatie' => 'ZAAK-2024-001']);

    $batchingServiceMock = Mockery::mock(BatchingService::class);
    $batchingServiceMock->shouldReceive('getOrCreateBatch')
        ->once()
        ->with('ZAAK-2024-001')
        ->andReturn($fakeBatch);

    $batchingServiceMock->shouldReceive('addNotificationToBatch')->once();

    $job = new CheckIncomingNotification($openNotification);
    $job->handle($openzaakMock, $batchingServiceMock);

    expect(Notification::count())->toBe(1);
});
