<?php

use App\Jobs\Notifications\HandleNotification;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\CorsaZaakdmsService;
use App\Services\Zgw\ZgwReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Log::spy();
    Http::preventStrayRequests();
});

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

/**
 * Fake the resultaat, resultaattype and zaak responses of the ZGW APIs.
 */
function fakeResultaatApis(?string $einddatum): void
{
    $zaakUrl = 'https://openzaak.example.com/zaken/api/v1/zaken/abc-123';
    $resultaatUrl = 'https://openzaak.example.com/zaken/api/v1/resultaten/def-456';
    $resultaattypeUrl = 'https://openzaak.example.com/catalogi/api/v1/resultaattypen/xyz-789';

    Http::fake([
        $resultaatUrl => Http::response(['resultaattype' => $resultaattypeUrl, 'zaak' => $zaakUrl]),
        $resultaattypeUrl => Http::response(['omschrijving' => 'Zaak afgehandeld']),
        $zaakUrl.'*' => Http::response([
            'uuid' => 'abc-123',
            'url' => $zaakUrl,
            'identificatie' => 'ZAAK-2024-001',
            'zaaktype' => 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/111',
            'omschrijving' => 'Test zaak',
            'startdatum' => '2024-01-15',
            'registratiedatum' => '2024-01-15T10:30:00Z',
            'einddatum' => $einddatum,
            'einddatumGepland' => null,
            'uiterlijkeEinddatumAfdoening' => null,
            'bronorganisatie' => null,
            'zaakgeometrie' => null,
        ]),
    ]);
}

function resultaatService($zaakdmsMock): CorsaZaakdmsService
{
    return new CorsaZaakdmsService(app(ZgwReader::class), $zaakdmsMock);
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

    expect($notification->fresh()->processed)->toBeTrue()
        ->and($notification->fresh()->processed_at)->not->toBeNull();
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

    fakeResultaatApis('2026-03-11');

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

    resultaatService($zaakdmsMock)->processResultaatAangemaakt($notification);

    Http::assertSentCount(3);
});

test('processResultaatAangemaakt uses today as einddatum fallback when zaak has no einddatum', function () {
    $notification = makeResultaatNotification();

    fakeResultaatApis(null);

    $zaakdmsMock = Mockery::mock(\Woweb\Zaakdms\Zaakdms::class);
    $zaakdmsMock->shouldReceive('geefZaakDetails')
        ->andReturn(['response' => (object) ['zknidentificatie' => 'ZAAK-2024-001']]);
    $zaakdmsMock->shouldReceive('updateZaak')
        ->once()
        ->withArgs(fn ($options) => $options['einddatum'] === \Carbon\Carbon::today()->format('Ymd'))
        ->andReturn('stuf-ref-001');

    resultaatService($zaakdmsMock)->processResultaatAangemaakt($notification);
});

test('processResultaatAangemaakt throws when zaak does not exist in Corsa', function () {
    $notification = makeResultaatNotification();

    fakeResultaatApis('2026-03-11');

    $zaakdmsMock = Mockery::mock(\Woweb\Zaakdms\Zaakdms::class);
    $zaakdmsMock->shouldReceive('geefZaakDetails')
        ->andThrow(new Exception('Zaak not found in Corsa'));

    expect(fn () => resultaatService($zaakdmsMock)->processResultaatAangemaakt($notification))
        ->toThrow(RuntimeException::class, 'Missing zaak in Corsa for resultaat update');
});

test('processResultaatAangemaakt throws when resourceUrl is missing', function () {
    Http::fake();

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

    $zaakdmsMock = Mockery::mock(\Woweb\Zaakdms\Zaakdms::class);

    expect(fn () => resultaatService($zaakdmsMock)->processResultaatAangemaakt($notification))
        ->toThrow(RuntimeException::class, 'Missing resultaat url for resultaat notification');

    Http::assertNothingSent();
});
