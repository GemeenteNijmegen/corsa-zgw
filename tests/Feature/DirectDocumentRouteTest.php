<?php

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Woweb\Zaakdms\Exceptions\InvalidZaakdmsArgumentsException;
use Woweb\Zaakdms\Zaakdms;

/*
 * The direct route for a document that is linked to a zaak while it is still being edited,
 * the register of documents sent to the document management system, and the placeholder for
 * a document without content. The ZGW APIs are faked at the HTTP level and the document
 * management system client is mocked at its public method boundary.
 */

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

const DIRECT_ZAAK_URL = 'https://openzaak.example.com/zaken/api/v1/zaken/00000000-0000-0000-0000-000000000011';
const DIRECT_ZAAKTYPE_URL = 'https://openzaak.example.com/catalogi/api/v1/zaaktypen/00000000-0000-0000-0000-000000000111';
const DIRECT_ZIO_URL = 'https://openzaak.example.com/zaken/api/v1/zaakinformatieobjecten/00000000-0000-0000-0000-000000000411';
const DIRECT_EIO_URL = 'https://openzaak.example.com/documenten/api/v1/enkelvoudiginformatieobjecten/00000000-0000-0000-0000-000000000511';
const DIRECT_IOT_URL = 'https://openzaak.example.com/catalogi/api/v1/informatieobjecttypen/00000000-0000-0000-0000-000000000611';
const DIRECT_ZAAK_IDENTIFICATIE = 'ZAAK-2026-0011';

beforeEach(function () {
    Log::spy();
    Storage::fake('local');
    Http::preventStrayRequests();
});

// ─── fixtures ────────────────────────────────────────────────────────────────

function directZaak(): array
{
    return [
        'url' => DIRECT_ZAAK_URL,
        'uuid' => '00000000-0000-0000-0000-000000000011',
        'identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'bronorganisatie' => '000000000',
        'omschrijving' => 'Testzaak',
        'zaaktype' => DIRECT_ZAAKTYPE_URL,
        'registratiedatum' => '2026-01-15',
        'startdatum' => '2026-01-14',
        'einddatum' => null,
        'einddatumGepland' => null,
        'uiterlijkeEinddatumAfdoening' => null,
        'zaakgeometrie' => null,
    ];
}

function directDocument(array $overrides = []): array
{
    return array_merge([
        'url' => DIRECT_EIO_URL,
        'identificatie' => 'DOC-2026-0011',
        'bronorganisatie' => '000000000',
        'creatiedatum' => '2026-01-15',
        'titel' => 'Brief',
        'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
        'auteur' => 'Testauteur',
        'status' => 'in_bewerking',
        'formaat' => 'application/pdf',
        'taal' => 'nld',
        'versie' => 1,
        'bestandsnaam' => 'Brief.pdf',
        'inhoud' => DIRECT_EIO_URL.'/download',
        'bestandsdelen' => [],
        'beschrijving' => 'Testdocument',
        'informatieobjecttype' => DIRECT_IOT_URL,
    ], $overrides);
}

/**
 * Fake the zaak, the link, the document, its type and its content.
 */
function fakeDirectApis(array $document, string $content = '%PDF-1.4 test content'): void
{
    Http::fake([
        DIRECT_ZAAK_URL.'*' => Http::response(directZaak()),
        DIRECT_ZIO_URL => Http::response([
            'url' => DIRECT_ZIO_URL,
            'zaak' => DIRECT_ZAAK_URL,
            'informatieobject' => DIRECT_EIO_URL,
        ]),
        DIRECT_EIO_URL.'/download' => Http::response($content, 200, ['Content-Type' => 'application/octet-stream']),
        DIRECT_EIO_URL => Http::response($document),
        DIRECT_IOT_URL => Http::response(['url' => DIRECT_IOT_URL, 'omschrijving' => 'Brief']),
    ]);
}

