<?php

use App\Enums\NotificationIgnoreReason;
use App\Enums\NotificationRoute;
use App\Jobs\Notifications\HandleNotification;
use App\Jobs\Notifications\IngestNotification;
use App\Jobs\Notifications\ProcessDocumentDirectly;
use App\Models\Batch;
use App\Models\DocumentSync;
use App\Models\Notification;
use App\Models\ZaaktypeMapping;
use App\Services\BatchingService;
use App\Services\CorsaZaakdmsService;
use App\Services\Zgw\ZgwReader;
use App\ValueObjects\OpenNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Woweb\Zaakdms\Helper;
use Woweb\Zaakdms\Response\ZaakdmsResponse;
use Woweb\Zaakdms\Zaakdms;

/*
 * Changes to a document (update and partial_update on enkelvoudiginformatieobject): finding the
 * zaak on ingest, sending the newest version to the document management system on the direct
 * route and in a batch, and the order of a batch. The ZGW APIs are faked at the HTTP level and the
 * document management system client is mocked at its public method boundary.
 */

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

const UPD_ZAAK_URL = 'https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000021';
const UPD_OTHER_ZAAK_URL = 'https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000022';
const UPD_ZAAKTYPE_URL = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/00000000-0000-0000-0000-000000000121';
const UPD_OTHER_ZAAKTYPE_URL = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/00000000-0000-0000-0000-000000000122';
const UPD_ZIO_LIST_URL = 'https://openzaak.example.com/zaken/api/v1/zaakinformatieobjecten';
const UPD_EIO_URL = 'https://openzaak.example.com/documenten/api/v1/enkelvoudiginformatieobjecten/00000000-0000-0000-0000-000000000521';
const UPD_IOT_URL = 'https://openzaak.example.com/catalogi/api/v1/informatieobjecttypen/00000000-0000-0000-0000-000000000621';
const UPD_ZAAK_IDENTIFICATIE = 'ZAAK-2026-0021';
const UPD_OTHER_ZAAK_IDENTIFICATIE = 'ZAAK-2026-0022';
const UPD_DOCUMENT_IDENTIFICATIE = 'DOC-2026-0021';

beforeEach(function () {
    Log::spy();
    Storage::fake('local');
    Http::preventStrayRequests();
});

// ─── fixtures ────────────────────────────────────────────────────────────────

function updZaak(string $url = UPD_ZAAK_URL, string $identificatie = UPD_ZAAK_IDENTIFICATIE, string $zaaktype = UPD_ZAAKTYPE_URL): array
{
    return [
        'url' => $url,
        'uuid' => substr($url, strrpos($url, '/') + 1),
        'identificatie' => $identificatie,
        'bronorganisatie' => '000000000',
        'omschrijving' => 'Testzaak',
        'zaaktype' => $zaaktype,
        'registratiedatum' => '2026-01-15',
        'startdatum' => '2026-01-14',
        'einddatum' => null,
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'zaakgeometrie' => null,
    ];
}

function updDocument(array $overrides = []): array
{
    return array_merge([
        'url' => UPD_EIO_URL,
        'identificatie' => UPD_DOCUMENT_IDENTIFICATIE,
        'bronorganisatie' => '000000000',
        'creatiedatum' => '2026-01-15',
        'titel' => 'Brief',
        'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
        'auteur' => 'Testauteur',
        'status' => 'definitief',
        'formaat' => 'application/pdf',
        'taal' => 'nld',
        'versie' => 2,
        'bestandsnaam' => 'Brief.pdf',
        'inhoud' => UPD_EIO_URL.'/download',
        'bestandsdelen' => [],
        'beschrijving' => 'Testdocument',
        'informatieobjecttype' => UPD_IOT_URL,
    ], $overrides);
}

function updLink(string $zaakUrl): array
{
    return [
        'url' => 'https://openzaak.example.com/zaken/api/v1/zaakinformatieobjecten/'.substr($zaakUrl, -12),
        'zaak' => $zaakUrl,
        'informatieobject' => UPD_EIO_URL,
    ];
}

/**
 * Fake the links of the document, the zaken, the document, its type and its content.
 *
 * @param  list<array<string, mixed>>  $links
 */
function fakeUpdApis(array $document, array $links = [], string $content = '%PDF-1.4 new content'): void
{
    Http::fake([
        UPD_ZIO_LIST_URL.'*' => Http::response($links),
        UPD_ZAAK_URL.'*' => Http::response(updZaak()),
        UPD_OTHER_ZAAK_URL.'*' => Http::response(updZaak(UPD_OTHER_ZAAK_URL, UPD_OTHER_ZAAK_IDENTIFICATIE, UPD_OTHER_ZAAKTYPE_URL)),
        UPD_EIO_URL.'/download' => Http::response($content, 200, ['Content-Type' => 'application/octet-stream']),
        UPD_EIO_URL => Http::response($document),
        UPD_IOT_URL => Http::response(['url' => UPD_IOT_URL, 'omschrijving' => 'Brief']),
    ]);
}

