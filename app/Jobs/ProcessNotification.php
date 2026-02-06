<?php

namespace App\Jobs;

use App\Models\Notification;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessNotification implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(private readonly Notification $notification) {}

    /**
     * Execute the job.
     */
    public function handle(): void
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

            match ($actionKey) {
                'create:zaak' => $this->handleZaakAangemaakt(),
                'create:status' => $this->handleZaakPartialUpdate(),
                'create:zaakinformatieobject' => $this->handleDocumentAangemaakt(),
                default => $this->handleUnknownAction(),
            };

            // Mark notification as processed
            $this->notification->update(['processed' => true]);

            Log::info('Notification processed successfully', [
                'notification_id' => $this->notification->id,
            ]);
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
    private function handleZaakAangemaakt(): void
    {
        Log::debug('Handling zaak aangemaakt', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);

        // TODO: Implement logic to create case in Corsa

    }

    /**
     * Handle zaak partially updated notification
     */
    private function handleZaakPartialUpdate(): void
    {
        Log::debug('Handling zaak partial update', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);

        // TODO: Implement logic to partially update case in Corsa
    }

    /**
     * Handle document created notification
     */
    private function handleDocumentAangemaakt(): void
    {
        Log::debug('Handling document aangemaakt', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);

        // TODO: Implement logic to create document in Corsa
    }

    /**
     * Handle unknown action
     */
    private function handleUnknownAction(): void
    {
        Log::warning('Unknown notification action/resource combination', [
            'notification_id' => $this->notification->id,
            'actie' => $this->notification->notification['actie'] ?? 'null',
            'resource' => $this->notification->notification['resource'] ?? 'null',
        ]);

        // TODO: Implement logging or error handling for unknown actions
    }
}