function directOpenNotification(string $resource = 'zaakinformatieobject', string $resourceUrl = DIRECT_ZIO_URL): OpenNotification
{
    return new OpenNotification(
        actie: 'create',
        kanaal: 'zaken',
        resource: $resource,
        hoofdObject: DIRECT_ZAAK_URL,
        resourceUrl: $resourceUrl,
        aanmaakdatum: '2026-01-15T10:00:00Z',
    );
}

function activeDirectMapping(): void
{
    ZaaktypeMapping::factory()->create([
        'zaaktype_url' => DIRECT_ZAAKTYPE_URL,
        'corsa_zaaktype_code' => 'CODE_11',
        'is_active' => true,
    ]);
}

function ingestDirect(OpenNotification $openNotification): void
{
    (new IngestNotification($openNotification))->handle(app(ZgwReader::class), app(BatchingService::class));
}

function makeDirectNotification(): Notification
{
    return Notification::create([
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'route' => NotificationRoute::Direct,
        'notification' => directOpenNotification()->toArray(),
        'processed' => false,
    ]);
}

function corsaDirectZaakDetails(): array
{
    return ['dms_id' => null, 'response' => (object) ['zknidentificatie' => DIRECT_ZAAK_IDENTIFICATIE]];
}

/**
 * Run the job the way a worker does, with fake queue interactions to observe release and failure.
 */
function runDirectJob(Notification $notification): ProcessDocumentDirectly
{
    $job = (new ProcessDocumentDirectly($notification))->withFakeQueueInteractions();
    $job->handle(app(CorsaZaakdmsService::class), app(BatchingService::class));

    return $job;
}

/**
 * Capture the options of voegZaakdocumentToe together with the temp file content at the moment of the upload.
 */
function captureDirectUpload($zaakdms, ?array &$captured, ?string &$contentDuringUpload): void
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
        ->andReturn('DOC-REF-0011');
}

// ─── Ingest: which route ─────────────────────────────────────────────────────

test('a document linked while in_bewerking is stored on the direct route and queued on the direct queue', function () {
    Queue::fake();
    activeDirectMapping();
    fakeDirectApis(directDocument());

    ingestDirect(directOpenNotification());

    $notification = Notification::sole();
    expect($notification->route)->toBe(NotificationRoute::Direct)
        ->and($notification->batch_id)->toBeNull()
        ->and($notification->zaak_identificatie)->toBe(DIRECT_ZAAK_IDENTIFICATIE)
        ->and($notification->processed)->toBeFalse()
        ->and($notification->isIgnored())->toBeFalse()
        ->and(Batch::count())->toBe(0);

    Queue::assertPushedOn('direct', ProcessDocumentDirectly::class);
    Queue::assertPushed(ProcessDocumentDirectly::class, 1);

    // The content is only downloaded by the job.
    Http::assertNotSent(fn (Request $request) => $request->url() === DIRECT_EIO_URL.'/download');
});

test('a document linked with another status goes into the batch of the zaak', function (string $status) {
    Queue::fake();
    activeDirectMapping();
    fakeDirectApis(directDocument(['status' => $status]));

    ingestDirect(directOpenNotification());

    $notification = Notification::sole();
    expect($notification->fresh()->route)->toBe(NotificationRoute::Batch)
        ->and($notification->batch_id)->toBe(Batch::sole()->id);

    Queue::assertNotPushed(ProcessDocumentDirectly::class);
})->with(['definitief', 'ter_vaststelling', 'gearchiveerd']);

test('a document link goes into the batch when the document status cannot be fetched', function (Closure $fakes) {
    Queue::fake();
    activeDirectMapping();
    Http::fake([DIRECT_ZAAK_URL.'*' => Http::response(directZaak()), ...$fakes()]);

    ingestDirect(directOpenNotification());

    expect(Notification::sole()->fresh()->route)->toBe(NotificationRoute::Batch)
        ->and(Notification::sole()->batch_id)->toBe(Batch::sole()->id);

    Queue::assertNotPushed(ProcessDocumentDirectly::class);
})->with([
    // Closures, because the HTTP fakes need the application, which does not exist yet when datasets load.
    'link unavailable' => [fn () => [DIRECT_ZIO_URL => Http::response('', 503)]],
    'link without document' => [fn () => [DIRECT_ZIO_URL => Http::response(['url' => DIRECT_ZIO_URL])]],
    'document unavailable' => [fn () => [
        DIRECT_ZIO_URL => Http::response(['url' => DIRECT_ZIO_URL, 'informatieobject' => DIRECT_EIO_URL]),
        DIRECT_EIO_URL => Http::response('', 500),
    ]],
]);