function updOpenNotification(string $actie = 'update', string $resource = 'enkelvoudiginformatieobject'): OpenNotification
{
    return new OpenNotification(
        actie: $actie,
        kanaal: 'documenten',
        resource: $resource,
        hoofdObject: UPD_EIO_URL,
        resourceUrl: $resource === 'enkelvoudiginformatieobject'
            ? UPD_EIO_URL
            : 'https://openzaak.example.com/documenten/api/v1/'.$resource.'/00000000-0000-0000-0000-000000000721',
        aanmaakdatum: '2026-01-15T10:00:00Z',
        kenmerken: ['bronorganisatie' => '000000000'],
    );
}

function updMapping(string $zaaktype = UPD_ZAAKTYPE_URL, bool $active = true): void
{
    ZaaktypeMapping::factory()->create([
        'zaaktype_url' => $zaaktype,
        'corsa_zaaktype_code' => 'CODE_21',
        'is_active' => $active,
    ]);
}

function ingestUpd(OpenNotification $openNotification): void
{
    (new IngestNotification($openNotification))->handle(app(ZgwReader::class), app(BatchingService::class));
}

function makeUpdNotification(string $actie = 'update', NotificationRoute $route = NotificationRoute::Direct): Notification
{
    return Notification::create([
        'zaak_identificatie' => UPD_ZAAK_IDENTIFICATIE,
        'route' => $route,
        'notification' => updOpenNotification($actie)->toArray(),
        'processed' => false,
    ]);
}

function registerUpdDocument(array $attributes = []): DocumentSync
{
    return DocumentSync::factory()->create([
        'document_url' => UPD_EIO_URL,
        'zaak_identificatie' => UPD_ZAAK_IDENTIFICATIE,
        'corsa_identificatie' => UPD_DOCUMENT_IDENTIFICATIE,
        'versie' => 1,
        'placeholder' => false,
        ...$attributes,
    ]);
}

function runUpdJob(Notification $notification, int $documentWaits = 0): ProcessDocumentDirectly
{
    $job = (new ProcessDocumentDirectly($notification, $documentWaits))->withFakeQueueInteractions();
    $job->handle(app(CorsaZaakdmsService::class), app(BatchingService::class));

    return $job;
}

/**
 * Capture the options of updateZaakDocument together with the temp file content at the moment of the upload.
 */
function captureUpdUpload($zaakdms, ?array &$captured, ?string &$contentDuringUpload): void
{
    $zaakdms->shouldReceive('updateZaakDocument')
        ->once()
        ->withArgs(function (array $options) use (&$captured, &$contentDuringUpload) {
            $captured = $options;
            $contentDuringUpload = Storage::disk('local')->exists($options['file'])
                ? Storage::disk('local')->get($options['file'])
                : null;

            return true;
        })
        ->andReturn(UPD_DOCUMENT_IDENTIFICATIE);
}

/**
 * A geefLijstZaakdocumenten answer as the client hands it over: the soap body parsed by the client's
 * own response handling, then the first zknobject, as Zaakdms::geefLijstZaakdocumenten() does. The
 * shape (the zaak object with one heeftRelevant per document, its identificatie under gerelateerde)
 * follows existing code that reads this answer; it still has to be confirmed against a test
 * environment of the document management system.
 *
 * @param  list<string>  $documentIdentificaties
 */
function corsaDocumentList(array $documentIdentificaties): SimpleXMLElement
{
    $relevant = implode('', array_map(fn (string $identificatie) => <<<XML
                <zkn:heeftRelevant stuf:entiteittype="ZAKEDC">
                    <zkn:gerelateerde stuf:entiteittype="EDC">
                        <zkn:identificatie>{$identificatie}</zkn:identificatie>
                        <zkn:dct.omschrijving>Brief</zkn:dct.omschrijving>
                        <zkn:titel>Brief</zkn:titel>
                    </zkn:gerelateerde>
                </zkn:heeftRelevant>
        XML, $documentIdentificaties));

    $soap = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <zkn:zakLa01 xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:stuf="http://www.egem.nl/StUF/StUF0301">
              <zkn:stuurgegevens><stuf:berichtcode>La01</stuf:berichtcode></zkn:stuurgegevens>
              <zkn:antwoord>
                <zkn:object stuf:entiteittype="ZAK">
                  <zkn:identificatie>ZAAK-2026-0021</zkn:identificatie>
                  {$relevant}
                </zkn:object>
              </zkn:antwoord>
            </zkn:zakLa01>
          </soap:Body>
        </soap:Envelope>
        XML;

    $body = ZaakdmsResponse::validate(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(200, [], $soap)));

    return Helper::findInObject($body, 'zknobject');
}

