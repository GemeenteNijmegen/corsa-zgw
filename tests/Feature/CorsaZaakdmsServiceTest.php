<?php

use App\Models\Batch;
use App\Models\Notification;
use App\Models\ZaaktypeMapping;
use App\Services\CorsaZaakdmsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Woweb\Zaakdms\Exceptions\InvalidZaakdmsArgumentsException;
use Woweb\Zaakdms\Zaakdms;
use Woweb\Zgw\Exceptions\ApiRequestException;
use Woweb\Zgw\Exceptions\DisallowedHostException;

/*
 * Characterization tests for CorsaZaakdmsService.
 *
 * The ZGW APIs are faked at the HTTP level, so these tests describe what the
 * application requests and what it sends on to the document management system,
 * independent of the ZGW client library in between. The ZGW host and client
 * credentials come from the test environment in phpunit.xml. The document
 * management system client is mocked at its public method boundary.
 */

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

const ZGW_ZAAK_URL = 'https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000001';
const ZGW_ZAAKTYPE_URL = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/00000000-0000-0000-0000-000000000101';
const ZGW_STATUS_URL = 'https://openzaak.example.com/zaken/api/v1/statussen/00000000-0000-0000-0000-000000000201';
const ZGW_STATUSTYPE_URL = 'https://openzaak.example.com/catalogi/api/v1/statustypen/00000000-0000-0000-0000-000000000301';
const ZGW_ZIO_URL = 'https://openzaak.example.com/zaken/api/v1/zaakinformatieobjecten/00000000-0000-0000-0000-000000000401';
const ZGW_EIO_URL = 'https://openzaak.example.com/documenten/api/v1/enkelvoudiginformatieobjecten/00000000-0000-0000-0000-000000000501';
const ZGW_IOT_URL = 'https://openzaak.example.com/catalogi/api/v1/informatieobjecttypen/00000000-0000-0000-0000-000000000601';
const ZGW_ZAAK_IDENTIFICATIE = 'ZAAK-2026-0001';

beforeEach(function () {
    Log::spy();
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->zaakdms = $this->mock(Zaakdms::class);
});

// ─── fixtures ────────────────────────────────────────────────────────────────

function corsaService(): CorsaZaakdmsService
{
    return app(CorsaZaakdmsService::class);
}

function makeServiceNotification(string $resource, ?string $resourceUrl, ?string $hoofdObject = ZGW_ZAAK_URL): Notification
{
    $batch = Batch::create(['zaak_identificatie' => ZGW_ZAAK_IDENTIFICATIE, 'status' => 'processing']);

    return Notification::create([
        'batch_id' => $batch->id,
        'zaak_identificatie' => ZGW_ZAAK_IDENTIFICATIE,
        'notification' => [
            'actie' => 'create',
            'kanaal' => 'zaken',
            'resource' => $resource,
            'hoofdObject' => $hoofdObject,
            'resourceUrl' => $resourceUrl,
            'aanmaakdatum' => '2026-01-15T10:00:00Z',
        ],
        'processed' => false,
    ]);
}

function zgwRol(string $betrokkeneType, array $betrokkeneIdentificatie, string $omschrijvingGeneriek = 'initiator'): array
{
    return [
        'url' => 'https://openzaak.example.com/zaken/api/v1/rollen/00000000-0000-0000-0000-000000000701',
        'uuid' => '00000000-0000-0000-0000-000000000701',
        'zaak' => ZGW_ZAAK_URL,
        'betrokkene' => '',
        'betrokkeneType' => $betrokkeneType,
        'roltype' => 'https://openzaak.example.com/catalogi/api/v1/roltypen/00000000-0000-0000-0000-000000000801',
        'omschrijving' => 'Aanvrager',
        'omschrijvingGeneriek' => $omschrijvingGeneriek,
        'roltoelichting' => 'Aanvrager',
        'registratiedatum' => '2026-01-15T10:00:00Z',
        'indicatieMachtiging' => '',
        'contactpersoonRol' => [
            'emailadres' => '',
            'functie' => '',
            'telefoonnummer' => '',
            'naam' => '',
        ],
        'betrokkeneIdentificatie' => $betrokkeneIdentificatie,
    ];
}

