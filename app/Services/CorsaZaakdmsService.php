<?php

namespace App\Services;

use App\Enums\DocumentSyncOutcome;
use App\Exceptions\DocumentLockedException;
use App\Models\DocumentSync;
use App\Models\Notification;
use App\Models\ZaaktypeMapping;
use App\Services\Zgw\ZgwReader;
use App\ValueObjects\ZGW\Zaak;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use Woweb\Zaakdms\Helper;
use Woweb\Zaakdms\Zaakdms;

class CorsaZaakdmsService
{
    private const DEFAULT_AUTEUR = 'OpenZaak';

    private const DEFAULT_VERTRAUWELIJK_AANDUIDING = 'zaakvertrouwelijk';

    private const TEMP_DISK = 'local';

    private const TEMP_DIR = 'zaakdms-temp';

    /** Document status of a document that is still being edited. */
    public const STATUS_IN_BEWERKING = 'in_bewerking';

    /** Sent instead of the content of a document that has none yet. */
    private const PLACEHOLDER_CONTENT = 'Tijdelijk bestand';

    private const PLACEHOLDER_FILENAME = 'tijdelijk-bestand.txt';

    /**
     * How long the lock for one document lasts at most. Equal to the job time-out of the queue
     * worker in production (config/horizon.php), so a worker that is stopped halfway does not keep
     * the document locked for longer than a job could run.
     */
    public const DOCUMENT_LOCK_SECONDS = 300;

    public function __construct(
        private readonly ZgwReader $zgw,
        private readonly Zaakdms $zaakdms
    ) {}

    public function processZaakAangemaakt(Notification $notification): void
    {
        $zaakUrl = $this->resolveZaakUrlFromNotification($notification);
        if (! $zaakUrl) {
            Log::warning('Missing zaak url for create notification', [
                'notification_id' => $notification->id,
                'actie' => $notification->notification['actie'] ?? null,
                'resource' => $notification->notification['resource'] ?? null,
            ]);
            throw new RuntimeException('Missing zaak url for create notification');
        }

        Log::info('Corsa create zaak start', [
            'notification_id' => $notification->id,
            'zaak_url' => $zaakUrl,
        ]);

        $zaak = $this->fetchZaak($zaakUrl);
        $zaaktype = $this->fetchZaaktype($zaak->zaaktype);

        // Check if zaak already exists in Corsa
        try {
            $existingZaak = $this->zaakdms->geefZaakDetails($zaak->identificatie);

            if ($existingZaak && Helper::findInObject($existingZaak['response'], 'zknidentificatie') === $zaak->identificatie) {
                Log::info('Zaak already exists in Corsa, skipping creation', [
                    'notification_id' => $notification->id,
                    'zaak_identificatie' => $zaak->identificatie,
                    'zaak_url' => $zaakUrl,
                ]);

                return;
            }
        } catch (\Exception $e) {
            // If zaak doesn't exist, ophalenZaak might throw an exception
            // Continue with creation
            Log::debug('Zaak does not exist in Corsa, proceeding with creation', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $zaak->identificatie,
            ]);
        }

        $options = $this->mapZaakToCorsaCreateOptions($zaak, $zaaktype);

        Log::debug('Corsa create zaak payload mapped', [
            'notification_id' => $notification->id,
            'zaak_identificatie' => $zaak->identificatie,
            'zaaktype_omschrijving' => $options['zaaktype']['omschrijving'] ?? null,
            'zaaktype_code' => $options['zaaktype']['code'] ?? null,
        ]);

        $reference = $this->zaakdms->creeerZaak($options);

