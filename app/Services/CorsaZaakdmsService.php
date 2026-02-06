<?php

namespace App\Services;

use App\Models\Notification;
use App\ValueObjects\ZGW\Zaak;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Woweb\Openzaak\Openzaak;
use Woweb\Zaakdms\Helper;
use Woweb\Zaakdms\Zaakdms;

class CorsaZaakdmsService
{
    private const DEFAULT_AUTEUR = 'OpenZaak';

    private const DEFAULT_VERTRAUWELIJK_AANDUIDING = 'zaakvertrouwelijk';

    private const TEMP_DISK = 'local';

    private const TEMP_DIR = 'zaakdms-temp';

    public function __construct(
        private readonly Openzaak $openzaak,
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

        $statusData = $this->openzaak->get($statusUrl)->toArray();
        // TODO value object of statustype with isEindstatus method
        $statusType = $this->resolveStatustype($statusData);
        $isEindstatus = (bool) Arr::get($statusType, 'isEindstatus', false);

        Log::debug('Corsa partial update status resolved', [
            'notification_id' => $notification->id,
            'status_uuid' => $statusData['uuid'] ?? null,
            'statustype_omschrijving' => Arr::get($statusType, 'omschrijving'),
            'is_eindstatus' => $isEindstatus,
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

        // TODO update resultaat if endstatus
    }

    public function processDocumentAangemaakt(Notification $notification): void
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

        $zoiData = $this->openzaak->get($zoiUrl)->toArray();
        $documentUrl = $zoiData['informatieobject'] ?? $zoiData['informatieObject'] ?? null;

        if (! $documentUrl) {
            Log::warning('Missing informatieobject url for document create', [
                'notification_id' => $notification->id,
                'zoi_url' => $zoiUrl,
            ]);
            throw new RuntimeException('Missing informatieobject url for document create');
        }

        if (! $this->checkZaakExistenceInCorsa($notification->zaak_identificatie, $notification)) {
            Log::warning('Missing zaak in Corsa for partial update', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
            ]);
            throw new RuntimeException('Missing zaak in Corsa for partial update');
        }

        $documentData = $this->openzaak->get($documentUrl)->toArray();
        $documentTypeData = $this->resolveDocumentType($documentData);

        $documentIdentificatie = $documentData['identificatie'] ?? $documentData['uuid'] ?? null;
        if (! $documentIdentificatie) {
            $documentIdentificatie = $this->zaakdms->genereerDocumentIdentificatie();
        }

        $inhoudUrl = $documentData['inhoud'] ?? null;
        if (! $inhoudUrl) {
            Log::warning('Missing inhoud url for document download', [
                'notification_id' => $notification->id,
                'document_url' => $documentUrl,
            ]);
            throw new RuntimeException('Missing inhoud url for document download');
        }

        $bestandsnaam = $documentData['bestandsnaam']
            ?? $documentData['titel']
            ?? ('document-'.$documentIdentificatie.'.bin');

        $tempPath = $this->writeTempDocument($bestandsnaam, $inhoudUrl, $notification);

        try {
            $options = [
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
                    'identificatie' => $notification->zaak_identificatie,
                ],
            ];

            $reference = $this->zaakdms->voegZaakdocumentToe($options);

            Log::info('Corsa document create done', [
                'notification_id' => $notification->id,
                'zaak_identificatie' => $notification->zaak_identificatie,
                'document_identificatie' => $documentIdentificatie,
                'corsa_reference' => $reference,
            ]);
        } finally {
            Storage::disk(self::TEMP_DISK)->delete($tempPath);
            Log::info('Temp file deleted', [
                'path' => $tempPath,
            ]);
        }
    }

    private function fetchZaak(string $zaakUrl): Zaak
    {
        $expandedUrl = $this->addExpandToUrl($zaakUrl, [
            'rollen',
            'status',
        ]);

        Log::debug('Fetching zaak from ZGW', ['zaak_url' => $expandedUrl]);

        return new Zaak(...$this->openzaak->get($expandedUrl)->toArray());
    }

    private function fetchZaaktype(string $zaaktypeUrl): array
    {
        Log::debug('Fetching zaaktype from ZGW', ['zaaktype_url' => $zaaktypeUrl]);

        return $this->openzaak->get($zaaktypeUrl)->toArray();
    }

    private function resolveDocumentType(array $documentData): array
    {
        $documentTypeUrl = $documentData['informatieobjecttype'] ?? null;
        if (! $documentTypeUrl) {
            return [];
        }

        Log::debug('Fetching document type from ZGW', ['documenttype_url' => $documentTypeUrl]);

        return $this->openzaak->get($documentTypeUrl)->toArray();
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

        return $this->openzaak->get($statusTypeUrl)->toArray();
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
        $identificatie = $zaak->initiator->betrokkeneIdentificatie ?? [];

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
                    'geslachtsnaam' => $identificatie['geslachtsnaam'] ? $identificatie['geslachtsnaam'] : 'onbekend',
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
        // TODO match zaaktype code based on configuration or mapping
        // For now, return fixed env value
        return config('app.fixed_corsa_zaaktype_code');
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

    private function writeTempDocument(string $bestandsnaam, string $inhoudUrl, Notification $notification): string
    {
        Log::debug('Downloading document content from ZGW', [
            'notification_id' => $notification->id,
            'inhoud_url' => $inhoudUrl,
        ]);

        $content = $this->openzaak->getRaw($inhoudUrl);
        if ($content === '') {
            Log::warning('Downloaded document content is empty', [
                'notification_id' => $notification->id,
                'inhoud_url' => $inhoudUrl,
            ]);
            throw new RuntimeException('Document content is empty');
        }

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