// ─── Ingest: kanaal documenten ───────────────────────────────────────────────

test('a change to a document linked to one zaak is stored on the direct route and queued on the direct queue', function (string $actie) {
    Queue::fake();
    updMapping();
    fakeUpdApis(updDocument(), [updLink(UPD_ZAAK_URL)]);

    ingestUpd(updOpenNotification($actie));

    $notification = Notification::sole();
    expect($notification->route)->toBe(NotificationRoute::Direct)
        ->and($notification->zaak_identificatie)->toBe(UPD_ZAAK_IDENTIFICATIE)
        ->and($notification->batch_id)->toBeNull()
        ->and($notification->isIgnored())->toBeFalse()
        ->and($notification->notification['kanaal'])->toBe('documenten')
        ->and(Batch::count())->toBe(0);

    Queue::assertPushedOn('direct', ProcessDocumentDirectly::class);

    // The zaak is found through the filter on the document, and the document itself is only fetched by the job.
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), UPD_ZIO_LIST_URL.'?')
        && $request['informatieobject'] === UPD_EIO_URL);
    Http::assertNotSent(fn (Request $request) => $request->url() === UPD_EIO_URL);
})->with(['update', 'partial_update']);

test('a change to a document that is not linked to any zaak is ignored', function () {
    Queue::fake();
    updMapping();
    fakeUpdApis(updDocument(), []);

    ingestUpd(updOpenNotification());

    $notification = Notification::sole();
    expect($notification->isIgnored())->toBeTrue()
        ->and($notification->ignored_reason)->toBe(NotificationIgnoreReason::DocumentNotLinked)
        ->and($notification->zaak_identificatie)->toBeNull();

    Http::assertSentCount(1);
    Queue::assertNotPushed(ProcessDocumentDirectly::class);
});

test('a change to a document linked to more than one zaak with an active mapping is ignored with a warning', function () {
    Queue::fake();
    updMapping();
    updMapping(UPD_OTHER_ZAAKTYPE_URL);
    fakeUpdApis(updDocument(), [updLink(UPD_ZAAK_URL), updLink(UPD_OTHER_ZAAK_URL)]);

    ingestUpd(updOpenNotification());

    $notification = Notification::sole();
    expect($notification->ignored_reason)->toBe(NotificationIgnoreReason::DocumentInMultipleZaken)
        ->and($notification->zaak_identificatie)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'more than one zaak')
            && $context['zaak_identificaties'] === [UPD_ZAAK_IDENTIFICATIE, UPD_OTHER_ZAAK_IDENTIFICATIE])
        ->once();
    Queue::assertNotPushed(ProcessDocumentDirectly::class);
});

test('only zaken with an active mapping count when a document is linked to more than one zaak', function (?bool $otherMappingActive) {
    Queue::fake();
    updMapping();
    if ($otherMappingActive !== null) {
        updMapping(UPD_OTHER_ZAAKTYPE_URL, $otherMappingActive);
    }
    fakeUpdApis(updDocument(), [updLink(UPD_OTHER_ZAAK_URL), updLink(UPD_ZAAK_URL)]);

    ingestUpd(updOpenNotification());

    $notification = Notification::sole();
    expect($notification->route)->toBe(NotificationRoute::Direct)
        ->and($notification->zaak_identificatie)->toBe(UPD_ZAAK_IDENTIFICATIE);
    Queue::assertPushed(ProcessDocumentDirectly::class, 1);
})->with(['other zaaktype without mapping' => [null], 'other mapping inactive' => [false]]);

test('a change to a document of a zaak without an active mapping is ignored like other notifications of that zaak', function (bool $withMapping, NotificationIgnoreReason $reason) {
    Queue::fake();
    if ($withMapping) {
        updMapping(active: false);
    }
    fakeUpdApis(updDocument(), [updLink(UPD_ZAAK_URL)]);

    ingestUpd(updOpenNotification());

    $notification = Notification::sole();
    expect($notification->ignored_reason)->toBe($reason)
        ->and($notification->zaak_identificatie)->toBe(UPD_ZAAK_IDENTIFICATIE);
    Queue::assertNotPushed(ProcessDocumentDirectly::class);
})->with([
    'inactive mapping' => [true, NotificationIgnoreReason::InactiveZaaktype],
    'no mapping' => [false, NotificationIgnoreReason::UnknownZaaktype],
]);