test('other notifications do not fetch a document to choose a route', function () {
    Queue::fake();
    activeDirectMapping();
    fakeDirectApis(directDocument());

    ingestDirect(directOpenNotification('status', 'https://openzaak.example.com/zaken/api/v1/statussen/00000000-0000-0000-0000-000000000211'));

    Http::assertSentCount(1);
    expect(Notification::sole()->fresh()->route)->toBe(NotificationRoute::Batch);
    Queue::assertNotPushed(ProcessDocumentDirectly::class);
});

test('a document of a zaak without an active mapping is ignored before a route is chosen', function () {
    Queue::fake();
    fakeDirectApis(directDocument());

    ingestDirect(directOpenNotification());

    expect(Notification::sole()->isIgnored())->toBeTrue();
    Http::assertSentCount(1);
    Queue::assertNotPushed(ProcessDocumentDirectly::class);
});

// ─── The direct job ──────────────────────────────────────────────────────────

test('the direct job adds the document when the zaak is in the document management system and registers it', function () {
    fakeDirectApis(directDocument(['versie' => 3]));
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->once()->with(DIRECT_ZAAK_IDENTIFICATIE)->andReturn(corsaDirectZaakDetails());
    captureDirectUpload($zaakdms, $options, $content);

    $notification = makeDirectNotification();
    $job = runDirectJob($notification);

    $job->assertNotFailed();
    $job->assertNotReleased();

    expect($options['identificatie'])->toBe('DOC-2026-0011')
        ->and($options['status'])->toBe('in_bewerking')
        ->and($options['zaak'])->toBe(['identificatie' => DIRECT_ZAAK_IDENTIFICATIE])
        ->and($content)->toBe('%PDF-1.4 test content');

    $notification->refresh();
    expect($notification->processed)->toBeTrue()
        ->and($notification->processed_at)->not->toBeNull()
        ->and($notification->route)->toBe(NotificationRoute::Direct)
        ->and($notification->batch_id)->toBeNull();

    $sync = DocumentSync::sole();
    expect($sync->document_url)->toBe(DIRECT_EIO_URL)
        ->and($sync->zaak_identificatie)->toBe(DIRECT_ZAAK_IDENTIFICATIE)
        ->and($sync->corsa_identificatie)->toBe('DOC-2026-0011')
        ->and($sync->versie)->toBe(3)
        ->and($sync->placeholder)->toBeFalse()
        ->and($sync->synced_at)->not->toBeNull();
});

