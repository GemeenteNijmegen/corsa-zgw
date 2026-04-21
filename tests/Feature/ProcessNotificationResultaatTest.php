<?php

use App\Jobs\Notifications\HandleNotification;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\CorsaZaakdmsService;
use Illuminate\Support\Facades\Log;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Log::spy());

// ─── helpers ──────────────────────────────────────────────────────────────────

function makeResultaatNotification(array $overrides = []): Notification
{
    $batch = Batch::create(['zaak_identificatie' => 'ZAAK-2024-001', 'status' => 'pending']);

    return Notification::create(array_merge([
        'batch_id' => $batch->id,
        'zaak_identificatie' => 'ZAAK-2024-001',
        'notification' => [
            'actie' => 'create',
            'kanaal' => 'zaken',
            'resource' => 'resultaat',
            'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/abc-123',
            'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/resultaten/def-456',
            'aanmaakdatum' => '2026-03-11T10:20:14.888Z',
        ],
        'processed' => false,
    ], $overrides));
}

// ─── HandleNotification routing ─────────────────────────────────────────────

test('create:resultaat routes to processResultaatAangemaakt', function () {
    $notification = makeResultaatNotification();

    $serviceMock = Mockery::mock(CorsaZaakdmsService::class);
    $serviceMock->shouldReceive('processResultaatAangemaakt')
        ->once()
        ->with($notification);

    $job = new HandleNotification($notification);
    $job->handle($serviceMock);

    expect($notification->fresh()->processed)->toBeTrue();
});

test('create:resultaat marks notification as processed on success', function () {
    $notification = makeResultaatNotification();

    $serviceMock = Mockery::mock(CorsaZaakdmsService::class);
    $serviceMock->shouldReceive('processResultaatAangemaakt')->once();

    $job = new HandleNotification($notification);
    $job->handle($serviceMock);

    expect($notification->fresh()->processed)->toBeTrue();
});

test('create:resultaat rethrows exception from service', function () {
    $notification = makeResultaatNotification();

    $serviceMock = Mockery::mock(CorsaZaakdmsService::class);
    $serviceMock->shouldReceive('processResultaatAangemaakt')
        ->once()
        ->andThrow(new RuntimeException('Corsa connection failed'));

    $job = new HandleNotification($notification);

    expect(fn () => $job->handle($serviceMock))
        ->toThrow(RuntimeException::class, 'Corsa connection failed');

    expect($notification->fresh()->processed)->toBeFalse();
});

// ─── CorsaZaakdmsService::processResultaatAangemaakt ─────────────────────────

test('processResultaatAangemaakt calls updateZaak with correct options', function () {
    $notification = makeResultaatNotification();

    $zaakUrl = 'https://openzaak.example.com/zaken/api/v1/zaken/abc-123';
    $resultaatUrl = 'https://openzaak.example.com/zaken/api/v1/resultaten/def-456';
    $resultaattypeUrl = 'https://openzaak.example.com/catalogi/api/v1/resultaattypen/xyz-789';

    $resultaatData = ['resultaattype' => $resultaattypeUrl, 'zaak' => $zaakUrl];
    $resultaattypeData = ['omschrijving' => 'Zaak afgehandeld'];
    $zaakData = [
        'uuid' => 'abc-123',
        'url' => $zaakUrl,
        'identificatie' => 'ZAAK-2024-001',
        'zaaktype' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/111',
        'omschrijving' => 'Test zaak',
        'startdatum' => '2024-01-15',
        'registratiedatum' => '2024-01-15T10:30:00Z',
        'einddatum' => '2026-03-11',
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'bronorganisatie' => null,
        'zaakgeometrie' => null,
    ];

    $openzaakMock = Mockery::mock(\Woweb\Openzaak\Openzaak::class);
    $openzaakMock->shouldReceive('get')
        ->with($resultaatUrl)
        ->andReturn(collect($resultaatData));
    $openzaakMock->shouldReceive('get')
        ->with($resultaattypeUrl)
        ->andReturn(collect($resultaattypeData));
    $openzaakMock->shouldReceive('get')
        ->withArgs(fn ($url) => str_starts_with($url, $zaakUrl))
        ->andReturn(collect($zaakData));

    $zaakdmsMock = Mockery::mock(\Woweb\Zaakdms\Zaakdms::class);
    $zaakdmsMock->shouldReceive('geefZaakDetails')
        ->once()
        ->with('ZAAK-2024-001')
        ->andReturn(['response' => (object) ['zknidentificatie' => 'ZAAK-2024-001']]);
    $zaakdmsMock->shouldReceive('updateZaak')
        ->once()
        ->withArgs(fn ($options) => $options['identificatie'] === 'ZAAK-2024-001'
            && $options['resultaat'] === 'Zaak afgehandeld'
            && $options['einddatum'] === '20260311')
        ->andReturn('stuf-ref-001');

    $service = new \App\Services\CorsaZaakdmsService($openzaakMock, $zaakdmsMock);
    $service->processResultaatAangemaakt($notification);
});

