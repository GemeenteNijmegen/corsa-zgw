<?php

namespace App\Services;

use App\Jobs\Notifications\HandleNotification;
use App\Jobs\Notifications\MarkBatchProcessed;
use App\Models\Batch;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class BatchingService
{
    private const TIMER_KEY_PREFIX = 'batch_timer:';

    /**
     * Get or create a batch for a notification
     *
     * Call this in a database transaction that also links the notification to the batch. The open
     * batch is read with a row lock, so on PostgreSQL adding a notification and locking the batch
     * for processing (Batch::lockForProcessing()) exclude each other: either the notification is
     * linked before the batch is locked and the batch processes it, or the lock comes first and
     * this does not return that batch anymore, so the notification goes to a new one. Without the
     * transaction the row lock ends right after the read and that guarantee is gone.
     */
    public function getOrCreateBatch(string $zaakIdentificatie): Batch
    {
        // Try to find an existing, unlocked batch for this case
        $batch = Batch::where('zaak_identificatie', $zaakIdentificatie)
            ->whereNull('locked_at')
            ->latest()
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            $batch = Batch::create([
                'zaak_identificatie' => $zaakIdentificatie,
                'status' => 'pending',
            ]);

            Log::info('Created new batch', [
                'batch_id' => $batch->id,
                'zaak_identificatie' => $zaakIdentificatie,
            ]);
        }

        return $batch;
    }

    /**
     * Add a notification to a batch and reset the timer
     */
    public function addNotificationToBatch(
        Notification $notification,
        Batch $batch
    ): void {
        // Associate the notification with the batch
        if ($notification->batch_id !== $batch->id) {
            $notification->update(['batch_id' => $batch->id]);
        }

        // Reset the timer for this batch
        $this->resetTimer($batch);

        Log::info('Added notification to batch', [
            'batch_id' => $batch->id,
            'notification_id' => $notification->id,
            'zaak_identificatie' => $batch->zaak_identificatie,
        ]);
    }

    /**
     * Reset the timer for a batch
     */
    public function resetTimer(Batch $batch): void
    {
        $timeout = (int) config('batching.batch_timeout', 60);
        $timerKey = self::TIMER_KEY_PREFIX.$batch->id;

        // Store timer in cache with expiration
        Cache::put($timerKey, true, now()->addSeconds($timeout));

        Log::debug('Timer reset for batch', [
            'batch_id' => $batch->id,
            'timeout_seconds' => $timeout,
        ]);
    }

    /**
     * Process a batch when the timer expires
     * This locks the batch and creates the job chain
     */
    public function processBatch(Batch $batch): void
    {
        // Prevent double processing. Two jobs for the same batch can run at the same time, so the
        // lock is one conditional update and only the job whose update changed the row goes on.
        if (! $batch->lockForProcessing()) {
            Log::warning('Attempted to process already locked batch', [
                'batch_id' => $batch->id,
            ]);

            return;
        }

        Log::info('Processing batch', [
            'batch_id' => $batch->id,
            'zaak_identificatie' => $batch->zaak_identificatie,
            'notification_count' => $batch->notifications()->count(),
        ]);

        // Get sorted notifications
        $sortedNotifications = $batch->getNotificationsSorted();

        if ($sortedNotifications->isEmpty()) {
            Log::warning('Batch has no notifications', [
                'batch_id' => $batch->id,
            ]);
            $batch->markProcessed();

            return;
        }

        // Check if batch contains 'zaak aangemaakt'
        if ($batch->hasZaakAangemaakt()) {
            $this->processBatchWithZaakAangemaakt($batch, $sortedNotifications);
        } else {
            $this->processBatchWithoutZaakAangemaakt($batch, $sortedNotifications);
        }

        // The batch stays "processing" until the last job of the chain marks it as processed, or
        // until the catch callback of the chain marks it as failed.
    }

    /**
     * Process batch when it contains 'zaak aangemaakt' notification
     * First process the create notification, then process others in parallel
     */
    private function processBatchWithZaakAangemaakt(
        Batch $batch,
        \Illuminate\Database\Eloquent\Collection $notifications
    ): void {
        Log::info('Creating job chain for batch with zaak aangemaakt', [
            'batch_id' => $batch->id,
        ]);

        // Separate the 'create' notification from others
        $createNotification = $notifications->first(function (Model $n) {
            /** @var \App\Models\Notification $n */
            return ($n->notification['actie'] ?? '') === 'create' && ($n->notification['resource'] ?? '') === 'zaak';
        });

        $otherNotifications = $notifications->reject(function (Model $n) {
            /** @var \App\Models\Notification $n */
            return ($n->notification['actie'] ?? '') === 'create' && ($n->notification['resource'] ?? '') === 'zaak';
        });

        // Build job chain: execute create first, then others
        $jobs = [];

        if ($createNotification) {
            /** @var \App\Models\Notification $createNotification */
            $jobs[] = new HandleNotification($createNotification);
        }

        // Add other notifications to be processed in parallel after create
        foreach ($otherNotifications as $notification) {
            /** @var \App\Models\Notification $notification */
            $jobs[] = new HandleNotification($notification);
        }

        if (! empty($jobs)) {
            $this->dispatchChain($batch, $jobs);
        }
    }

    /**
     * Process batch when it does NOT contain 'zaak aangemaakt' notification
     * Process all notifications in parallel
     */
    private function processBatchWithoutZaakAangemaakt(
        Batch $batch,
        \Illuminate\Database\Eloquent\Collection $notifications
    ): void {
        Log::info('Creating chain jobs with a first job to check zaak existence in Corsa', [
            'batch_id' => $batch->id,
            'notification_count' => count($notifications),
        ]);

        // TODO Add job to check zaak existence in Corsa
        $jobs = [];
        foreach ($notifications as $notification) {
            /** @var \App\Models\Notification $notification */
            $jobs[] = new HandleNotification($notification);
        }

        if (! empty($jobs)) {
            $this->dispatchChain($batch, $jobs);
        }
    }

    /**
     * Dispatch the job chain of a batch, ending with the job that marks the batch as processed.
     *
     * A chain stops at a job that fails for good, so the batch is marked as failed then. When the
     * failed job later succeeds on a retry, the rest of the chain runs and its last job marks the
     * batch as processed after all.
     *
     * @param  array<int, HandleNotification>  $jobs
     */
    private function dispatchChain(Batch $batch, array $jobs): void
    {
        $jobs[] = new MarkBatchProcessed($batch);

        // The catch callback is serialized with the chain. It only captures the id, so it carries no
        // model; static makes explicit that it does not need this service either.
        $batchId = (string) $batch->id;

        Bus::chain($jobs)
            ->onQueue(config('batching.queue', 'default'))
            ->catch(static function (Throwable $e) use ($batchId): void {
                if (Batch::markFailedUnlessProcessed($batchId)) {
                    Log::warning('Batch chain stopped at a failed job, batch marked as failed', [
                        'batch_id' => $batchId,
                        'error' => $e->getMessage(),
                    ]);
                }
            })
            ->dispatch();
    }

    /**
     * Check if a timer exists for a batch (still active)
     */
    public function hasActiveTimer(Batch $batch): bool
    {
        $timerKey = self::TIMER_KEY_PREFIX.$batch->id;

        return Cache::has($timerKey);
    }

    /**
     * Get all batches that need to be processed
     * (have no active timer and are not locked)
     */
    public function getUnprocessedBatches()
    {
        return Batch::whereNull('locked_at')
            ->where('status', 'pending')
            ->get()
            ->filter(function (Batch $batch) {
                return ! $this->hasActiveTimer($batch);
            });
    }
}