test('the direct job moves the notification to the batch of the zaak when the zaak is not in the document management system', function () {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->once()->andThrow(new Exception('not found'));
    $zaakdms->shouldNotReceive('voegZaakdocumentToe');

    $existingBatch = Batch::create(['zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE, 'status' => 'pending']);

    $notification = makeDirectNotification();
    $job = runDirectJob($notification);

    $job->assertNotFailed();
    $job->assertNotReleased();

    $notification->refresh();
    expect($notification->route)->toBe(NotificationRoute::Batch)
        ->and($notification->batch_id)->toBe($existingBatch->id)
        ->and($notification->processed)->toBeFalse()
        ->and(DocumentSync::count())->toBe(0)
        ->and(app(BatchingService::class)->hasActiveTimer($existingBatch))->toBeTrue();

    // The document itself is not fetched when the zaak is missing.
    Http::assertNotSent(fn (Request $request) => $request->url() === DIRECT_EIO_URL);
});

test('a document already sent for the same zaak is not sent again', function () {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldNotReceive('geefZaakDetails', 'voegZaakdocumentToe');

    DocumentSync::factory()->create([
        'document_url' => DIRECT_EIO_URL,
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'corsa_identificatie' => 'DOC-2026-0011',
    ]);

    $notification = makeDirectNotification();
    runDirectJob($notification)->assertNotFailed();

    expect($notification->fresh()->processed)->toBeTrue()
        ->and(DocumentSync::count())->toBe(1);
    Http::assertNotSent(fn (Request $request) => $request->url() === DIRECT_EIO_URL);
});

test('the direct job does nothing for a notification that is already processed or moved to the batch', function (array $attributes) {
    Http::fake();
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldNotReceive('geefZaakDetails', 'voegZaakdocumentToe');

    $notification = makeDirectNotification();
    $notification->update($attributes);

    runDirectJob($notification)->assertNotFailed();

    Http::assertNothingSent();
})->with([
    'processed' => [['processed' => true, 'processed_at' => now()]],
    'moved to the batch' => [['route' => NotificationRoute::Batch]],
]);

test('the direct job retries a temporary error and keeps the notification unprocessed', function (Closure $error) {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    $zaakdms->shouldReceive('voegZaakdocumentToe')->once()->andThrow($error());

    $notification = makeDirectNotification();
    $job = (new ProcessDocumentDirectly($notification))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(CorsaZaakdmsService::class), app(BatchingService::class)))
        ->toThrow(Exception::class);

    $job->assertNotFailed();
    expect($notification->fresh()->processed)->toBeFalse()
        ->and(DocumentSync::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'connection error' => [fn () => new ConnectionException('timed out')],
    'server error' => [fn () => new Illuminate\Http\Client\RequestException(
        new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(503))
    )],
    'request time-out' => [fn () => new Illuminate\Http\Client\RequestException(
        new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(408))
    )],
    'rate limited' => [fn () => new Illuminate\Http\Client\RequestException(
        new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(429))
    )],
]);

test('the direct job fails without a retry on a client error from the ZGW APIs', function () {
    Http::fake([DIRECT_ZIO_URL => Http::response('', 404)]);
    $this->mock(Zaakdms::class)->shouldNotReceive('voegZaakdocumentToe');

    $notification = makeDirectNotification();
    $job = runDirectJob($notification);

    $job->assertFailed();
    expect($notification->fresh()->processed)->toBeFalse();
});

test('the direct job retries a temporary error from the ZGW APIs', function () {
    Http::fake([DIRECT_ZIO_URL => Http::response('', 502)]);
    $this->mock(Zaakdms::class)->shouldNotReceive('voegZaakdocumentToe');

    $job = (new ProcessDocumentDirectly(makeDirectNotification()))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(CorsaZaakdmsService::class), app(BatchingService::class)))
        ->toThrow(Woweb\Zgw\Exceptions\ApiRequestException::class);

    $job->assertNotFailed();
});

test('the direct job fails without a retry on a permanent error', function (Closure $error) {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    $zaakdms->shouldReceive('voegZaakdocumentToe')->once()->andThrow($error());

    $notification = makeDirectNotification();
    $job = runDirectJob($notification);

    $job->assertFailed();
    expect($notification->fresh()->processed)->toBeFalse()
        ->and($notification->fresh()->route)->toBe(NotificationRoute::Direct)
        ->and(DocumentSync::count())->toBe(0);
})->with([
    'validation error' => [fn () => new InvalidZaakdmsArgumentsException('Not all Zaakdms options are set')],
    'error message from the document management system' => [fn () => new Exception('Fo02 refused')],
    'client error' => [fn () => new Illuminate\Http\Client\RequestException(
        new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(400))
    )],
]);

// The fourth temporary error failing the job is covered under the queue worker in QueueWorkerTest.

test('the direct job runs on the direct queue with three retries after 30 seconds, 2 minutes and 5 minutes', function () {
    $job = new ProcessDocumentDirectly(makeDirectNotification());

    expect($job->queue)->toBe('direct')
        ->and(ProcessDocumentDirectly::ERROR_ATTEMPTS)->toBe(4)
        ->and($job->backoff())->toBe([30, 120, 300])
        ->and($job->tags())->toBe(['zaak:'.DIRECT_ZAAK_IDENTIFICATIE, 'route:direct']);
});