function natuurlijkPersoonRol(array $overrides = []): array
{
    return zgwRol('natuurlijk_persoon', array_merge([
        'inpBsn' => '000000000',
        'anpIdentificatie' => '',
        'inpA_nummer' => '',
        'geslachtsnaam' => 'Testpersoon',
        'voorvoegselGeslachtsnaam' => '',
        'voorletters' => 'T',
        'voornamen' => 'Test',
        'geslachtsaanduiding' => '',
        'geboortedatum' => '',
    ], $overrides));
}

function nietNatuurlijkPersoonRol(): array
{
    return zgwRol('niet_natuurlijk_persoon', [
        'innNnpId' => '000000000',
        'annIdentificatie' => '',
        'statutaireNaam' => 'Testorganisatie',
        'innRechtsvorm' => '',
        'bezoekadres' => '',
    ]);
}

function zgwZaak(array $rollen = [], array $overrides = []): array
{
    return array_merge([
        'url' => ZGW_ZAAK_URL,
        'uuid' => '00000000-0000-0000-0000-000000000001',
        'identificatie' => ZGW_ZAAK_IDENTIFICATIE,
        'bronorganisatie' => '000000000',
        'omschrijving' => 'Testzaak',
        'toelichting' => '',
        'zaaktype' => ZGW_ZAAKTYPE_URL,
        'registratiedatum' => '2026-01-15',
        'verantwoordelijkeOrganisatie' => '000000000',
        'startdatum' => '2026-01-14',
        'einddatum' => null,
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'zaakgeometrie' => null,
        'status' => null,
        '_expand' => ['rollen' => $rollen],
    ], $overrides);
}

function zgwZaaktype(): array
{
    return [
        'url' => ZGW_ZAAKTYPE_URL,
        'identificatie' => 'ZT-0001',
        'omschrijving' => 'Testzaaktype',
        'omschrijvingGeneriek' => '',
    ];
}

function activeZaaktypeMapping(): ZaaktypeMapping
{
    return ZaaktypeMapping::factory()->create([
        'zaaktype_url' => ZGW_ZAAKTYPE_URL,
        'corsa_zaaktype_code' => 'CODE_01',
        'is_active' => true,
    ]);
}

function fakeZaakAndZaaktype(array $zaak): void
{
    Http::fake([
        ZGW_ZAAK_URL.'*' => Http::response($zaak),
        ZGW_ZAAKTYPE_URL => Http::response(zgwZaaktype()),
    ]);
}

function corsaZaakDetails(string $identificatie = ZGW_ZAAK_IDENTIFICATIE): array
{
    return ['dms_id' => null, 'response' => (object) ['zknidentificatie' => $identificatie]];
}

/**
 * Expect a single call to the given zaakdms method and capture its options.
 */
function captureZaakdmsCall($zaakdms, string $method, ?array &$captured, string $return = 'REF-0001'): void
{
    $zaakdms->shouldReceive($method)
        ->once()
        ->withArgs(function (array $options) use (&$captured) {
            $captured = $options;

            return true;
        })
        ->andReturn($return);
}

function expectedCreateOptions(array $initiator): array
{
    return [
        'identificatie' => ZGW_ZAAK_IDENTIFICATIE,
        'omschrijving' => 'Testzaak',
        'startdatum' => '20260114',
        'registratiedatum' => '20260115',
        'zaakniveau' => 1,
        'zaaktype' => [
            'omschrijving' => 'Testzaaktype',
            'code' => 'CODE_01',
        ],
        'initiator' => $initiator,
    ];
}

// ─── processZaakAangemaakt ──────────────────────────────────────────────────

