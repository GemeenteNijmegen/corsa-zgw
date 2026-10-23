<?php

namespace App\Jobs\Notifications;

use App\Enums\DocumentSyncOutcome;
use App\Enums\NotificationRoute;
use App\Exceptions\DocumentLockedException;
use App\Models\Batch;
use App\Models\Notification;
use App\Services\BatchingService;
use App\Services\CorsaZaakdmsService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Woweb\Zgw\Exceptions\ApiRequestException;

/**
 * Add a newly linked document to its zaak right away, without waiting for the batch of the zaak.
 *
 * Used for a document that is still being edited, so it is in the document management system as
 * soon as possible. When the zaak is not there yet, the notification moves to the batch of the
 * zaak, which adds the zaak first.
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

    public function __construct(private readonly Notification $notification)
    {
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

        try {
            $outcome = $corsaZaakdmsService->addDocumentToZaak($this->notification);
        } catch (DocumentLockedException) {
            // Another job is sending the same document. Once it is done the register has it.
            $this->release(self::LOCK_RETRY_SECONDS);

            return;
        } catch (Throwable $e) {
            if ($this->isTemporary($e) && $this->attempts() < self::ERROR_ATTEMPTS) {
                Log::warning('Temporary error while processing document directly, retrying', [
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

        $this->notification->update([
            'processed' => true,
            'processed_at' => now(),
        ]);

        Log::info('Direct notification processed', [
            'notification_id' => $this->notification->id,
            'zaak_identificatie' => $this->notification->zaak_identificatie,
            'outcome' => $outcome->name,
        ]);
    }

    /**
     * The zaak is not in the document management system yet. The batch of the zaak adds the zaak
     * before the document, so the notification is processed there.
     *
     * The route and the batch change together, so a failure halfway leaves the notification on the
     * direct route and a retry moves it again. The batch timer starts after that change.
     */
    private function moveToBatch(BatchingService $batchingService): void
    {
        $batch = DB::transaction(function () use ($batchingService): Batch {
            $batch = $batchingService->getOrCreateBatch($this->notification->zaak_identificatie);

            $this->notification->update([
                'route' => NotificationRoute::Batch,
                'batch_id' => $batch->id,
            ]);

            return $batch;
        });

        // The notification is already linked, so this only starts the timer of the batch.
        $batchingService->addNotificationToBatch($this->notification, $batch);

        Log::info('Zaak not in Corsa yet, direct notification moved to the batch of the zaak', [
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