test('processResultaatAangemaakt uses today as einddatum fallback when zaak has no einddatum', function () {
    $notification = makeResultaatNotification();

    $zaakUrl = 'https://openzaak.example.com/zaken/api/v1/zaken/abc-123';
    $resultaatUrl = 'https://openzaak.example.com/zaken/api/v1/resultaten/def-456';
    $resultaattypeUrl = 'https://openzaak.example.com/catalogi/api/v1/resultaattypen/xyz-789';

    $resultaatData = ['resultaattype' => $resultaattypeUrl, 'zaak' => $zaakUrl];
    $resultaattypeData = ['omschrijving' => 'Zaak afgehandeld'];
    $zaakData = [
        'uuid' => 'abc-123',
        'url' => $zaakUrl,
        'identificatie' => 'ZAAK-2024-001',
        'zaaktype' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/111',
        'omschrijving' => 'Test zaak',
        'startdatum' => '2024-01-15',
        'registratiedatum' => '2024-01-15T10:30:00Z',
        'einddatum' => null,
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'bronorganisatie' => null,
        'zaakgeometrie' => null,
    ];

    $openzaakMock = Mockery::mock(\Woweb\Openzaak\Openzaak::class);
    $openzaakMock->shouldReceive('get')->with($resultaatUrl)->andReturn(collect($resultaatData));
    $openzaakMock->shouldReceive('get')->with($resultaattypeUrl)->andReturn(collect($resultaattypeData));
    $openzaakMock->shouldReceive('get')
        ->withArgs(fn ($url) => str_starts_with($url, $zaakUrl))
        ->andReturn(collect($zaakData));

    $zaakdmsMock = Mockery::mock(\Woweb\Zaakdms\Zaakdms::class);
    $zaakdmsMock->shouldReceive('geefZaakDetails')
        ->andReturn(['response' => (object) ['zknidentificatie' => 'ZAAK-2024-001']]);
    $zaakdmsMock->shouldReceive('updateZaak')
        ->once()
        ->withArgs(fn ($options) => $options['einddatum'] === \Carbon\Carbon::today()->format('Ymd'))
        ->andReturn('stuf-ref-001');

    $service = new \App\Services\CorsaZaakdmsService($openzaakMock, $zaakdmsMock);
    $service->processResultaatAangemaakt($notification);
});

test('processResultaatAangemaakt throws when zaak does not exist in Corsa', function () {
    $notification = makeResultaatNotification();

    $zaakUrl = 'https://openzaak.example.com/zaken/api/v1/zaken/abc-123';
    $resultaatUrl = 'https://openzaak.example.com/zaken/api/v1/resultaten/def-456';
    $resultaattypeUrl = 'https://openzaak.example.com/catalogi/api/v1/resultaattypen/xyz-789';

    $resultaatData = ['resultaattype' => $resultaattypeUrl, 'zaak' => $zaakUrl];
    $resultaattypeData = ['omschrijving' => 'Zaak afgehandeld'];
    $zaakData = [
        'uuid' => 'abc-123',
        'url' => $zaakUrl,
        'identificatie' => 'ZAAK-2024-001',
        'zaaktype' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/111',
        'omschrijving' => 'Test zaak',
        'startdatum' => '2024-01-15',
        'registratiedatum' => '2024-01-15T10:30:00Z',
        'einddatum' => '2026-03-11',
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'bronorganisatie' => null,
        'zaakgeometrie' => null,
    ];

    $openzaakMock = Mockery::mock(\Woweb\Openzaak\Openzaak::class);
    $openzaakMock->shouldReceive('get')->with($resultaatUrl)->andReturn(collect($resultaatData));
    $openzaakMock->shouldReceive('get')->with($resultaattypeUrl)->andReturn(collect($resultaattypeData));
    $openzaakMock->shouldReceive('get')
        ->withArgs(fn ($url) => str_starts_with($url, $zaakUrl))
        ->andReturn(collect($zaakData));

    $zaakdmsMock = Mockery::mock(\Woweb\Zaakdms\Zaakdms::class);
    $zaakdmsMock->shouldReceive('geefZaakDetails')
        ->andThrow(new Exception('Zaak not found in Corsa'));

    $service = new \App\Services\CorsaZaakdmsService($openzaakMock, $zaakdmsMock);

    expect(fn () => $service->processResultaatAangemaakt($notification))
        ->toThrow(RuntimeException::class, 'Missing zaak in Corsa for resultaat update');
});

test('processResultaatAangemaakt throws when resourceUrl is missing', function () {
    $batch = Batch::create(['zaak_identificatie' => 'ZAAK-2024-001', 'status' => 'pending']);
    $notification = Notification::create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => 'ZAAK-2024-001',
        'notification' => [
            'actie' => 'create',
            'resource' => 'resultaat',
            'hoofdObject' => 'https://openzaak.example.com/zaken/api/v1/zaken/abc-123',
        ],
        'processed' => false,
    ]);

    $openzaakMock = Mockery::mock(\Woweb\Openzaak\Openzaak::class);
    $zaakdmsMock = Mockery::mock(\Woweb\Zaakdms\Zaakdms::class);

    $service = new \App\Services\CorsaZaakdmsService($openzaakMock, $zaakdmsMock);

    expect(fn () => $service->processResultaatAangemaakt($notification))
        ->toThrow(RuntimeException::class, 'Missing resultaat url for resultaat notification');
});