test('other actions on the documenten kanaal are ignored without fetching anything', function (string $actie, string $resource) {
    Queue::fake();
    Http::fake();

    ingestUpd(updOpenNotification($actie, $resource));

    expect(Notification::sole()->ignored_reason)->toBe(NotificationIgnoreReason::UnsupportedAction);
    Http::assertNothingSent();
    Queue::assertNotPushed(ProcessDocumentDirectly::class);
})->with([
    'create document' => ['create', 'enkelvoudiginformatieobject'],
    'destroy document' => ['destroy', 'enkelvoudiginformatieobject'],
    'gebruiksrechten' => ['create', 'gebruiksrechten'],
    'gebruiksrechten update' => ['update', 'gebruiksrechten'],
    'verzending' => ['partial_update', 'verzending'],
]);

test('an action is only processed on its own kanaal', function (string $kanaal, string $actie, string $resource) {
    Queue::fake();
    Http::fake();

    ingestUpd(new OpenNotification(
        actie: $actie,
        kanaal: $kanaal,
        resource: $resource,
        hoofdObject: UPD_EIO_URL,
        resourceUrl: UPD_EIO_URL,
        aanmaakdatum: '2026-01-15T10:00:00Z',
    ));

    expect(Notification::sole()->ignored_reason)->toBe(NotificationIgnoreReason::UnsupportedAction);
    Http::assertNothingSent();
    Queue::assertNotPushed(ProcessDocumentDirectly::class);
})->with([
    'change to a document on the zaken kanaal' => ['zaken', 'update', 'enkelvoudiginformatieobject'],
    'zaak on the documenten kanaal' => ['documenten', 'create', 'zaak'],
]);

test('a change to a document whose link points outside the configured hosts fails the ingest', function () {
    Queue::fake();
    Http::fake([UPD_ZIO_LIST_URL.'*' => Http::response([updLink('https://elsewhere.example.org/zaken/api/v1/zaken/1')])]);

    $job = (new IngestNotification(updOpenNotification()))->withFakeQueueInteractions();
    $job->handle(app(ZgwReader::class), app(BatchingService::class));

    $job->assertFailed();
    expect(Notification::count())->toBe(0);
});

// ─── The direct job ──────────────────────────────────────────────────────────

test('the direct job sends the newest version with the content from the ZGW APIs and updates the register', function () {
    fakeUpdApis(updDocument(['versie' => 3]));
    registerUpdDocument(['versie' => 2, 'synced_at' => now()->subDay()]);
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldNotReceive('geefLijstZaakdocumenten', 'voegZaakdocumentToe');
    captureUpdUpload($zaakdms, $options, $content);

    $notification = makeUpdNotification();
    $job = runUpdJob($notification);

    $job->assertNotFailed();
    $job->assertNotReleased();

    expect($options['identificatie'])->toBe(UPD_DOCUMENT_IDENTIFICATIE)
        ->and($options['zaak'])->toBe(['identificatie' => UPD_ZAAK_IDENTIFICATIE])
        ->and($options['status'])->toBe('definitief')
        ->and($options['titel'])->toBe('Brief')
        ->and($options['documenttype'])->toBe('Brief')
        ->and($options['creatiedatum'])->toBe('20260115')
        ->and($options['vertrouwelijkAanduiding'])->toBe('zaakvertrouwelijk')
        ->and($options['disk'])->toBe('local')
        ->and($content)->toBe('%PDF-1.4 new content');

    $sync = DocumentSync::sole();
    expect($sync->versie)->toBe(3)
        ->and($sync->placeholder)->toBeFalse()
        ->and($sync->synced_at->isToday())->toBeTrue()
        ->and($notification->fresh()->processed)->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('the options of an update are the same as those of the add, apart from the document management call', function () {
    // The add of the same document, through the batch route, for comparison.
    // Registered first, because the fake of the list of links matches this URL as well.
    Http::fake([
        'https://openzaak.example.com/zaken/api/v1/zaakinformatieobjecten/00000000-0000-0000-0000-000000000421' => Http::response(updLink(UPD_ZAAK_URL)),
        UPD_EIO_URL => Http::sequence()->push(updDocument(['versie' => 1]))->push(updDocument(['versie' => 2])),
    ]);
    fakeUpdApis(updDocument(['versie' => 1]));
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(['dms_id' => null, 'response' => (object) ['zknidentificatie' => UPD_ZAAK_IDENTIFICATIE]]);
    $zaakdms->shouldReceive('voegZaakdocumentToe')->once()->withArgs(function (array $options) use (&$addOptions) {
        $addOptions = $options;

        return true;
    })->andReturn(UPD_DOCUMENT_IDENTIFICATIE);

    $link = Notification::factory()->create([
        'zaak_identificatie' => UPD_ZAAK_IDENTIFICATIE,
        'notification' => [
            'actie' => 'create', 'kanaal' => 'zaken', 'resource' => 'zaakinformatieobject',
            'hoofdObject' => UPD_ZAAK_URL,
            'resourceUrl' => 'https://openzaak.example.com/zaken/api/v1/zaakinformatieobjecten/00000000-0000-0000-0000-000000000421',
            'aanmaakdatum' => '2026-01-15T10:00:00Z',
        ],
    ]);
    app(CorsaZaakdmsService::class)->processDocumentAangemaakt($link);

    captureUpdUpload($zaakdms, $updateOptions, $content);
    runUpdJob(makeUpdNotification())->assertNotFailed();

    expect(array_diff_key($updateOptions, ['file' => true]))->toBe(array_diff_key($addOptions, ['file' => true]));
});

test('a placeholder is replaced by the real content of the first change that has content', function () {
    fakeUpdApis(updDocument(['versie' => 2, 'status' => 'in_bewerking']));
    registerUpdDocument(['versie' => 1, 'placeholder' => true]);
    $zaakdms = $this->mock(Zaakdms::class);
    captureUpdUpload($zaakdms, $options, $content);

    runUpdJob(makeUpdNotification())->assertNotFailed();

    expect($content)->toBe('%PDF-1.4 new content')
        ->and($options['status'])->toBe('in_bewerking')
        ->and(DocumentSync::sole()->placeholder)->toBeFalse()
        ->and(DocumentSync::sole()->versie)->toBe(2);
});

test('a change that is still in_bewerking without content keeps the placeholder', function (array $document, string $content) {
    fakeUpdApis(updDocument(['versie' => 2, 'status' => 'in_bewerking', ...$document]), content: $content);
    registerUpdDocument(['versie' => 1, 'placeholder' => true]);
    $this->mock(Zaakdms::class)->shouldNotReceive('updateZaakDocument');

    $notification = makeUpdNotification();
    runUpdJob($notification)->assertNotFailed();

    expect($notification->fresh()->processed)->toBeTrue()
        ->and(DocumentSync::sole()->placeholder)->toBeTrue()
        ->and(DocumentSync::sole()->versie)->toBe(1);
})->with([
    'no inhoud' => [['inhoud' => null], ''],
    'empty download' => [[], ''],
]);

test('a change without content is refused when the document is no longer in_bewerking', function (array $document, string $message) {
    fakeUpdApis(updDocument(['versie' => 2, ...$document]), content: '');
    registerUpdDocument();
    $this->mock(Zaakdms::class)->shouldNotReceive('updateZaakDocument');

    $notification = makeUpdNotification();
    $job = runUpdJob($notification);

    $job->assertFailed();
    expect($notification->fresh()->processed)->toBeFalse()
        ->and(DocumentSync::sole()->versie)->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $context) => $context['error'] === $message)->once();
})->with([
    'no inhoud' => [['inhoud' => null], 'Missing inhoud url for document download'],
    'empty download' => [[], 'Document content is empty'],
]);