test('the queue worker takes jobs from the direct queue as well as the default queue', function () {
    expect(config('horizon.defaults.supervisor-1.queue'))->toBe(['direct', 'default']);
});

// ─── Placeholder ─────────────────────────────────────────────────────────────

test('a document in_bewerking without content gets a placeholder in the document management system', function (array $document, string $content) {
    fakeDirectApis(directDocument($document), $content);
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    captureDirectUpload($zaakdms, $options, $uploaded);

    $notification = makeDirectNotification();
    runDirectJob($notification)->assertNotFailed();

    expect($uploaded)->toBe('Tijdelijk bestand')
        ->and($options['file'])->toBe('zaakdms-temp/'.$notification->id.'/tijdelijk-bestand.txt')
        ->and($options['titel'])->toBe('Brief')
        ->and($options['status'])->toBe('in_bewerking')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    expect(DocumentSync::sole()->placeholder)->toBeTrue()
        ->and($notification->fresh()->processed)->toBeTrue();
})->with([
    'no inhoud url' => [['inhoud' => null, 'bestandsnaam' => '', 'formaat' => ''], ''],
    'empty download' => [[], ''],
    'bestandsdelen missing from the response' => [['inhoud' => null, 'bestandsdelen' => null], ''],
]);

test('a document without content is still refused when it is not in_bewerking or its upload is not complete', function (array $document, string $content, string $message) {
    fakeDirectApis(directDocument($document), $content);
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    $zaakdms->shouldNotReceive('voegZaakdocumentToe');

    expect(fn () => app(CorsaZaakdmsService::class)->addDocumentToZaak(makeDirectNotification()))
        ->toThrow(RuntimeException::class, $message);

    expect(DocumentSync::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'definitief without inhoud url' => [['status' => 'definitief', 'inhoud' => null], '', 'Missing inhoud url for document download'],
    'definitief with an empty download' => [['status' => 'definitief'], '', 'Document content is empty'],
    'in_bewerking with bestandsdelen and no inhoud url' => [
        ['inhoud' => null, 'bestandsdelen' => [['url' => DIRECT_EIO_URL.'/bestandsdelen/1']]],
        '',
        'Missing inhoud url for document download',
    ],
    'in_bewerking with bestandsdelen and an empty download' => [
        ['bestandsdelen' => [['url' => DIRECT_EIO_URL.'/bestandsdelen/1']]],
        '',
        'Document content is empty',
    ],
]);

test('a document in_bewerking with content is sent with its content, not a placeholder', function () {
    fakeDirectApis(directDocument(), 'concept content');
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    captureDirectUpload($zaakdms, $options, $uploaded);

    runDirectJob(makeDirectNotification())->assertNotFailed();

    expect($uploaded)->toBe('concept content')
        ->and($options['file'])->toEndWith('/brief.pdf')
        ->and(DocumentSync::sole()->placeholder)->toBeFalse();
});

// ─── The register on the batch route ─────────────────────────────────────────

test('the batch route registers the document it adds', function () {
    fakeDirectApis(directDocument(['status' => 'definitief', 'versie' => 2]));
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    $zaakdms->shouldReceive('voegZaakdocumentToe')->once()->andReturn('DOC-REF-0011');

    $notification = Notification::factory()->create([
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'notification' => directOpenNotification()->toArray(),
    ]);

    app(CorsaZaakdmsService::class)->processDocumentAangemaakt($notification);

    expect(DocumentSync::sole()->only(['document_url', 'zaak_identificatie', 'corsa_identificatie', 'versie', 'placeholder']))->toBe([
        'document_url' => DIRECT_EIO_URL,
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'corsa_identificatie' => 'DOC-2026-0011',
        'versie' => 2,
        'placeholder' => false,
    ]);
});

test('the batch route does not add a document that the direct route already added', function () {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldNotReceive('geefZaakDetails', 'voegZaakdocumentToe');

    DocumentSync::factory()->create([
        'document_url' => DIRECT_EIO_URL,
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
    ]);

    $notification = Notification::factory()->create([
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'notification' => directOpenNotification()->toArray(),
    ]);

    app(CorsaZaakdmsService::class)->processDocumentAangemaakt($notification);

    expect(DocumentSync::count())->toBe(1);
});

test('a document registered for another zaak is still added and keeps its first registration', function () {
    fakeDirectApis(directDocument(['status' => 'definitief']));
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    $zaakdms->shouldReceive('voegZaakdocumentToe')->once()->andReturn('DOC-REF-0011');

    DocumentSync::factory()->create([
        'document_url' => DIRECT_EIO_URL,
        'zaak_identificatie' => 'ZAAK-2026-0099',
        'corsa_identificatie' => 'DOC-2026-0011',
    ]);

    $notification = Notification::factory()->create([
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'notification' => directOpenNotification()->toArray(),
    ]);

    app(CorsaZaakdmsService::class)->processDocumentAangemaakt($notification);

    expect(DocumentSync::sole()->zaak_identificatie)->toBe('ZAAK-2026-0099');
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'Document was already sent to Corsa for another zaak')
        ->once();
});