test('processZaakAangemaakt creates the zaak with a natural person as initiator', function () {
    activeZaaktypeMapping();
    fakeZaakAndZaaktype(zgwZaak([natuurlijkPersoonRol()]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->once()->with(ZGW_ZAAK_IDENTIFICATIE)->andThrow(new Exception('not found'));
    captureZaakdmsCall($this->zaakdms, 'creeerZaak', $options);

    corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL));

    expect($options)->toBe(expectedCreateOptions([
        'type' => 'natuurlijk_persoon',
        'natuurlijk_persoon' => [
            'bsn' => '000000000',
            'identificatie' => '',
            'geslachtsnaam' => 'Testpersoon',
        ],
    ]));

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && urldecode($request->url()) === ZGW_ZAAK_URL.'?expand=rollen,status');
    Http::assertSent(fn (Request $request) => $request->url() === ZGW_ZAAKTYPE_URL);
    Http::assertSentCount(2);
});

test('processZaakAangemaakt creates the zaak with a non-natural person as initiator', function () {
    activeZaaktypeMapping();
    fakeZaakAndZaaktype(zgwZaak([nietNatuurlijkPersoonRol()]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andThrow(new Exception('not found'));
    captureZaakdmsCall($this->zaakdms, 'creeerZaak', $options);

    corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL));

    expect($options)->toBe(expectedCreateOptions([
        'type' => 'niet_natuurlijk_persoon',
        'niet_natuurlijk_persoon' => [
            'nnpId' => '000000000',
            'statutaireNaam' => 'Testorganisatie',
        ],
    ]));
});

test('processZaakAangemaakt uses onbekend when the initiator has an empty geslachtsnaam', function () {
    activeZaaktypeMapping();
    fakeZaakAndZaaktype(zgwZaak([natuurlijkPersoonRol(['geslachtsnaam' => ''])]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andThrow(new Exception('not found'));
    captureZaakdmsCall($this->zaakdms, 'creeerZaak', $options);

    corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL));

    expect($options['initiator']['natuurlijk_persoon']['geslachtsnaam'])->toBe('onbekend');
});

test('processZaakAangemaakt uses onbekend when the natural person initiator has no geslachtsnaam field', function () {
    activeZaaktypeMapping();
    $rol = natuurlijkPersoonRol();
    unset($rol['betrokkeneIdentificatie']['geslachtsnaam']);
    fakeZaakAndZaaktype(zgwZaak([$rol]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andThrow(new Exception('not found'));
    captureZaakdmsCall($this->zaakdms, 'creeerZaak', $options);

    corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL));

    expect($options['initiator']['natuurlijk_persoon']['geslachtsnaam'])->toBe('onbekend');
});

test('processZaakAangemaakt leaves out the initiator for an unsupported betrokkene type', function () {
    activeZaaktypeMapping();
    fakeZaakAndZaaktype(zgwZaak([zgwRol('medewerker', ['identificatie' => 'TEST-001'])]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andThrow(new Exception('not found'));
    captureZaakdmsCall($this->zaakdms, 'creeerZaak', $options);

    corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL));

    expect($options)->not->toHaveKey('initiator')
        ->and($options['zaaktype']['code'])->toBe('CODE_01');
});

test('processZaakAangemaakt skips creation when the zaak already exists in the document management system', function () {
    activeZaaktypeMapping();
    fakeZaakAndZaaktype(zgwZaak([natuurlijkPersoonRol()]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->once()->with(ZGW_ZAAK_IDENTIFICATIE)->andReturn(corsaZaakDetails());
    $this->zaakdms->shouldNotReceive('creeerZaak');

    corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL));
});

test('processZaakAangemaakt still creates the zaak when the existence check fails or returns another zaak', function (string $existenceCheck) {
    activeZaaktypeMapping();
    fakeZaakAndZaaktype(zgwZaak([natuurlijkPersoonRol()]));

    $expectation = $this->zaakdms->shouldReceive('geefZaakDetails')->once();
    $existenceCheck === 'throws'
        ? $expectation->andThrow(new Exception('not found'))
        : $expectation->andReturn(corsaZaakDetails('ZAAK-2026-9999'));
    captureZaakdmsCall($this->zaakdms, 'creeerZaak', $options);

    corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL));

    expect($options['identificatie'])->toBe(ZGW_ZAAK_IDENTIFICATIE);
})->with([
    'existence check throws' => ['throws'],
    'existence check returns another zaak' => ['other zaak'],
]);