test('a version that the document management system already has is not sent again', function (int $versie) {
    fakeUpdApis(updDocument(['versie' => $versie]));
    registerUpdDocument(['versie' => 3]);
    $this->mock(Zaakdms::class)->shouldNotReceive('updateZaakDocument', 'geefLijstZaakdocumenten');

    $notification = makeUpdNotification();
    runUpdJob($notification)->assertNotFailed();

    expect($notification->fresh()->processed)->toBeTrue()
        ->and(DocumentSync::sole()->versie)->toBe(3);
    Http::assertNotSent(fn (Request $request) => $request->url() === UPD_EIO_URL.'/download');
})->with(['same version' => [3], 'older version' => [2]]);

test('a change delivered twice is sent once', function () {
    fakeUpdApis(updDocument(['versie' => 2]));
    registerUpdDocument(['versie' => 1]);
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('updateZaakDocument')->once()->andReturn(UPD_DOCUMENT_IDENTIFICATIE);

    $first = makeUpdNotification();
    $second = makeUpdNotification('partial_update');
    runUpdJob($first)->assertNotFailed();
    runUpdJob($second)->assertNotFailed();

    expect($first->fresh()->processed)->toBeTrue()
        ->and($second->fresh()->processed)->toBeTrue();
});

test('a change waits while the content is still uploaded in parts, and fails after the last wait', function () {
    Queue::fake();
    fakeUpdApis(updDocument(['bestandsdelen' => [['url' => UPD_EIO_URL.'/bestandsdelen/1', 'volgnummer' => 1]]]));
    registerUpdDocument();
    $this->mock(Zaakdms::class)->shouldNotReceive('updateZaakDocument');

    $notification = makeUpdNotification();

    foreach ([0 => 30, 1 => 120, 2 => 300] as $waits => $delay) {
        $job = runUpdJob($notification, $waits);

        $job->assertNotFailed();
        Queue::assertPushed(ProcessDocumentDirectly::class, fn (ProcessDocumentDirectly $next) => $next->documentWaits === $waits + 1
            && $next->delay === $delay
            && $next->queue === ProcessDocumentDirectly::QUEUE);
    }

    $last = runUpdJob($notification, 3);

    $last->assertFailed();
    expect($notification->fresh()->processed)->toBeFalse()
        ->and($notification->fresh()->route)->toBe(NotificationRoute::Direct);
    Queue::assertPushed(ProcessDocumentDirectly::class, 3);
});