test('the batch route still throws when the zaak is not in the document management system', function () {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->once()->andThrow(new Exception('not found'));
    $zaakdms->shouldNotReceive('voegZaakdocumentToe');

    $notification = Notification::factory()->create([
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'notification' => directOpenNotification()->toArray(),
    ]);

    expect(fn () => app(CorsaZaakdmsService::class)->processDocumentAangemaakt($notification))
        ->toThrow(RuntimeException::class, 'Missing zaak in Corsa for partial update');

    expect(DocumentSync::count())->toBe(0);
});

// ─── End to end on the sync queue ────────────────────────────────────────────

test('a document linked while in_bewerking reaches the document management system without a batch', function () {
    activeDirectMapping();
    fakeDirectApis(directDocument(['inhoud' => null]));
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->once()->andReturn(corsaDirectZaakDetails());
    $zaakdms->shouldReceive('voegZaakdocumentToe')->once()->andReturn('DOC-REF-0011');

    // The test environment runs queued jobs synchronously.
    ingestDirect(directOpenNotification());

    $notification = Notification::sole();
    expect($notification->route)->toBe(NotificationRoute::Direct)
        ->and($notification->processed)->toBeTrue()
        ->and(Batch::count())->toBe(0)
        ->and(DocumentSync::sole()->placeholder)->toBeTrue();
});

test('a direct notification is not lost when moving it to the batch fails halfway', function () {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->twice()->andThrow(new Exception('not found'));
    $zaakdms->shouldNotReceive('voegZaakdocumentToe');

    $notification = makeDirectNotification();

    // The first attempt fails while linking the notification to a batch.
    $failingBatching = Mockery::mock(BatchingService::class);
    $failingBatching->shouldReceive('getOrCreateBatch')->andThrow(new RuntimeException('database unavailable'));
    $first = (new ProcessDocumentDirectly($notification))->withFakeQueueInteractions();

    expect(fn () => $first->handle(app(CorsaZaakdmsService::class), $failingBatching))
        ->toThrow(RuntimeException::class, 'database unavailable');

    expect($notification->fresh()->route)->toBe(NotificationRoute::Direct)
        ->and($notification->fresh()->batch_id)->toBeNull();

    // The retry moves it to the batch of the zaak.
    runDirectJob($notification)->assertNotFailed();

    $notification->refresh();
    $batch = Batch::sole();
    expect($notification->route)->toBe(NotificationRoute::Batch)
        ->and($notification->batch_id)->toBe($batch->id)
        ->and(app(BatchingService::class)->hasActiveTimer($batch))->toBeTrue();
});