test('processZaakAangemaakt throws when there is no active zaaktype mapping', function (bool $mappingExists) {
    if ($mappingExists) {
        ZaaktypeMapping::factory()->create([
            'zaaktype_url' => ZGW_ZAAKTYPE_URL,
            'corsa_zaaktype_code' => 'CODE_01',
            'is_active' => false,
        ]);
    }
    fakeZaakAndZaaktype(zgwZaak([natuurlijkPersoonRol()]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andThrow(new Exception('not found'));
    $this->zaakdms->shouldNotReceive('creeerZaak');

    expect(fn () => corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL)))
        ->toThrow(RuntimeException::class, 'Cannot resolve zaaktype code: no active mapping found for zaaktype url ['.ZGW_ZAAKTYPE_URL.']');
})->with([
    'no mapping' => [false],
    'inactive mapping' => [true],
]);

test('processZaakAangemaakt leaves out the initiator when the zaak has no initiator role', function () {
    activeZaaktypeMapping();
    fakeZaakAndZaaktype(zgwZaak([zgwRol('natuurlijk_persoon', ['inpBsn' => '000000000'], 'belanghebbende')]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andThrow(new Exception('not found'));
    captureZaakdmsCall($this->zaakdms, 'creeerZaak', $options);

    corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL));

    expect($options)->not->toHaveKey('initiator')
        ->and($options['identificatie'])->toBe(ZGW_ZAAK_IDENTIFICATIE);
});