test('a change waits for a document that is not in the document management system yet, and then moves to the batch of the zaak', function () {
    Queue::fake();
    fakeUpdApis(updDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefLijstZaakdocumenten')->times(4)->with(UPD_ZAAK_IDENTIFICATIE)->andReturn(corsaDocumentList(['DOC-2026-9999']));
    $zaakdms->shouldNotReceive('updateZaakDocument');

    $notification = makeUpdNotification();

    foreach ([0 => 30, 1 => 120, 2 => 300] as $waits => $delay) {
        runUpdJob($notification, $waits)->assertNotFailed();

        Queue::assertPushed(ProcessDocumentDirectly::class, fn (ProcessDocumentDirectly $next) => $next->documentWaits === $waits + 1 && $next->delay === $delay);
        expect($notification->fresh()->route)->toBe(NotificationRoute::Direct);
    }

    runUpdJob($notification, 3)->assertNotFailed();

    $notification->refresh();
    expect($notification->route)->toBe(NotificationRoute::Batch)
        ->and($notification->batch_id)->toBe(Batch::sole()->id)
        ->and(Batch::sole()->zaak_identificatie)->toBe(UPD_ZAAK_IDENTIFICATIE)
        ->and($notification->processed)->toBeFalse()
        ->and(DocumentSync::count())->toBe(0);
    Queue::assertPushed(ProcessDocumentDirectly::class, 3);
});

test('a document without a register entry is updated when the list of the zaak has it, and is registered', function () {
    fakeUpdApis(updDocument(['versie' => 4]));
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefLijstZaakdocumenten')->once()->with(UPD_ZAAK_IDENTIFICATIE)
        ->andReturn(corsaDocumentList(['DOC-2026-0001', UPD_DOCUMENT_IDENTIFICATIE]));
    captureUpdUpload($zaakdms, $options, $content);

    runUpdJob(makeUpdNotification())->assertNotFailed();

    expect($options['identificatie'])->toBe(UPD_DOCUMENT_IDENTIFICATIE);
    $sync = DocumentSync::sole();
    expect($sync->document_url)->toBe(UPD_EIO_URL)
        ->and($sync->zaak_identificatie)->toBe(UPD_ZAAK_IDENTIFICATIE)
        ->and($sync->corsa_identificatie)->toBe(UPD_DOCUMENT_IDENTIFICATIE)
        ->and($sync->versie)->toBe(4)
        ->and($sync->placeholder)->toBeFalse();
});

test('the identificaties are read from the list of documents of a zaak', function (array $identificaties) {
    expect(CorsaZaakdmsService::listedDocumentIdentificaties(corsaDocumentList($identificaties)))->toBe($identificaties);
})->with([
    'no documents' => [[]],
    'one document' => [['DOC-2026-0001']],
    'three documents' => [['DOC-2026-0001', 'DOC-2026-0002', 'DOC-2026-0003']],
]);

test('a document counts as not in the document management system when its zaak cannot be listed', function (Closure $error) {
    Queue::fake();
    fakeUpdApis(updDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefLijstZaakdocumenten')->once()->andThrow($error());
    $zaakdms->shouldNotReceive('updateZaakDocument');

    $job = runUpdJob(makeUpdNotification());

    $job->assertNotFailed();
    Queue::assertPushed(ProcessDocumentDirectly::class, fn (ProcessDocumentDirectly $next) => $next->documentWaits === 1);
})->with([
    'fault message' => [fn () => new Exception('Response for Zaakdms failed. Errors: zaak niet gevonden')],
    'answer without a zaak object' => [fn () => new TypeError('Return value must be of type SimpleXMLElement, null returned')],
]);

test('a connection or server error while listing the documents of the zaak is retried as a temporary error', function (Closure $error, string $class) {
    Queue::fake();
    fakeUpdApis(updDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefLijstZaakdocumenten')->once()->andThrow($error());

    $notification = makeUpdNotification();
    $job = (new ProcessDocumentDirectly($notification))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(CorsaZaakdmsService::class), app(BatchingService::class)))
        ->toThrow($class);
    $job->assertNotFailed();
    Queue::assertNothingPushed();
})->with([
    'connection error' => [fn () => new ConnectionException('timed out'), ConnectionException::class],
    'server error' => [fn () => new Illuminate\Http\Client\RequestException(
        new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(503))
    ), Illuminate\Http\Client\RequestException::class],
]);

