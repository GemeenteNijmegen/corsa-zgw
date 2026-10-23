<?php

namespace App\Jobs\Notifications;

use App\Enums\DocumentSyncOutcome;
use App\Enums\NotificationRoute;
use App\Exceptions\DocumentLockedException;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\BatchingService;
use App\Services\CorsaZaakdmsService;
use App\ValueObjects\OpenNotification;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Woweb\Zgw\Exceptions\ApiRequestException;

/**
 * Send a document to the document management system right away, without waiting for the batch of
 * the zaak.
 *
 * Used for a document that is linked to a zaak while it is still being edited, so it is in the
 * document management system as soon as possible, and for every change to a document. When the
 * zaak (for a new link) or the document (for a change) is not there yet, the notification moves to
 * the batch of the zaak, which adds the zaak and its documents first. A change waits for the
 * document a few times before it moves.
 */
class ProcessDocumentDirectly implements ShouldQueue
{
    use Queueable;

    /** Workers take jobs from this queue before the batch jobs on the default queue. */
    public const QUEUE = 'direct';

    /**
     * The first attempt plus three retries, for temporary HTTP errors only.
     */
    public const ERROR_ATTEMPTS = 4;

    /**
     * The worker fails the job at the fourth temporary error. It counts thrown exceptions only,
     * not releases, so waiting for the lock of another job does not use up these retries.
     */
    public int $maxExceptions = self::ERROR_ATTEMPTS;

    /**
     * Seconds to wait before trying again while another job is sending the same document.
     */
    public const LOCK_RETRY_SECONDS = 15;

    /**
     * Minutes after dispatch in which the job may run again. Bounded by time instead of a number of
     * tries, because waiting for another job that sends the same document is not an error and
     * should not use up the retries for temporary errors. Covers the three retries (7.5 minutes),
     * the time the attempts themselves take and the lock of another job.
     */
    public const RETRY_MINUTES = 20;

    /**
     * Seconds to wait before checking again whether a changed document is in the document
     * management system, or whether its upload is complete. After the last wait a change moves to
     * the batch of the zaak, or fails when the upload is still not complete.
     *
     * @var list<int>
     */
    public const DOCUMENT_WAIT_SECONDS = [30, 120, 300];

    /**
     * @param  int  $documentWaits  how many times this change already waited for the document
     */
    public function __construct(
        private readonly Notification $notification,
        public readonly int $documentWaits = 0,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_MINUTES);
    }

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function displayName(): string
    {
        return 'Process document directly';
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            "zaak:{$this->notification->zaak_identificatie}",
            'route:direct',
        ];
    }

    public function handle(CorsaZaakdmsService $corsaZaakdmsService, BatchingService $batchingService): void
    {
        $this->notification->refresh();

        if ($this->notification->processed || $this->notification->route !== NotificationRoute::Direct) {
            Log::info('Direct notification already handled, skipping', [
                'notification_id' => $this->notification->id,
                'route' => $this->notification->route->value,
            ]);

            return;
        }

        $isUpdate = OpenNotification::isDocumentUpdateAction(
            $this->notification->notification['actie'] ?? null,
            $this->notification->notification['resource'] ?? null,
        );

        try {
            $outcome = $isUpdate
                ? $corsaZaakdmsService->updateDocument($this->notification)
                : $corsaZaakdmsService->addDocumentToZaak($this->notification);
        } catch (DocumentLockedException) {
            // Another job is sending the same document. Once it is done the register has it.
            $this->release(self::LOCK_RETRY_SECONDS);

            return;
        } catch (Throwable $e) {
            if ($this->isTemporary($e)) {
                // The worker retries this until the fourth temporary error ($maxExceptions).
                Log::warning('Temporary error while processing document directly', [
                    'notification_id' => $this->notification->id,
                    'attempt' => $this->attempts(),
                    'error' => get_class($e),
                ]);

                throw $e;
            }

            Log::error('Error processing document directly', [
                'notification_id' => $this->notification->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Nothing else will change the outcome of a permanent error, so fail without a retry.
            $this->fail($e);

            return;
        }

        if ($outcome === DocumentSyncOutcome::ZaakNotInCorsa) {
            $this->moveToBatch($batchingService);

            return;
        }

        if ($outcome === DocumentSyncOutcome::DocumentNotInCorsa || $outcome === DocumentSyncOutcome::UploadIncomplete) {
            $this->waitForDocument($outcome, $batchingService);

            return;
        }

        $this->notification->markProcessed();

        Log::info('Direct notification processed', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
            'outcome' => $outcome->name,
        ]);
    }

    /**
     * Called once the job has failed for good: after a permanent error, after the last attempt
     * of a temporary error, or when its time to retry has run out.
     */
    public function failed(Throwable $e): void
    {
        $this->notification->markFailed($e, $this->job?->uuid());
    }

    /**
     * The changed document is not in the document management system yet (its link may still be in
     * a batch), or its content is still being uploaded. Check again later in a new job that knows
     * how often it waited. Waiting is not an error, so it does not use up the retries for errors.
     * After the last wait a document that is still missing moves the change to the batch of the
     * zaak, and an upload that is still not complete fails the job.
     */
    private function waitForDocument(DocumentSyncOutcome $outcome, BatchingService $batchingService): void
    {
        $delay = self::DOCUMENT_WAIT_SECONDS[$this->documentWaits] ?? null;

        if ($delay !== null) {
            Log::info('Document not ready for the change yet, checking again later', [
                'notification_id' => $this->notification->id,
                'outcome' => $outcome->name,
                'wait' => $this->documentWaits + 1,
                'delay_seconds' => $delay,
            ]);

            self::dispatch($this->notification, $this->documentWaits + 1)->delay($delay);

            return;
        }

        if ($outcome === DocumentSyncOutcome::DocumentNotInCorsa) {
            $this->moveToBatch($batchingService);

            return;
        }

        $this->fail(new RuntimeException('Document upload is not complete'));
    }

    /**
     * The zaak (or for a change, the document) is not in the document management system yet. The
     * batch of the zaak adds the zaak and its documents first, so the notification is processed
     * there.
     *
     * The route and the batch change together, so a failure halfway leaves the notification on the
     * direct route and a retry moves it again. The batch timer starts after that change.
     */
    private function moveToBatch(BatchingService $batchingService): void
    {
        $batch = DB::transaction(function () use ($batchingService): Batch {
            $batch = $batchingService->getOrCreateBatch($this->notification->zaak_identificatie);

            // A failure of an earlier run no longer applies: the batch processes it now.
            $this->notification->update([
                'route' => NotificationRoute::Batch,
                'batch_id' => $batch->id,
                ...Notification::clearedFailure(),
            ]);

            return $batch;
        });

        // The notification is already linked, so this only starts the timer of the batch.
        $batchingService->addNotificationToBatch($this->notification, $batch);

        Log::info('Zaak or document not in Corsa yet, direct notification moved to the batch of the zaak', [
            'notification_id' => $this->notification->id,
            'batch_id' => $batch->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
        ]);
    }

    /**
     * A connection error, a time-out, rate limiting or a server error may pass. Any other error,
     * such as a validation error, a refused host or a 4xx response, will not.
     */
    private function isTemporary(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        $status = match (true) {
            $e instanceof RequestException => $e->response->status(),
            $e instanceof ApiRequestException => $e->getResponse()->status(),
            default => null,
        };

        return $status !== null && ($status === 408 || $status === 429 || $status >= 500);
    }
}