test('processZaakAangemaakt fails with a validation error from the zaakdms client when the zaak has no initiator role', function () {
    // The zaakdms client requires an initiator, so the zaak is refused before the create request is sent.
    ZaaktypeMapping::factory()->create([
        'zaaktype_url' => ZGW_ZAAKTYPE_URL,
        'corsa_zaaktype_code' => '1001',
        'is_active' => true,
    ]);
    fakeZaakAndZaaktype(zgwZaak([zgwRol('natuurlijk_persoon', ['inpBsn' => '000000000'], 'belanghebbende')]));

    $this->partialMock(Zaakdms::class, fn ($mock) => $mock->shouldReceive('geefZaakDetails')->andThrow(new Exception('not found')));

    expect(fn () => corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', ZGW_ZAAK_URL)))
        ->toThrow(InvalidZaakdmsArgumentsException::class, 'initiator.type');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('processZaakAangemaakt throws before calling any API when the notification has no zaak url', function () {
    $this->zaakdms->shouldNotReceive('geefZaakDetails', 'creeerZaak');
    Http::fake();

    expect(fn () => corsaService()->processZaakAangemaakt(makeServiceNotification('zaak', null, null)))
        ->toThrow(RuntimeException::class, 'Missing zaak url for create notification');

    Http::assertNothingSent();
});

// ─── processZaakPartialUpdate ───────────────────────────────────────────────

test('processZaakPartialUpdate sends the status from an expanded statustype', function () {
    Http::fake([
        ZGW_STATUS_URL => Http::response([
            'url' => ZGW_STATUS_URL,
            'uuid' => '00000000-0000-0000-0000-000000000201',
            'zaak' => ZGW_ZAAK_URL,
            'statustype' => ZGW_STATUSTYPE_URL,
            '_expand' => ['statustype' => ['url' => ZGW_STATUSTYPE_URL, 'volgnummer' => 2, 'omschrijving' => 'In behandeling']],
        ]),
        ZGW_ZAAK_URL.'*' => Http::response(zgwZaak([natuurlijkPersoonRol()])),
    ]);

    $this->zaakdms->shouldReceive('geefZaakDetails')->once()->with(ZGW_ZAAK_IDENTIFICATIE)->andReturn(corsaZaakDetails());
    captureZaakdmsCall($this->zaakdms, 'actualiseerZaakstatus', $options);

    corsaService()->processZaakPartialUpdate(makeServiceNotification('status', ZGW_STATUS_URL));

    expect($options)->toBe([
        'identificatie' => ZGW_ZAAK_IDENTIFICATIE,
        'volgnummer' => 2,
        'omschrijving' => 'In behandeling',
        'gebruikerIdentificatie' => 'CorsaZaakdmsService',
    ]);

    Http::assertNotSent(fn (Request $request) => $request->url() === ZGW_STATUSTYPE_URL);
});

test('processZaakPartialUpdate fetches the statustype when it is not expanded', function () {
    Http::fake([
        ZGW_STATUS_URL => Http::response([
            'url' => ZGW_STATUS_URL,
            'uuid' => '00000000-0000-0000-0000-000000000201',
            'zaak' => ZGW_ZAAK_URL,
            'statustype' => ZGW_STATUSTYPE_URL,
        ]),
        ZGW_STATUSTYPE_URL => Http::response(['url' => ZGW_STATUSTYPE_URL, 'volgnummer' => 3, 'omschrijving' => 'Afgerond']),
        ZGW_ZAAK_URL.'*' => Http::response(zgwZaak([natuurlijkPersoonRol()])),
    ]);

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    captureZaakdmsCall($this->zaakdms, 'actualiseerZaakstatus', $options);

    corsaService()->processZaakPartialUpdate(makeServiceNotification('status', ZGW_STATUS_URL));

    expect($options['volgnummer'])->toBe(3)
        ->and($options['omschrijving'])->toBe('Afgerond');

    Http::assertSent(fn (Request $request) => $request->url() === ZGW_STATUSTYPE_URL);
});

test('processZaakPartialUpdate throws when the zaak does not exist in the document management system', function () {
    Http::fake([
        ZGW_STATUS_URL => Http::response([
            'url' => ZGW_STATUS_URL,
            'zaak' => ZGW_ZAAK_URL,
            'statustype' => ZGW_STATUSTYPE_URL,
            '_expand' => ['statustype' => ['volgnummer' => 2, 'omschrijving' => 'In behandeling']],
        ]),
        ZGW_ZAAK_URL.'*' => Http::response(zgwZaak([natuurlijkPersoonRol()])),
    ]);

    $this->zaakdms->shouldReceive('geefZaakDetails')->once()->andThrow(new Exception('not found'));
    $this->zaakdms->shouldNotReceive('actualiseerZaakstatus');

    expect(fn () => corsaService()->processZaakPartialUpdate(makeServiceNotification('status', ZGW_STATUS_URL)))
        ->toThrow(RuntimeException::class, 'Missing zaak in Corsa for partial update');
});

test('processZaakPartialUpdate throws before calling any API when the notification has no status url', function () {
    Http::fake();
    $this->zaakdms->shouldNotReceive('actualiseerZaakstatus');

    expect(fn () => corsaService()->processZaakPartialUpdate(makeServiceNotification('status', null)))
        ->toThrow(RuntimeException::class, 'Missing status url for partial update');

    Http::assertNothingSent();
});

// ─── processDocumentAangemaakt ──────────────────────────────────────────────

function zgwDocument(array $overrides = []): array
{
    return array_merge([
        'url' => ZGW_EIO_URL,
        'identificatie' => 'DOC-2026-0001',
        'bronorganisatie' => '000000000',
        'creatiedatum' => '2026-01-15',
        'titel' => 'Aanvraagformulier',
        'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
        'auteur' => 'Testauteur',
        'status' => 'definitief',
        'formaat' => 'application/pdf',
        'taal' => 'nld',
        'bestandsnaam' => 'Aanvraagformulier.pdf',
        'inhoud' => ZGW_EIO_URL.'/download',
        'beschrijving' => 'Testdocument',
        'ontvangstdatum' => '2026-01-16T09:30:00Z',
        'verzenddatum' => null,
        'informatieobjecttype' => ZGW_IOT_URL,
    ], $overrides);
}

function fakeDocumentApis(array $document, string $content = '%PDF-1.4 test content'): void
{
    Http::fake([
        ZGW_ZIO_URL => Http::response([
            'url' => ZGW_ZIO_URL,
            'zaak' => ZGW_ZAAK_URL,
            'informatieobject' => ZGW_EIO_URL,
        ]),
        ZGW_EIO_URL.'/download' => Http::response($content, 200, ['Content-Type' => 'application/octet-stream']),
        ZGW_EIO_URL => Http::response($document),
        ZGW_IOT_URL => Http::response(['url' => ZGW_IOT_URL, 'omschrijving' => 'Bijlage']),
    ]);
}

/**
 * Capture the options of voegZaakdocumentToe together with the temp file
 * state at the moment of the upload.
 */
function captureDocumentUpload($zaakdms, ?array &$captured, ?string &$contentDuringUpload): void
{
    $zaakdms->shouldReceive('voegZaakdocumentToe')
        ->once()
        ->withArgs(function (array $options) use (&$captured, &$contentDuringUpload) {
            $captured = $options;
            $contentDuringUpload = Storage::disk('local')->exists($options['file'])
                ? Storage::disk('local')->get($options['file'])
                : null;

            return true;
        })
        ->andReturn('DOC-REF-0001');
}

test('processDocumentAangemaakt uploads the document through a temp file and removes it afterwards', function () {
    fakeDocumentApis(zgwDocument());
    $notification = makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL);

    $this->zaakdms->shouldReceive('geefZaakDetails')->once()->with(ZGW_ZAAK_IDENTIFICATIE)->andReturn(corsaZaakDetails());
    captureDocumentUpload($this->zaakdms, $options, $contentDuringUpload);

    corsaService()->processDocumentAangemaakt($notification);

    $expectedPath = 'zaakdms-temp/'.$notification->id.'/aanvraagformulier.pdf';

    expect($options)->toBe([
        'identificatie' => 'DOC-2026-0001',
        'documenttype' => 'Bijlage',
        'creatiedatum' => '20260115',
        'ontvangstdatum' => '20260116',
        'verzenddatum' => null,
        'status' => 'definitief',
        'titel' => 'Aanvraagformulier',
        'beschrijving' => 'Testdocument',
        'auteur' => 'Testauteur',
        'vertrouwelijkAanduiding' => 'zaakvertrouwelijk',
        'file' => $expectedPath,
        'disk' => 'local',
        'zaak' => [
            'identificatie' => ZGW_ZAAK_IDENTIFICATIE,
        ],
    ])->and($contentDuringUpload)->toBe('%PDF-1.4 test content');

    Storage::disk('local')->assertMissing($expectedPath);
    Http::assertSent(fn (Request $request) => $request->url() === ZGW_EIO_URL.'/download');
});

test('processDocumentAangemaakt removes the temp file when the upload fails', function () {
    fakeDocumentApis(zgwDocument());
    $notification = makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL);

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    $this->zaakdms->shouldReceive('voegZaakdocumentToe')->once()->andThrow(new RuntimeException('Upload rejected'));

    expect(fn () => corsaService()->processDocumentAangemaakt($notification))
        ->toThrow(RuntimeException::class, 'Upload rejected');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('processDocumentAangemaakt throws and uploads nothing when the downloaded content is empty', function () {
    fakeDocumentApis(zgwDocument(), '');
    $notification = makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL);

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    $this->zaakdms->shouldNotReceive('voegZaakdocumentToe');

    expect(fn () => corsaService()->processDocumentAangemaakt($notification))
        ->toThrow(RuntimeException::class, 'Document content is empty');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('processDocumentAangemaakt throws when the document has no inhoud url', function () {
    fakeDocumentApis(zgwDocument(['inhoud' => null]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    $this->zaakdms->shouldNotReceive('voegZaakdocumentToe');

    expect(fn () => corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL)))
        ->toThrow(RuntimeException::class, 'Missing inhoud url for document download');

    Http::assertNotSent(fn (Request $request) => $request->url() === ZGW_EIO_URL.'/download');
});

test('processDocumentAangemaakt throws when the zaak does not exist in the document management system', function () {
    fakeDocumentApis(zgwDocument());

    $this->zaakdms->shouldReceive('geefZaakDetails')->once()->andThrow(new Exception('not found'));
    $this->zaakdms->shouldNotReceive('voegZaakdocumentToe');

    expect(fn () => corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL)))
        ->toThrow(RuntimeException::class, 'Missing zaak in Corsa for partial update');

    Http::assertNotSent(fn (Request $request) => $request->url() === ZGW_EIO_URL);
});

test('processDocumentAangemaakt throws when the zaakinformatieobject has no informatieobject url', function () {
    Http::fake([
        ZGW_ZIO_URL => Http::response(['url' => ZGW_ZIO_URL, 'zaak' => ZGW_ZAAK_URL]),
    ]);
    $this->zaakdms->shouldNotReceive('geefZaakDetails', 'voegZaakdocumentToe');

    expect(fn () => corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL)))
        ->toThrow(RuntimeException::class, 'Missing informatieobject url for document create');
});