test('a change is sent for the zaak and under the identificatie in the register', function () {
    fakeUpdApis(updDocument(['versie' => 2]));
    registerUpdDocument([
        'versie' => 1,
        'zaak_identificatie' => UPD_OTHER_ZAAK_IDENTIFICATIE,
        'corsa_identificatie' => 'DOC-CORSA-2026-0001',
    ]);
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldNotReceive('geefLijstZaakdocumenten');
    captureUpdUpload($zaakdms, $options, $content);

    // The notification has another zaak and ZGW another identificatie than the register.
    runUpdJob(makeUpdNotification())->assertNotFailed();

    expect($options['identificatie'])->toBe('DOC-CORSA-2026-0001')
        ->and($options['zaak'])->toBe(['identificatie' => UPD_OTHER_ZAAK_IDENTIFICATIE]);

    $sync = DocumentSync::sole();
    expect($sync->zaak_identificatie)->toBe(UPD_OTHER_ZAAK_IDENTIFICATIE)
        ->and($sync->corsa_identificatie)->toBe('DOC-CORSA-2026-0001')
        ->and($sync->versie)->toBe(2);
});

test('a change fetches, locks and registers the document of its hoofdObject, the one whose zaak was looked up', function () {
    $otherDocumentUrl = 'https://openzaak.example.com/documenten/api/v1/enkelvoudiginformatieobjecten/00000000-0000-0000-0000-000000000529';
    fakeUpdApis(updDocument(['versie' => 2]));
    Http::fake([$otherDocumentUrl.'*' => Http::response(updDocument(['url' => $otherDocumentUrl, 'versie' => 9]))]);
    registerUpdDocument();
    $zaakdms = $this->mock(Zaakdms::class);
    captureUpdUpload($zaakdms, $options, $content);

    $notification = Notification::create([
        'zaak_identificatie' => UPD_ZAAK_IDENTIFICATIE,
        'route' => NotificationRoute::Direct,
        'notification' => [...updOpenNotification()->toArray(), 'resourceUrl' => $otherDocumentUrl],
        'processed' => false,
    ]);

    runUpdJob($notification)->assertNotFailed();

    Http::assertSent(fn (Request $request) => $request->url() === UPD_EIO_URL);
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), $otherDocumentUrl));
    expect(DocumentSync::sole()->document_url)->toBe(UPD_EIO_URL)
        ->and(DocumentSync::sole()->versie)->toBe(2);
});

test('a change waits while another job sends the same document', function () {
    fakeUpdApis(updDocument());
    registerUpdDocument();
    $this->mock(Zaakdms::class)->shouldNotReceive('updateZaakDocument');

    $lock = Cache::lock(CorsaZaakdmsService::documentLockKey(UPD_EIO_URL), CorsaZaakdmsService::DOCUMENT_LOCK_SECONDS);
    expect($lock->get())->toBeTrue();

    $notification = makeUpdNotification();
    $job = runUpdJob($notification);

    $job->assertReleased(ProcessDocumentDirectly::LOCK_RETRY_SECONDS);
    $job->assertNotFailed();
    expect($notification->fresh()->processed)->toBeFalse();
    Http::assertNothingSent();
});

test('a failed update releases the lock and records the failure', function () {
    fakeUpdApis(updDocument());
    registerUpdDocument();
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('updateZaakDocument')->once()->andThrow(new Exception('Fo02 refused'));

    $notification = makeUpdNotification();
    runUpdJob($notification)->assertFailed();

    expect(Cache::lock(CorsaZaakdmsService::documentLockKey(UPD_EIO_URL), 1)->get())->toBeTrue()
        ->and(DocumentSync::sole()->versie)->toBe(1);
});

// ─── A change in a batch ─────────────────────────────────────────────────────

function runUpdBatchJob(Notification $notification): HandleNotification
{
    $job = (new HandleNotification($notification))->withFakeQueueInteractions();
    $job->handle(app(CorsaZaakdmsService::class));

    return $job;
}

test('a change in a batch is sent the same way as on the direct route', function () {
    fakeUpdApis(updDocument(['versie' => 2]));
    registerUpdDocument();
    $zaakdms = $this->mock(Zaakdms::class);
    captureUpdUpload($zaakdms, $options, $content);

    $notification = makeUpdNotification('partial_update', NotificationRoute::Batch);
    runUpdBatchJob($notification)->assertNotFailed();

    expect($content)->toBe('%PDF-1.4 new content')
        ->and($notification->fresh()->processed)->toBeTrue()
        ->and(DocumentSync::sole()->versie)->toBe(2);
});