// ─── Two jobs for the same document at the same time ─────────────────────────
//
// The second job runs inside the upload of the first, so it starts after the first passed the
// register check and before the first registered the document. Without the lock per document
// both send the document. A real second worker cannot be started in the test process, so this
// interleaving stands in for it; the lock itself is the cache lock of the framework.

test('a second delivery of the same document while the first is being sent does not send it again', function () {
    fakeDirectApis(directDocument());
    $first = makeDirectNotification();
    $second = makeDirectNotification();
    $secondJob = null;

    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    $uploads = 0;
    $zaakdms->shouldReceive('voegZaakdocumentToe')
        ->andReturnUsing(function () use ($second, &$secondJob, &$uploads) {
            if (++$uploads === 1) {
                $secondJob = runDirectJob($second);
            }

            return 'DOC-REF-0011';
        });

    runDirectJob($first)->assertNotFailed();

    expect($uploads)->toBe(1);

    $secondJob->assertReleased(ProcessDocumentDirectly::LOCK_RETRY_SECONDS);
    $secondJob->assertNotFailed();
    expect($first->fresh()->processed)->toBeTrue()
        ->and($second->fresh()->processed)->toBeFalse()
        ->and(DocumentSync::count())->toBe(1);

    // Tried again after the first job is done: the register has the document.
    runDirectJob($second)->assertNotFailed();

    expect($second->fresh()->processed)->toBeTrue()
        ->and(DocumentSync::count())->toBe(1);
});

test('the batch route waits for a direct job that is sending the same document', function () {
    fakeDirectApis(directDocument());
    $direct = makeDirectNotification();
    $batched = Notification::factory()->create([
        'zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE,
        'notification' => directOpenNotification()->toArray(),
    ]);
    $batchJob = null;

    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    $uploads = 0;
    $zaakdms->shouldReceive('voegZaakdocumentToe')
        ->andReturnUsing(function () use ($batched, &$batchJob, &$uploads) {
            if (++$uploads === 1) {
                $batchJob = (new HandleNotification($batched))->withFakeQueueInteractions();
                $batchJob->handle(app(CorsaZaakdmsService::class));
            }

            return 'DOC-REF-0011';
        });

    runDirectJob($direct)->assertNotFailed();

    expect($uploads)->toBe(1);

    $batchJob->assertReleased(HandleNotification::LOCK_RETRY_SECONDS);
    $batchJob->assertNotFailed();
    expect($batched->fresh()->processed)->toBeFalse();

    $retry = (new HandleNotification($batched->fresh()))->withFakeQueueInteractions();
    $retry->handle(app(CorsaZaakdmsService::class));

    $retry->assertNotFailed();
    expect($batched->fresh()->processed)->toBeTrue()
        ->and(DocumentSync::count())->toBe(1);
});

test('the direct job does not contact the document management system while the document is locked', function () {
    fakeDirectApis(directDocument());
    $this->mock(Zaakdms::class)->shouldNotReceive('geefZaakDetails', 'voegZaakdocumentToe');

    $lock = Cache::lock(CorsaZaakdmsService::documentLockKey(DIRECT_EIO_URL), CorsaZaakdmsService::DOCUMENT_LOCK_SECONDS);
    expect($lock->get())->toBeTrue();

    $notification = makeDirectNotification();
    $job = runDirectJob($notification);

    $job->assertReleased(ProcessDocumentDirectly::LOCK_RETRY_SECONDS);
    $job->assertNotFailed();
    expect($notification->fresh()->processed)->toBeFalse()
        ->and($notification->fresh()->route)->toBe(NotificationRoute::Direct);
});

test('the lock for a document is released when sending it fails', function () {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->andReturn(corsaDirectZaakDetails());
    $zaakdms->shouldReceive('voegZaakdocumentToe')->once()->andThrow(new Exception('Fo02 refused'));

    runDirectJob(makeDirectNotification())->assertFailed();

    expect(Cache::lock(CorsaZaakdmsService::documentLockKey(DIRECT_EIO_URL), 1)->get())->toBeTrue();
});