test('processDocumentAangemaakt throws before calling any API when the notification has no zaakinformatieobject url', function () {
    Http::fake();
    $this->zaakdms->shouldNotReceive('geefZaakDetails', 'voegZaakdocumentToe');

    expect(fn () => corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', null)))
        ->toThrow(RuntimeException::class, 'Missing zaakinformatieobject url for document create');

    Http::assertNothingSent();
});

test('processDocumentAangemaakt asks the document management system for an identificatie when the document has none', function () {
    // Without identificatie and without a url, nothing is left to derive an identificatie from.
    fakeDocumentApis(zgwDocument(['identificatie' => null, 'url' => null]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    $this->zaakdms->shouldReceive('genereerDocumentIdentificatie')->once()->andReturn('DOC-GEN-0001');
    captureDocumentUpload($this->zaakdms, $options, $contentDuringUpload);

    corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL));

    expect($options['identificatie'])->toBe('DOC-GEN-0001');
});

test('processDocumentAangemaakt currently uses the uuid from the document url when the document has no identificatie', function () {
    // The current ZGW client adds a uuid derived from the url to every single-object response.
    fakeDocumentApis(zgwDocument(['identificatie' => null]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    $this->zaakdms->shouldNotReceive('genereerDocumentIdentificatie');
    captureDocumentUpload($this->zaakdms, $options, $contentDuringUpload);

    corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL));

    expect($options['identificatie'])->toBe('00000000-0000-0000-0000-000000000501');
});

test('processDocumentAangemaakt slugs the temp file name and keeps the extension', function (array $documentOverrides, string $expectedFilename) {
    fakeDocumentApis(zgwDocument($documentOverrides));
    $notification = makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL);

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    captureDocumentUpload($this->zaakdms, $options, $contentDuringUpload);

    corsaService()->processDocumentAangemaakt($notification);

    expect($options['file'])->toBe('zaakdms-temp/'.$notification->id.'/'.$expectedFilename)
        ->and($contentDuringUpload)->toBe('%PDF-1.4 test content');
})->with([
    'spaces, brackets and capitals' => [['bestandsnaam' => 'Aanvraag Formulier (definitief).PDF'], 'aanvraag-formulier-definitief.PDF'],
    'no extension' => [['bestandsnaam' => 'Situatieschets'], 'situatieschets'],
    'falls back to titel' => [['bestandsnaam' => null, 'titel' => 'Plattegrond evenement.png'], 'plattegrond-evenement.png'],
    'falls back to identificatie' => [['bestandsnaam' => null, 'titel' => null], 'document-doc-2026-0001.bin'],
]);