test('a change in a batch fails visibly when the document is not in the document management system', function () {
    fakeUpdApis(updDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefLijstZaakdocumenten')->once()->andReturn(corsaDocumentList([]));
    $zaakdms->shouldNotReceive('updateZaakDocument');

    $notification = makeUpdNotification('update', NotificationRoute::Batch);

    expect(fn () => runUpdBatchJob($notification))->toThrow(RuntimeException::class, 'Missing document in Corsa for document update');
    expect($notification->fresh()->processed)->toBeFalse();
});

test('a change in a batch waits while the content is still uploaded in parts', function () {
    fakeUpdApis(updDocument(['bestandsdelen' => [['url' => UPD_EIO_URL.'/bestandsdelen/1']]]));
    registerUpdDocument();
    $this->mock(Zaakdms::class)->shouldNotReceive('updateZaakDocument');

    $notification = makeUpdNotification('update', NotificationRoute::Batch);
    $job = runUpdBatchJob($notification);

    $job->assertReleased(HandleNotification::LOCK_RETRY_SECONDS);
    $job->assertNotFailed();
    expect($notification->fresh()->processed)->toBeFalse();
});

test('a change in a batch waits for the lock of the document', function () {
    fakeUpdApis(updDocument());
    $this->mock(Zaakdms::class)->shouldNotReceive('updateZaakDocument');
    Cache::lock(CorsaZaakdmsService::documentLockKey(UPD_EIO_URL), 60)->get();

    $job = runUpdBatchJob(makeUpdNotification('update', NotificationRoute::Batch));

    $job->assertReleased(HandleNotification::LOCK_RETRY_SECONDS);
    $job->assertNotFailed();
});

// ─── Order in a batch ────────────────────────────────────────────────────────

/**
 * @return array<string, Notification>
 */
function updBatchWithAllTypes(Batch $batch): array
{
    $make = function (string $actie, string $resource, int $secondsAgo) use ($batch): Notification {
        return Notification::factory()->create([
            'batch_id' => $batch->id,
            'zaak_identificatie' => $batch->zaak_identificatie,
            'created_at' => now()->subSeconds($secondsAgo),
            'notification' => [
                'actie' => $actie,
                'kanaal' => $resource === 'enkelvoudiginformatieobject' ? 'documenten' : 'zaken',
                'resource' => $resource,
                'hoofdObject' => UPD_ZAAK_URL,
                'resourceUrl' => UPD_ZAAK_URL,
                'aanmaakdatum' => now()->toIso8601String(),
            ],
        ]);
    };

    // Created in an order that differs from the processing order on purpose. Within a group the
    // notification that is created later (and so has the higher ordered id) was received earlier,
    // so the order of receipt and the order of the ids differ.
    $made = [];
    $made['resultaat'] = $make('create', 'resultaat', 50);
    $made['partial_update'] = $make('partial_update', 'enkelvoudiginformatieobject', 5);
    $made['update'] = $make('update', 'enkelvoudiginformatieobject', 40);
    $made['status'] = $make('create', 'status', 10);
    $made['link'] = $make('create', 'zaakinformatieobject', 30);
    $made['zaak'] = $make('create', 'zaak', 1);

    return $made;
}

test('a batch processes the zaak first, then the other notifications, then the changes to documents and the resultaat last, each group in order of receipt', function () {
    $batch = Batch::factory()->create(['zaak_identificatie' => UPD_ZAAK_IDENTIFICATIE]);
    $n = updBatchWithAllTypes($batch);

    expect($batch->getNotificationsSorted()->pluck('id')->all())->toBe([
        $n['zaak']->id,
        $n['link']->id,
        $n['status']->id,
        $n['update']->id,
        $n['partial_update']->id,
        $n['resultaat']->id,
    ]);
});

test('the notifications of a batch are listed in the order the batch processes them', function () {
    $this->actingAs(App\Models\User::factory()->create());
    $batch = Batch::factory()->create(['zaak_identificatie' => UPD_ZAAK_IDENTIFICATIE]);
    $n = updBatchWithAllTypes($batch);

    $component = Livewire\Livewire::test(App\Filament\Resources\Batches\RelationManagers\NotificationsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => App\Filament\Resources\Batches\Pages\ViewBatch::class,
    ]);

    expect($component->instance()->getTableRecords()->pluck('id')->all())
        ->toBe($batch->getNotificationsSorted()->pluck('id')->all());

    $component->assertTableColumnStateSet('sort_order', '2', $n['update'])
        ->assertTableColumnStateSet('sort_order', '3', $n['resultaat'])
        ->assertTableColumnStateSet('sort_order', '1', $n['link'])
        ->assertTableColumnStateSet('sort_order', '0', $n['zaak']);
});

test('the processing group of each type of notification', function (string $actie, string $resource, int $group) {
    expect(Notification::processingGroup($actie, $resource))->toBe($group);
})->with([
    ['create', 'zaak', 0],
    ['create', 'status', 1],
    ['create', 'zaakinformatieobject', 1],
    ['update', 'enkelvoudiginformatieobject', 2],
    ['partial_update', 'enkelvoudiginformatieobject', 2],
    ['create', 'resultaat', 3],
]);
