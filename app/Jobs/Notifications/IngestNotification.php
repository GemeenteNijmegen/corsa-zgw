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

        // Get or create batch for this case
        $batch = $batchingService->getOrCreateBatch($zaak->identificatie);

        // Create the notification record in the database
        $notification = Notification::create([
            'zaak_identificatie' => $zaak->identificatie,
            'notification' => $this->opennotification->toArray(),
            'processed' => false,
        ]);

        // Add notification to batch and reset timer
        $batchingService->addNotificationToBatch($notification, $batch);

        Log::info('Notification added to batch', [
            'notification_id' => $notification->id,
            'batch_id' => $batch->id,
            'zaak_identificatie' => $zaak->identificatie,
        ]);
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
