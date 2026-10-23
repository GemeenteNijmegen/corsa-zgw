<?php

namespace App\Jobs\Notifications;

use App\Enums\NotificationIgnoreReason;
use App\Enums\NotificationRoute;
use App\Models\Notification;
use App\Models\ZaaktypeMapping;
use App\Services\BatchingService;
use App\Services\CorsaZaakdmsService;
use App\Services\Zgw\ZgwReader;
use App\ValueObjects\OpenNotification;
use App\ValueObjects\ZGW\Zaak;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Woweb\Zgw\Exceptions\DisallowedHostException;

class IngestNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(private OpenNotification $opennotification) {}

    public function displayName(): string
    {
        return "Ingest {$this->opennotification->actie}:{$this->opennotification->resource}";
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["action:{$this->opennotification->actie}:{$this->opennotification->resource}"];
    }

    /**
     * Execute the job.
     */
    public function handle(ZgwReader $zgw, BatchingService $batchingService): void
    {
        if (! $this->opennotification->isSupportedKanaal()) {
            $this->storeIgnored(NotificationIgnoreReason::UnsupportedChannel);

            return;
        }

        if (! $this->opennotification->isSupportedAction()) {
            // Ignored before fetching the zaak: after a destroy it no longer exists.
            $this->storeIgnored(NotificationIgnoreReason::UnsupportedAction);

            return;
        }

        if ($this->opennotification->isDocumentUpdate()) {
            // The hoofdObject of a change to a document is the document itself, not a zaak.
            $this->ingestDocumentUpdate($zgw);

            return;
        }

        // get the zaak from the hoofdobject URL
        try {
            $zaak = new Zaak(...$zgw->get($this->opennotification->hoofdObject));
        } catch (DisallowedHostException $e) {
            // A URL outside the configured ZGW hosts will never succeed, so fail without a retry.
            Log::warning('Notification refers to a host that is not a configured ZGW host', [
                'actie' => $this->opennotification->actie,
                'resource' => $this->opennotification->resource,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        $mapping = ZaaktypeMapping::where('zaaktype_url', $zaak->zaaktype)->first();

        if (! $mapping || ! $mapping->is_active) {
            Log::warning('Zaaktype not active or unknown, ignoring notification', [
                'zaaktype_url' => $zaak->zaaktype,
                'zaak_identificatie' => $zaak->identificatie,
                'actie' => $this->opennotification->actie,
            ]);

            // Keep the notification so it can be processed once the mapping is active.
            $this->storeIgnored(
                $mapping ? NotificationIgnoreReason::InactiveZaaktype : NotificationIgnoreReason::UnknownZaaktype,
                $zaak->identificatie,
            );

            return;
        }

        Log::info('Received notification', [
            'zaak_identificatie' => $zaak->identificatie,
            'actie' => $this->opennotification->actie,
        ]);

        if ($this->resolveRoute($zgw) === NotificationRoute::Direct) {
            $this->storeDirect($zaak->identificatie);

            return;
        }

        // Finding the open batch and linking the notification to it happen in one transaction, so
        // the batch cannot be locked for processing in between (see getOrCreateBatch()).
        [$batch, $notification] = DB::transaction(function () use ($batchingService, $zaak): array {
            $batch = $batchingService->getOrCreateBatch($zaak->identificatie);

            $notification = Notification::create([
                'zaak_identificatie' => $zaak->identificatie,
                'notification' => $this->opennotification->toArray(),
                'processed' => false,
            ]);

            // Add notification to batch and reset timer
            $batchingService->addNotificationToBatch($notification, $batch);

            return [$batch, $notification];
        });

        Log::info('Notification added to batch', [
            'notification_id' => $notification->id,
            'batch_id' => $batch->id,
            'zaak_identificatie' => $zaak->identificatie,
        ]);
    }

    /**
     * A change to a document goes to the document management system directly, for the one zaak
     * the document is linked to. Only zaken with an active mapping count. A document without a
     * link is ignored, because the link sends the newest version once it is made. A document
     * linked to more than one such zaak is ignored as well, because it is not known what the
     * document management system does with one document in two zaken.
     */
    private function ingestDocumentUpdate(ZgwReader $zgw): void
    {
        $documentUrl = $this->opennotification->hoofdObject;

        try {
            /** @var array<string, Zaak> $zaken keyed on the URL of the zaak */
            $zaken = [];

            foreach ($zgw->zaakinformatieobjecten($documentUrl) as $link) {
                $zaakUrl = $link['zaak'] ?? null;

                if (is_string($zaakUrl) && $zaakUrl !== '' && ! isset($zaken[$zaakUrl])) {
                    $zaken[$zaakUrl] = new Zaak(...$zgw->get($zaakUrl));
                }
            }
        } catch (DisallowedHostException $e) {
            // A URL outside the configured ZGW hosts will never succeed, so fail without a retry.
            Log::warning('Notification refers to a host that is not a configured ZGW host', [
                'actie' => $this->opennotification->actie,
                'resource' => $this->opennotification->resource,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        if ($zaken === []) {
            $this->storeIgnored(NotificationIgnoreReason::DocumentNotLinked);

            return;
        }

        $mappings = ZaaktypeMapping::whereIn('zaaktype_url', array_map(fn (Zaak $zaak) => $zaak->zaaktype, $zaken))
            ->get()
            ->keyBy('zaaktype_url');

        $active = array_values(array_filter(
            $zaken,
            fn (Zaak $zaak): bool => (bool) $mappings->get($zaak->zaaktype)?->is_active,
        ));

        if (count($active) > 1) {
            Log::warning('Document is linked to more than one zaak with an active mapping, ignoring the change', [
                'zaak_identificaties' => array_map(fn (Zaak $zaak) => $zaak->identificatie, $active),
                'actie' => $this->opennotification->actie,
            ]);

            $this->storeIgnored(NotificationIgnoreReason::DocumentInMultipleZaken);

            return;
        }

        if ($active === []) {
            $single = count($zaken) === 1 ? reset($zaken) : null;

            Log::warning('Zaaktype not active or unknown, ignoring notification', [
                'zaak_identificaties' => array_map(fn (Zaak $zaak) => $zaak->identificatie, array_values($zaken)),
                'actie' => $this->opennotification->actie,
            ]);

            $this->storeIgnored(
                $mappings->isNotEmpty() ? NotificationIgnoreReason::InactiveZaaktype : NotificationIgnoreReason::UnknownZaaktype,
                $single?->identificatie,
            );

            return;
        }

        Log::info('Received notification', [
            'zaak_identificatie' => $active[0]->identificatie,
            'actie' => $this->opennotification->actie,
        ]);

        $this->storeDirect($active[0]->identificatie);
    }

    /**
     * A document that is linked to a zaak while it is still being edited is processed directly,
     * so it is in the document management system without waiting for the batch of the zaak.
     * Everything else goes through the batch.
     */
    private function resolveRoute(ZgwReader $zgw): NotificationRoute
    {
        if ("{$this->opennotification->actie}:{$this->opennotification->resource}" !== 'create:zaakinformatieobject') {
            return NotificationRoute::Batch;
        }

        try {
            $zoiData = $zgw->get($this->opennotification->resourceUrl);
            $documentUrl = $zoiData['informatieobject'] ?? $zoiData['informatieObject'] ?? null;

            if (! $documentUrl) {
                return NotificationRoute::Batch;
            }

            $status = $zgw->get($documentUrl)['status'] ?? null;
        } catch (Exception $e) {
            // The batch fetches the same resources again and reports the error there.
            Log::warning('Could not determine the document status, using the batch', [
                'actie' => $this->opennotification->actie,
                'resource' => $this->opennotification->resource,
                'error' => get_class($e),
            ]);

            return NotificationRoute::Batch;
        }

        return $status === CorsaZaakdmsService::STATUS_IN_BEWERKING
            ? NotificationRoute::Direct
            : NotificationRoute::Batch;
    }

    /**
     * Store a notification on the direct route and queue it for processing, outside any batch.
     */
    private function storeDirect(string $zaakIdentificatie): void
    {
        $notification = Notification::create([
            'zaak_identificatie' => $zaakIdentificatie,
            'route' => NotificationRoute::Direct,
            'notification' => $this->opennotification->toArray(),
            'processed' => false,
        ]);

        ProcessDocumentDirectly::dispatch($notification);

        Log::info('Notification queued for direct processing', [
            'notification_id' => $notification->id,
            'zaak_identificatie' => $zaakIdentificatie,
        ]);
    }

    /**
     * Store a notification that is accepted but not processed, outside any batch.
     */
    private function storeIgnored(NotificationIgnoreReason $reason, ?string $zaakIdentificatie = null): void
    {
        $notification = Notification::create([
            'zaak_identificatie' => $zaakIdentificatie,
            'notification' => $this->opennotification->toArray(),
            'processed' => false,
            'ignored_at' => now(),
            'ignored_reason' => $reason,
        ]);

        Log::info('Notification stored as ignored', [
            'notification_id' => $notification->id,
            'reason' => $reason->value,
            'kanaal' => $this->opennotification->kanaal,
            'actie' => $this->opennotification->actie,
            'resource' => $this->opennotification->resource,
        ]);
    }
}
