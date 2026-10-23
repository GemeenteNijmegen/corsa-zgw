<?php

namespace App\Jobs\Notifications;

use App\Enums\NotificationIgnoreReason;
use App\Models\Notification;
use App\Services\CorsaZaakdmsService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Woweb\Zgw\Exceptions\DisallowedHostException;

class HandleNotification implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(private readonly Notification $notification) {}

    public function displayName(): string
    {
        $actie = $this->notification->notification['actie'] ?? 'unknown';
        $resource = $this->notification->notification['resource'] ?? 'unknown';

        return "Handle {$actie}:{$resource}";
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        $actie = $this->notification->notification['actie'] ?? 'unknown';
        $resource = $this->notification->notification['resource'] ?? 'unknown';

        return [
            "zaak:{$this->notification->zaak_identificatie}",
            "action:{$actie}:{$resource}",
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(CorsaZaakdmsService $corsaZaakdmsService): void
    {
        Log::info('Processing notification', [
            'notification_id' => $this->notification->id,
            'batch_id' => $this->notification->batch_id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
            'actie' => $this->notification->notification['actie'] ?? 'unknown',
            'resource' => $this->notification->notification['resource'] ?? 'unknown',
        ]);

        try {
            $actie = $this->notification->notification['actie'] ?? null;
            $resource = $this->notification->notification['resource'] ?? null;

            // Match on combination of actie and resource
            $actionKey = "{$actie}:{$resource}";

            $handler = match ($actionKey) {
                'create:zaak' => $this->handleZaakAangemaakt(...),
                'create:status' => $this->handleZaakPartialUpdate(...),
                'create:zaakinformatieobject' => $this->handleDocumentAangemaakt(...),
                'create:resultaat' => $this->handleResultaatAangemaakt(...),
                default => null,
            };

            if ($handler === null) {
                $this->ignoreUnsupportedAction();

                return;
            }

            $handler($corsaZaakdmsService);

            // Mark notification as processed
            $this->notification->update([
                'processed' => true,
                'processed_at' => now(),
            ]);

            Log::info('Notification processed successfully', [
                'notification_id' => $this->notification->id,
            ]);
        } catch (DisallowedHostException $e) {
            // A URL outside the configured ZGW hosts will never succeed, so fail without a retry.
            Log::warning('Notification refers to a host that is not a configured ZGW host', [
                'notification_id' => $this->notification->id,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);
        } catch (\Exception $e) {
            Log::error('Error processing notification', [
                'notification_id' => $this->notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle zaak aangemaakt (case created) notification
     */
    private function handleZaakAangemaakt(CorsaZaakdmsService $corsaZaakdmsService): void
    {
        Log::debug('Handling zaak aangemaakt', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);

        $corsaZaakdmsService->processZaakAangemaakt($this->notification);
    }

    /**
     * Handle zaak partially updated notification
     */
    private function handleZaakPartialUpdate(CorsaZaakdmsService $corsaZaakdmsService): void
    {
        Log::debug('Handling zaak partial update', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);

        $corsaZaakdmsService->processZaakPartialUpdate($this->notification);
    }

    /**
     * Handle document created notification
     */
    private function handleDocumentAangemaakt(CorsaZaakdmsService $corsaZaakdmsService): void
    {
        Log::debug('Handling document aangemaakt', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);

        $corsaZaakdmsService->processDocumentAangemaakt($this->notification);
    }

    /**
     * Handle resultaat aangemaakt (result created) notification
     */
    private function handleResultaatAangemaakt(CorsaZaakdmsService $corsaZaakdmsService): void
    {
        Log::debug('Handling resultaat aangemaakt', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);

        $corsaZaakdmsService->processResultaatAangemaakt($this->notification);
    }

    /**
     * Mark a notification with an unsupported action/resource combination as ignored.
     *
     * New notifications like this are already ignored on ingest; this covers notifications
     * that were batched before that check existed. The notification is not marked as
     * processed and the job does not fail, so the rest of the batch still runs.
     */
    private function ignoreUnsupportedAction(): void
    {
        Log::warning('Unknown notification action/resource combination', [
            'notification_id' => $this->notification->id,
            'actie' => $this->notification->notification['actie'] ?? 'null',
            'resource' => $this->notification->notification['resource'] ?? 'null',
        ]);

        $this->notification->markIgnored(NotificationIgnoreReason::UnsupportedAction);
    }
}