        Log::info('Corsa create zaak done', [
            'notification_id' => $notification->id,
            'zaak_identificatie' => $zaak->identificatie,
            'corsa_reference' => $reference,
        ]);
    }

    // only status update for now, but can be extended with other partial updates if needed
    public function processZaakPartialUpdate(Notification $notification): void
    {
        $statusUrl = $notification->notification['resourceUrl'] ?? null;
        if (! $statusUrl) {
            Log::warning('Missing status url for partial update', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);
            throw new RuntimeException('Missing status url for partial update');
        }

        Log::info('Corsa partial update start', [
            'notification_id' => $notification->id,
            'status_url' => $statusUrl,
        ]);

        $statusData = $this->zgw->get($statusUrl);
        $statusType = $this->resolveStatustype($statusData);

        Log::debug('Corsa partial update status resolved', [
            'notification_id' => $notification->id,
            'status_uuid' => $statusData['uuid'] ?? null,
            'statustype_omschrijving' => Arr::get($statusType, 'omschrijving'),
        ]);

        $zaakUrl = $statusData['zaak'] ?? $this->resolveZaakUrlFromNotification($notification);
        if (! $zaakUrl) {
            Log::warning('Missing zaak url for partial update', [
                'notification_id' => $notification->id,
            ]);
            throw new RuntimeException('Missing zaak url for partial update');
        }

        $zaak = $this->fetchZaak($zaakUrl);

        if (! $this->checkZaakExistenceInCorsa($zaak->identificatie, $notification)) {
            Log::warning('Missing zaak in Corsa for partial update', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $zaak->identificatie,
            ]);
            throw new RuntimeException('Missing zaak in Corsa for partial update');
        }

        $response = $this->zaakdms->actualiseerZaakstatus([
            'identificatie' => $zaak->identificatie,
            'volgnummer' => $statusType['volgnummer'],
            'omschrijving' => Arr::get($statusType, 'omschrijving') ?? 'Status update',
            'gebruikerIdentificatie' => 'CorsaZaakdmsService',
        ]);

        if ($response) {
            Log::info('Corsa partial update done', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $zaak->identificatie,
                'corsa_reference' => $response,
            ]);
        }
    }

    public function processDocumentAangemaakt(Notification $notification): void
    {
        if ($this->addDocumentToZaak($notification) === DocumentSyncOutcome::ZaakNotInCorsa) {
            throw new RuntimeException('Missing zaak in Corsa for partial update');
        }
    }

    /**
     * Add the document of a create:zaakinformatieobject notification to its zaak.
     *
     * A document that was already sent for the same zaak is not sent again. When the zaak is not in
     * the document management system yet, nothing is sent and the caller decides what to do.
     */
    public function addDocumentToZaak(Notification $notification): DocumentSyncOutcome
    {
        $zoiUrl = $notification->notification['resourceUrl'] ?? null;
        if (! $zoiUrl) {
            Log::warning('Missing zaakinformatieobject url for document create', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);
            throw new RuntimeException('Missing zaakinformatieobject url for document create');
        }

        Log::info('Corsa document create start', [
            'notification_id' => $notification->id,
            'zoi_url' => $zoiUrl,
        ]);

        $zoiData = $this->zgw->get($zoiUrl);
        $documentUrl = $zoiData['informatieobject'] ?? $zoiData['informatieObject'] ?? null;

        if (! $documentUrl) {
            Log::warning('Missing informatieobject url for document create', [
                'notification_id' => $notification->id,
                'zoi_url' => $zoiUrl,
            ]);
            throw new RuntimeException('Missing informatieobject url for document create');
        }

        // Two jobs for the same document (a repeated delivery, or the direct route and the batch
        // route at the same time) must not both pass the register check before either has
        // registered the document, or the document is sent twice.
        $lock = Cache::lock(self::documentLockKey($documentUrl), self::DOCUMENT_LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('Document is being sent by another job, trying again later', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);

            throw new DocumentLockedException;
        }

        try {
            return $this->sendDocumentToZaak($notification, $documentUrl);
        } finally {
            $lock->release();
        }
    }

    /**
     * Cache key of the lock for one document, keyed on its URL in the Documenten API.
     */
    public static function documentLockKey(string $documentUrl): string
    {
        return 'document-sync:'.hash('sha256', $documentUrl);
    }

    /**
     * Check the register and the zaak, send the document and register it. Runs while holding the
     * lock for the document.
     */
    private function sendDocumentToZaak(Notification $notification, string $documentUrl): DocumentSyncOutcome
    {
        $sync = DocumentSync::where('document_url', $documentUrl)->first();

        if ($sync && $sync->zaak_identificatie === $notification->zaak_identificatie) {
            Log::info('Document already sent to Corsa for this zaak, skipping', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
                'document_identificatie' => $sync->corsa_identificatie,
            ]);

            return DocumentSyncOutcome::AlreadySynced;
        }

        if (! $this->checkZaakExistenceInCorsa($notification->zaak_identificatie, $notification)) {
            Log::warning('Missing zaak in Corsa for document create', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);

            return DocumentSyncOutcome::ZaakNotInCorsa;
        }

        $documentData = $this->zgw->get($documentUrl);
        $documentTypeData = $this->resolveDocumentType($documentData);

        $documentIdentificatie = $documentData['identificatie'] ?? $documentData['uuid'] ?? null;
        if (! $documentIdentificatie) {
            $documentIdentificatie = $this->zaakdms->genereerDocumentIdentificatie();
        }

        $placeholderAllowed = $this->mayUsePlaceholder($documentData);

        $inhoudUrl = $documentData['inhoud'] ?? null;
        if (! $inhoudUrl && ! $placeholderAllowed) {
            Log::warning('Missing inhoud url for document download', [
                'notification_id' => $notification->id,
                'document_url' => $documentUrl,
            ]);
            throw new RuntimeException('Missing inhoud url for document download');
        }

        $content = $inhoudUrl ? $this->downloadDocument($inhoudUrl, $notification) : '';
        $placeholder = $content === '';

        if ($placeholder && ! $placeholderAllowed) {
            Log::warning('Downloaded document content is empty', [
                'notification_id' => $notification->id,
                'inhoud_url' => $inhoudUrl,
            ]);
            throw new RuntimeException('Document content is empty');
        }

        if ($placeholder) {
            Log::info('Document has no content yet, sending a placeholder', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
                'document_identificatie' => $documentIdentificatie,
            ]);
        }

        $bestandsnaam = $documentData['bestandsnaam']
            ?? $documentData['titel']
            ?? ('document-'.$documentIdentificatie.'.bin');

        $tempPath = $placeholder
            ? $this->writeTempDocument(self::PLACEHOLDER_FILENAME, self::PLACEHOLDER_CONTENT, $notification)
            : $this->writeTempDocument($bestandsnaam, $content, $notification);

        try {
            $options = $this->mapDocumentOptions(
                $documentData,
                $documentTypeData,
                $documentIdentificatie,
                $bestandsnaam,
                $tempPath,
                $notification->zaak_identificatie,
            );

            $reference = $this->zaakdms->voegZaakdocumentToe($options);

            Log::info('Corsa document create done', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
                'document_identificatie' => $documentIdentificatie,
                'placeholder' => $placeholder,
                'corsa_reference' => $reference,
            ]);
        } finally {
            Storage::disk(self::TEMP_DISK)->delete($tempPath);
            Log::info('Temp file deleted', [
                'path' => $tempPath,
            ]);
        }

        $this->recordDocumentSync($sync, $documentUrl, $notification, $documentIdentificatie, $documentData, $placeholder);

        return DocumentSyncOutcome::Added;
    }

    /**
     * Send the newest version of a changed document to the document management system.
     *
     * Used by the direct route and by the batch route, for an update or partial_update on
     * enkelvoudiginformatieobject. Nothing is sent when the document management system already has
     * this version, when the content is still being uploaded in parts, when the document is still
     * being edited without content (the placeholder stays), or when the document is not in the
     * document management system yet. The caller decides what to do with the last two.
     */
    public function updateDocument(Notification $notification): DocumentSyncOutcome
    {
        // The hoofdObject of a change is the document. The zaak was chosen on ingest from the same
        // URL, so the document that is sent is always the one whose zaak was looked up.
        $documentUrl = $notification->notification['hoofdObject'] ?? null;

        if (! $documentUrl) {
            Log::warning('Missing document url for document update', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);
            throw new RuntimeException('Missing document url for document update');
        }

        // The same lock as for adding a document, so an update never overlaps with the add of the
        // same document or with another update of it.
        $lock = Cache::lock(self::documentLockKey($documentUrl), self::DOCUMENT_LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('Document is being sent by another job, trying again later', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);

            throw new DocumentLockedException;
        }

        try {
            return $this->sendDocumentUpdate($notification, $documentUrl);
        } finally {
            $lock->release();
        }
    }

    /**
     * Check the version, the upload and the register, send the update and register it. Runs while
     * holding the lock for the document.
     */
    private function sendDocumentUpdate(Notification $notification, string $documentUrl): DocumentSyncOutcome
    {
        // Without a version the newest one is returned.
        $documentData = $this->zgw->get($documentUrl);
        $versie = isset($documentData['versie']) ? (int) $documentData['versie'] : null;
        $sync = DocumentSync::where('document_url', $documentUrl)->first();

        if ($sync && $sync->versie !== null && $versie !== null && $versie <= $sync->versie) {
            Log::info('Document version already in Corsa, skipping', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $sync->zaak_identificatie,
                'versie' => $versie,
                'registered_versie' => $sync->versie,
            ]);

            return DocumentSyncOutcome::AlreadyUpToDate;
        }

        if (! empty($documentData['bestandsdelen'] ?? [])) {
            Log::info('Document content is still being uploaded, trying again later', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);

            return DocumentSyncOutcome::UploadIncomplete;
        }

        $zaakIdentificatie = $sync->zaak_identificatie ?? $notification->zaak_identificatie;
        $documentIdentificatie = $sync->corsa_identificatie
            ?? $documentData['identificatie']
            ?? $documentData['uuid']
            ?? null;

        if (! $sync && (! $documentIdentificatie || ! $this->isDocumentListedInCorsa($zaakIdentificatie, $documentIdentificatie, $notification))) {
            Log::info('Document not in Corsa yet for document update', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $zaakIdentificatie,
            ]);

            return DocumentSyncOutcome::DocumentNotInCorsa;
        }

        $inhoudUrl = $documentData['inhoud'] ?? null;
        $content = $inhoudUrl ? $this->downloadDocument($inhoudUrl, $notification) : '';

        if ($content === '') {
            if ($this->mayUsePlaceholder($documentData)) {
                // Still being edited without content: what is in Corsa (the placeholder) stays.
                Log::info('Document has no content yet, keeping what is in Corsa', [
                    'notification_id' => $notification->id,
                    'zaak_identificatie' => $zaakIdentificatie,
                    'document_identificatie' => $documentIdentificatie,
                ]);

                return DocumentSyncOutcome::PlaceholderKept;
            }

            Log::warning('Document content is empty or missing for document update', [
                'notification_id' => $notification->id,
                'document_url' => $documentUrl,
            ]);
            throw new RuntimeException($inhoudUrl ? 'Document content is empty' : 'Missing inhoud url for document download');
        }

        $documentTypeData = $this->resolveDocumentType($documentData);

        $bestandsnaam = $documentData['bestandsnaam']
            ?? $documentData['titel']
            ?? ('document-'.$documentIdentificatie.'.bin');

        $tempPath = $this->writeTempDocument($bestandsnaam, $content, $notification);

        try {
            $reference = $this->zaakdms->updateZaakDocument(
                $this->mapDocumentOptions($documentData, $documentTypeData, $documentIdentificatie, $bestandsnaam, $tempPath, $zaakIdentificatie)
            );

            Log::info('Corsa document update done', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $zaakIdentificatie,
                'document_identificatie' => $documentIdentificatie,
                'versie' => $versie,
                'corsa_reference' => $reference,
            ]);
        } finally {
            Storage::disk(self::TEMP_DISK)->delete($tempPath);
        }

        DocumentSync::updateOrCreate(
            ['document_url' => $documentUrl],
            [
                'zaak_identificatie' => $zaakIdentificatie,
                'corsa_identificatie' => $documentIdentificatie,
                'versie' => $versie,
                'placeholder' => false,
                'synced_at' => now(),
            ],
        );

        return DocumentSyncOutcome::Updated;
    }

    /**
     * Whether the list of documents of the zaak in the document management system contains a
     * document with this identificatie. Only used for a document without a register entry, such as
     * one that was added before the register existed.
     *
     * The list (geefLijstZaakdocumenten) returns the zaak object; each document is a related object
     * under heeftRelevant, with its identificatie under gerelateerde. That shape follows existing code
     * that reads this list from the same client, and the inverse relation isRelevantVoor in the
     * update message; it is not confirmed against a test environment yet.
     *
     * A connection error or a server error is passed on, so it is tried again. Any other error,
     * such as a fault message because the zaak is not there, counts as "not listed".
     */
    public function isDocumentListedInCorsa(string $zaakIdentificatie, string $documentIdentificatie, Notification $notification): bool
    {
        try {
            $zaakObject = $this->zaakdms->geefLijstZaakdocumenten($zaakIdentificatie);
        } catch (ConnectionException|RequestException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('Could not list the documents of the zaak in Corsa, treating the document as not listed', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $zaakIdentificatie,
                'error' => get_class($e),
            ]);

            return false;
        }

        foreach (self::listedDocumentIdentificaties($zaakObject) as $identificatie) {
            if ($identificatie === $documentIdentificatie) {
                return true;
            }
        }

        return false;
    }

    /**
     * The identificaties of the documents in the zaak object of a geefLijstZaakdocumenten answer,
     * with the namespace prefixes removed as the client does (zkn:heeftRelevant becomes
     * zknheeftRelevant).
     *
     * @return list<string>
     */
    public static function listedDocumentIdentificaties(SimpleXMLElement $zaakObject): array
    {
        $identificaties = [];

        foreach ($zaakObject->zknheeftRelevant as $relevant) {
            $identificatie = trim((string) $relevant->zkngerelateerde->zknidentificatie);

            if ($identificatie !== '') {
                $identificaties[] = $identificatie;
            }
        }

        return $identificaties;
    }

    public function processResultaatAangemaakt(Notification $notification): void
    {
        $resultaatUrl = $notification->notification['resourceUrl'] ?? null;
        if (! $resultaatUrl) {
            Log::warning('Missing resultaat url for resultaat notification', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);
            throw new RuntimeException('Missing resultaat url for resultaat notification');
        }

        Log::info('Corsa update zaak resultaat start', [
            'notification_id' => $notification->id,
            'resultaat_url' => $resultaatUrl,
        ]);

        $resultaatData = $this->zgw->get($resultaatUrl);
        $resultaattypeUrl = $resultaatData['resultaattype'] ?? null;
        $resultaattypeData = $resultaattypeUrl ? $this->zgw->get($resultaattypeUrl) : [];

        $zaakUrl = $resultaatData['zaak'] ?? $this->resolveZaakUrlFromNotification($notification);
        if (! $zaakUrl) {
            Log::warning('Missing zaak url for resultaat notification', [
                'notification_id' => $notification->id,
            ]);
            throw new RuntimeException('Missing zaak url for resultaat notification');
        }

        $zaak = $this->fetchZaak($zaakUrl);

        if (! $this->checkZaakExistenceInCorsa($zaak->identificatie, $notification)) {
            Log::warning('Missing zaak in Corsa for resultaat update', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $zaak->identificatie,
            ]);
            throw new RuntimeException('Missing zaak in Corsa for resultaat update');
        }

        $dateFormat = config('zaakdms.date_format', 'Ymd');
        $einddatum = $zaak->einddatum
            ? Carbon::parse($zaak->einddatum)->format($dateFormat)
            : Carbon::today()->format($dateFormat);

        $reference = $this->zaakdms->updateZaak([
            'identificatie' => $zaak->identificatie,
            'resultaat' => $resultaattypeData['omschrijving'] ?? null,
            'einddatum' => $einddatum,
        ]);

        Log::info('Corsa update zaak resultaat done', [
            'notification_id' => $notification->id,
            'zaak_identificatie' => $zaak->identificatie,
            'resultaattype_omschrijving' => $resultaattypeData['omschrijving'] ?? null,
            'einddatum' => $einddatum,
            'corsa_reference' => $reference,
        ]);
    }

    /**
     * The options for adding or updating a document in the document management system. The same
     * for both, so an update sends the same metadata as the add.
     *
     * @return array<string, mixed>
     */
    private function mapDocumentOptions(
        array $documentData,
        array $documentTypeData,
        string $documentIdentificatie,
        string $bestandsnaam,
        string $tempPath,
        string $zaakIdentificatie,
    ): array {
        return [
            'identificatie' => $documentIdentificatie,
            'documenttype' => $documentTypeData['omschrijving']
                ?? $documentTypeData['omschrijvingGeneriek']
                ?? 'Onbekend',
            'creatiedatum' => $this->formatZaakdmsDate($documentData['creatiedatum'] ?? null),
            'ontvangstdatum' => $this->formatZaakdmsDate($documentData['ontvangstdatum'] ?? null),
            'verzenddatum' => $this->formatZaakdmsDate($documentData['verzenddatum'] ?? null),
            'status' => $documentData['status'] ?? null,
            'titel' => $documentData['titel'] ?? $bestandsnaam,
            'beschrijving' => $documentData['beschrijving'] ?? null,
            'auteur' => $documentData['auteur'] ?? self::DEFAULT_AUTEUR,
            'vertrouwelijkAanduiding' => $this->mapVertrouwelijkAanduiding($documentData),
            'file' => $tempPath,
            'disk' => self::TEMP_DISK,
            'zaak' => [
                'identificatie' => $zaakIdentificatie,
            ],
        ];
    }

    private function fetchZaak(string $zaakUrl): Zaak
    {
        $expandedUrl = $this->addExpandToUrl($zaakUrl, [
            'rollen',
            'status',
        ]);

        Log::debug('Fetching zaak from ZGW', ['zaak_url' => $expandedUrl]);

        return new Zaak(...$this->zgw->get($expandedUrl));
    }

    private function fetchZaaktype(string $zaaktypeUrl): array
    {
        Log::debug('Fetching zaaktype from ZGW', ['zaaktype_url' => $zaaktypeUrl]);

        return $this->zgw->get($zaaktypeUrl);
    }

    private function resolveDocumentType(array $documentData): array
    {
        $documentTypeUrl = $documentData['informatieobjecttype'] ?? null;
        if (! $documentTypeUrl) {
            return [];
        }

        Log::debug('Fetching document type from ZGW', ['documenttype_url' => $documentTypeUrl]);

        return $this->zgw->get($documentTypeUrl);
    }

    private function resolveStatustype(array $statusData): array
    {
        if (isset($statusData['_expand']['statustype'])) {
            return $statusData['_expand']['statustype'];
        }

        $statusTypeUrl = $statusData['statustype'] ?? null;
        if (! $statusTypeUrl) {
            return [];
        }

        Log::debug('Fetching statustype from ZGW', ['statustype_url' => $statusTypeUrl]);

        return $this->zgw->get($statusTypeUrl);
    }

    private function mapZaakToCorsaCreateOptions(Zaak $zaak, array $zaaktype): array
    {
        $zaaktypeCode = $this->resolveZaaktypeCode($zaaktype);
        $initiator = $this->mapInitiator($zaak);

        $options = [
            'identificatie' => $zaak->identificatie,
            'omschrijving' => $zaak->omschrijving,
            'startdatum' => $this->formatZaakdmsDate($zaak->startdatum),
            'registratiedatum' => $this->formatZaakdmsDate($zaak->registratiedatum),
            'zaakniveau' => 1,
            'zaaktype' => [
                'omschrijving' => $zaaktype['omschrijving']
                    ?? $zaaktype['omschrijvingGeneriek']
                    ?? 'Onbekend',
                'code' => $zaaktypeCode,
            ],
        ];

        if ($initiator) {
            $options['initiator'] = $initiator;
        }

        return $options;
    }

    private function mapInitiator(Zaak $zaak): ?array
    {
        if (! $zaak->initiator) {
            Log::debug('No initiator found for zaak', [
                'zaak_identificatie' => $zaak->identificatie,
            ]);

            return null;
        }

        $betrokkeneType = $zaak->initiator->betrokkeneType;
        $identificatie = $zaak->initiator->betrokkeneIdentificatie;

        Log::debug('Mapping initiator', [
            'zaak_identificatie' => $zaak->identificatie,
            'betrokkene_type' => $betrokkeneType,
        ]);

        if ($betrokkeneType === 'natuurlijk_persoon') {
            return [
                'type' => 'natuurlijk_persoon',
                'natuurlijk_persoon' => [
                    'bsn' => $identificatie['inpBsn'] ?? null,
                    'identificatie' => $identificatie['anpIdentificatie'] ?? null,
                    'geslachtsnaam' => ($identificatie['geslachtsnaam'] ?? null) ?: 'onbekend',
                ],
            ];
        }

        if ($betrokkeneType === 'niet_natuurlijk_persoon') {
            return [
                'type' => 'niet_natuurlijk_persoon',
                'niet_natuurlijk_persoon' => [
                    'nnpId' => $identificatie['innNnpId'] ?? null,
                    'statutaireNaam' => $identificatie['statutaireNaam'] ?? null,
                ],
            ];
        }

        Log::warning('Unknown betrokkene type for initiator', [
            'zaak_identificatie' => $zaak->identificatie,
            'betrokkene_type' => $betrokkeneType,
        ]);

        return null;
    }

    private function resolveZaaktypeCode(array $zaaktype): string
    {
        $zaaktypeUrl = $zaaktype['url'] ?? null;

        if (! $zaaktypeUrl) {
            throw new RuntimeException('Cannot resolve zaaktype code: zaaktype url is missing');
        }

        $code = ZaaktypeMapping::where('zaaktype_url', $zaaktypeUrl)
            ->where('is_active', true)
            ->value('corsa_zaaktype_code');

        if (! $code) {
            throw new RuntimeException("Cannot resolve zaaktype code: no active mapping found for zaaktype url [{$zaaktypeUrl}]");
        }

        return $code;
    }

    private function formatZaakdmsDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        return Carbon::parse($date)->format(config('zaakdms.date_format', 'Ymd'));
    }

    private function addExpandToUrl(string $url, array $expand): string
    {
        if (! $expand || str_contains($url, 'expand=')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'expand='.implode(',', $expand);
    }

    private function resolveZaakUrlFromNotification(Notification $notification): ?string
    {
        return $notification->notification['resourceUrl']
            ?? $notification->notification['hoofdObject']
            ?? null;
    }

    private function mapVertrouwelijkAanduiding(array $documentData): string
    {
        $value = $documentData['vertrouwelijkheidaanduiding']
            ?? $documentData['vertrouwelijkAanduiding']
            ?? null;

        if (! $value) {
            return self::DEFAULT_VERTRAUWELIJK_AANDUIDING;
        }

        $normalized = Str::of($value)->lower()->replace(' ', '_')->value();

        $allowed = [
            'openbaar',
            'beperkt_openbaar',
            'intern',
            'zaakvertrouwelijk',
            'vertrouwelijk',
            'confidentieel',
            'geheim',
            'zeer_geheim',
        ];

        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        Log::warning('Unknown vertrouwelijkheidaanduiding, using default', [
            'value' => $value,
        ]);

        return self::DEFAULT_VERTRAUWELIJK_AANDUIDING;
    }

    /**
     * A document that is still being edited may be linked before it has any content, for example
     * when it is created from a template. Only then a placeholder is sent instead of the content.
     * A document with bestandsdelen is still being uploaded and does not qualify.
     */
    private function mayUsePlaceholder(array $documentData): bool
    {
        return ($documentData['status'] ?? null) === self::STATUS_IN_BEWERKING
            && empty($documentData['bestandsdelen'] ?? []);
    }

    private function downloadDocument(string $inhoudUrl, Notification $notification): string
    {
        Log::debug('Downloading document content from ZGW', [
            'notification_id' => $notification->id,
            'inhoud_url' => $inhoudUrl,
        ]);

        return $this->zgw->getRaw($inhoudUrl);
    }

    private function writeTempDocument(string $bestandsnaam, string $content, Notification $notification): string
    {
        $safeName = Str::slug(pathinfo($bestandsnaam, PATHINFO_FILENAME));
        $extension = pathinfo($bestandsnaam, PATHINFO_EXTENSION);
        $filename = $safeName ?: 'document-'.Str::uuid();

        if ($extension !== '') {
            $filename .= '.'.$extension;
        }

        $tempPath = self::TEMP_DIR.'/'.$notification->id.'/'.$filename;

        Storage::disk(self::TEMP_DISK)->put($tempPath, $content);

        Log::debug('Stored document content on disk', [
            'notification_id' => $notification->id,
            'disk' => self::TEMP_DISK,
            'path' => $tempPath,
        ]);

        return $tempPath;
    }

    /**
     * Remember what was sent for this document. A document that is already registered for
     * another zaak keeps that registration.
     */
    private function recordDocumentSync(
        ?DocumentSync $sync,
        string $documentUrl,
        Notification $notification,
        string $documentIdentificatie,
        array $documentData,
        bool $placeholder,
    ): void {
        if ($sync) {
            Log::warning('Document was already sent to Corsa for another zaak', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
                'registered_zaak_identificatie' => $sync->zaak_identificatie,
                'document_identificatie' => $documentIdentificatie,
            ]);

            return;
        }

        DocumentSync::create([
            'document_url' => $documentUrl,
            'zaak_identificatie' => $notification->zaak_identificatie,
            'corsa_identificatie' => $documentIdentificatie,
            'versie' => isset($documentData['versie']) ? (int) $documentData['versie'] : null,
            'placeholder' => $placeholder,
            'synced_at' => now(),
        ]);
    }

    public function checkZaakExistenceInCorsa(string $identificatie, Notification $notification): bool
    {
        $exists = false;
        try {
            $existingZaak = $this->zaakdms->geefZaakDetails($identificatie);

            if ($existingZaak && Helper::findInObject($existingZaak['response'], 'zknidentificatie') === $identificatie) {
                $exists = true;
            }
        } catch (\Exception $e) {
            // If zaak doesn't exist, ophalenZaak might throw an exception
            // Continue with creation
            Log::debug('Zaak does not exist in Corsa, stopping notification', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $identificatie,
            ]);

        }

        return $exists;
    }
}