test('both jobs are configured to wait for the lock without counting it as an error', function () {
    $this->freezeTime();
    $direct = new ProcessDocumentDirectly(makeDirectNotification());
    $batch = new HandleNotification(makeDirectNotification());

    expect($direct->retryUntil()->getTimestamp())->toBe(now()->addMinutes(ProcessDocumentDirectly::RETRY_MINUTES)->getTimestamp())
        ->and(property_exists($direct, 'tries'))->toBeFalse()
        ->and($direct->maxExceptions)->toBe(ProcessDocumentDirectly::ERROR_ATTEMPTS)
        ->and($batch->tries)->toBe(20)
        ->and($batch->maxExceptions)->toBe(1)
        ->and($batch->failOnTimeout)->toBeTrue()
        ->and($batch->tries * HandleNotification::LOCK_RETRY_SECONDS)->toBeGreaterThanOrEqual(CorsaZaakdmsService::DOCUMENT_LOCK_SECONDS);
});

// ─── Linking to a batch while it may be locked ───────────────────────────────
//
// Finding the open batch and linking the notification to it happen in one transaction, so on
// PostgreSQL the row lock of getOrCreateBatch() keeps the batch from being locked for processing
// in between. SQLite has no row locks, so these tests only show that both steps share the
// transaction.

/**
 * Bind a batching service that records the transaction level at which the open batch is found and
 * the notification is linked.
 *
 * @param  array<string, int>  $levels
 */
function recordBatchingTransactionLevels(array &$levels): void
{
    $real = new BatchingService;
    $recording = Mockery::mock(BatchingService::class)->makePartial();
    $recording->shouldReceive('getOrCreateBatch')->andReturnUsing(function (string $zaakIdentificatie) use (&$levels, $real) {
        $levels['find'] = DB::transactionLevel();

        return $real->getOrCreateBatch($zaakIdentificatie);
    });
    $recording->shouldReceive('addNotificationToBatch')->andReturnUsing(function (Notification $notification, Batch $batch) use (&$levels, $real) {
        $levels['link'] = DB::transactionLevel();
        $real->addNotificationToBatch($notification, $batch);
    });

    app()->instance(BatchingService::class, $recording);
}

test('a notification for the batch is linked in the same transaction that finds the open batch', function () {
    Queue::fake();
    activeDirectMapping();
    fakeDirectApis(directDocument(['status' => 'definitief']));
    $levels = [];
    recordBatchingTransactionLevels($levels);
    $outside = DB::transactionLevel();

    ingestDirect(directOpenNotification());

    expect($levels['find'])->toBe($outside + 1)
        ->and($levels['link'])->toBe($outside + 1)
        ->and(Notification::sole()->batch_id)->toBe(Batch::sole()->id);
});

test('a direct notification that moves to the batch is linked in the same transaction that finds the open batch', function () {
    fakeDirectApis(directDocument());
    $zaakdms = $this->mock(Zaakdms::class);
    $zaakdms->shouldReceive('geefZaakDetails')->once()->andThrow(new Exception('not found'));
    $levels = [];
    recordBatchingTransactionLevels($levels);
    $outside = DB::transactionLevel();

    $notification = makeDirectNotification();
    runDirectJob($notification)->assertNotFailed();

    // The link itself is part of that transaction; the call afterwards only starts the timer.
    expect($levels['find'])->toBe($outside + 1)
        ->and($notification->fresh()->batch_id)->toBe(Batch::sole()->id);
});

test('a notification is not linked to a batch that was locked after it was found', function () {
    Queue::fake();
    activeDirectMapping();
    fakeDirectApis(directDocument(['status' => 'definitief']));
    $locked = Batch::create(['zaak_identificatie' => DIRECT_ZAAK_IDENTIFICATIE, 'status' => 'pending']);

    // The batch is locked before the open batch is looked up, the order the row lock enforces.
    app(BatchingService::class)->processBatch($locked);
    ingestDirect(directOpenNotification());

    $notification = Notification::sole();
    expect($notification->batch_id)->not->toBe($locked->id)
        ->and($notification->batch->isLocked())->toBeFalse();
});