test('processDocumentAangemaakt falls back to a generated name when the file name has no usable characters', function () {
    fakeDocumentApis(zgwDocument(['bestandsnaam' => '###.pdf']));
    $notification = makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL);

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    captureDocumentUpload($this->zaakdms, $options, $contentDuringUpload);

    corsaService()->processDocumentAangemaakt($notification);

    expect($options['file'])->toMatch('#^zaakdms-temp/'.$notification->id.'/document-[0-9a-f-]{36}\.pdf$#');
});

test('processDocumentAangemaakt maps the vertrouwelijkheidaanduiding', function (?string $value, string $expected) {
    fakeDocumentApis(zgwDocument(['vertrouwelijkheidaanduiding' => $value]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    captureDocumentUpload($this->zaakdms, $options, $contentDuringUpload);

    corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL));

    expect($options['vertrouwelijkAanduiding'])->toBe($expected);
})->with([
    'openbaar' => ['openbaar', 'openbaar'],
    'beperkt_openbaar' => ['beperkt_openbaar', 'beperkt_openbaar'],
    'intern' => ['intern', 'intern'],
    'zaakvertrouwelijk' => ['zaakvertrouwelijk', 'zaakvertrouwelijk'],
    'vertrouwelijk' => ['vertrouwelijk', 'vertrouwelijk'],
    'confidentieel' => ['confidentieel', 'confidentieel'],
    'geheim' => ['geheim', 'geheim'],
    'zeer_geheim' => ['zeer_geheim', 'zeer_geheim'],
    'capitals and a space are normalised' => ['Beperkt Openbaar', 'beperkt_openbaar'],
    'unknown value falls back to the default' => ['topgeheim', 'zaakvertrouwelijk'],
    'empty value falls back to the default' => ['', 'zaakvertrouwelijk'],
    'missing value falls back to the default' => [null, 'zaakvertrouwelijk'],
]);

