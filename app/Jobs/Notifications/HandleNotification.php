<?php

namespace App\Jobs\Notifications;

use App\Enums\DocumentSyncOutcome;
use App\Enums\NotificationIgnoreReason;
use App\Exceptions\DocumentLockedException;
use App\Exceptions\DocumentUploadIncompleteException;
use App\Models\Notification;
use App\Services\CorsaZaakdmsService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Woweb\Zgw\Exceptions\DisallowedHostException;

class HandleNotification implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Seconds to wait before trying again while another job is sending the same document.
     */
    public const LOCK_RETRY_SECONDS = 15;

    /**
     * Room to try again while another job sends the same document (20 times 15 seconds covers
     * the lock of that job). Releasing the job for that reason is not an error.
     */
    public int $tries = 20;

    /**
     * Any error still fails the job right away, as with a single try.
     */
    public int $maxExceptions = 1;

    /**
     * A time-out still fails the job right away, as with a single try.
     */
    public bool $failOnTimeout = true;

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
        $this->notification->refresh();

        // A second chain for the same batch, or a retry of a job that already succeeded, has
        // nothing left to do. Returning normally lets the rest of the chain go on.
        if ($this->notification->processed || $this->notification->ignored_at !== null) {
            Log::info('Notification already handled, skipping', [
                'notification_id' => $this->notification->id,
                'batch_id' => $this->notification->batch_id,
                'processed' => $this->notification->processed,
                'ignored' => $this->notification->ignored_at !== null,
            ]);

            return;
        }

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
                'update:enkelvoudiginformatieobject',
                'partial_update:enkelvoudiginformatieobject' => $this->handleDocumentGewijzigd(...),
                default => null,
            };

            if ($handler === null) {
                $this->ignoreUnsupportedAction();

                return;
            }

            $handler($corsaZaakdmsService);

            // Mark notification as processed, which clears a failure of an earlier run
            $this->notification->markProcessed();

            Log::info('Notification processed successfully', [
                'notification_id' => $this->notification->id,
            ]);
        } catch (DocumentLockedException) {
            // Another job is sending the same document. Once it is done the register has it, and
            // the rest of the chain waits for this job.
            Log::info('Document is being sent by another job, releasing the notification', [
                'notification_id' => $this->notification->id,
            ]);

            $this->release(self::LOCK_RETRY_SECONDS);
        } catch (DocumentUploadIncompleteException) {
            // The content of the changed document is still being uploaded in parts. Wait the same
            // way as for the lock; the tries above bound how long the chain waits.
            Log::info('Document upload is not complete, releasing the notification', [
                'notification_id' => $this->notification->id,
            ]);

            $this->release(self::LOCK_RETRY_SECONDS);
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
     * Called once the job has failed for good. The jobs after it in the batch chain do not run,
     * so their notifications stay unprocessed without a recorded failure.
     */
    public function failed(Throwable $e): void
    {
        $this->notification->markFailed($e, $this->job?->uuid());
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
     * Handle a change to a document that ended up in the batch of its zaak, with the same checks as
     * the direct route. The link comes earlier in the batch, so the document should be in the
     * document management system by now; when it is not, the job fails visibly.
     */
    private function handleDocumentGewijzigd(CorsaZaakdmsService $corsaZaakdmsService): void
    {
        Log::debug('Handling document gewijzigd', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);

        $outcome = $corsaZaakdmsService->updateDocument($this->notification);

        if ($outcome === DocumentSyncOutcome::UploadIncomplete) {
            throw new DocumentUploadIncompleteException;
        }

        if ($outcome === DocumentSyncOutcome::DocumentNotInCorsa) {
            throw new RuntimeException('Missing document in Corsa for document update');
        }
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