test('processDocumentAangemaakt formats dates as Ymd and leaves missing dates empty', function () {
    fakeDocumentApis(zgwDocument([
        'creatiedatum' => '2026-02-03',
        'ontvangstdatum' => null,
        'verzenddatum' => '2026-02-05T23:15:00+01:00',
    ]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    captureDocumentUpload($this->zaakdms, $options, $contentDuringUpload);

    corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL));

    expect($options['creatiedatum'])->toBe('20260203')
        ->and($options['ontvangstdatum'])->toBeNull()
        ->and($options['verzenddatum'])->toBe('20260205');
});

test('processDocumentAangemaakt uses defaults for a document without type, author and titel', function () {
    fakeDocumentApis(zgwDocument([
        'informatieobjecttype' => null,
        'auteur' => null,
        'titel' => null,
    ]));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    captureDocumentUpload($this->zaakdms, $options, $contentDuringUpload);

    corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL));

    expect($options['documenttype'])->toBe('Onbekend')
        ->and($options['auteur'])->toBe('OpenZaak')
        ->and($options['titel'])->toBe('Aanvraagformulier.pdf');

    Http::assertNotSent(fn (Request $request) => $request->url() === ZGW_IOT_URL);
});

test('processDocumentAangemaakt throws and uploads nothing when the document content cannot be downloaded', function () {
    // Registered first, so this stub wins over the download stub in fakeDocumentApis().
    Http::fake([
        ZGW_EIO_URL.'/download' => Http::response('<html>Internal Server Error</html>', 500),
    ]);
    fakeDocumentApis(zgwDocument());

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    $this->zaakdms->shouldNotReceive('voegZaakdocumentToe');

    expect(fn () => corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL)))
        ->toThrow(ApiRequestException::class);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('processDocumentAangemaakt sends no request to an inhoud url outside the configured ZGW hosts', function () {
    fakeDocumentApis(zgwDocument(['inhoud' => 'https://elsewhere.example.com/download/00000000-0000-0000-0000-000000000501']));

    $this->zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaZaakDetails());
    $this->zaakdms->shouldNotReceive('voegZaakdocumentToe');

    expect(fn () => corsaService()->processDocumentAangemaakt(makeServiceNotification('zaakinformatieobject', ZGW_ZIO_URL)))
        ->toThrow(DisallowedHostException::class);

    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://elsewhere.example.com'));
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
