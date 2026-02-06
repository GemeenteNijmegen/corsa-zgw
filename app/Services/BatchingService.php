<?php

namespace App\Services;

use App\Jobs\ProcessNotification;
use App\Models\Batch;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BatchingService
{
    private const TIMER_KEY_PREFIX = 'batch_timer:';

    /**
     * Get or create a batch for a notification
     */
    public function getOrCreateBatch(string $zaakIdentificatie): Batch
    {
        // Try to find an existing, unlocked batch for this case
        $batch = Batch::where('zaak_identificatie', $zaakIdentificatie)
            ->whereNull('locked_at')
            ->latest()
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
        // Prevent double processing
        if ($batch->isLocked()) {
            Log::warning('Attempted to process already locked batch', [
                'batch_id' => $batch->id,
            ]);

            return;
        }

        // Lock the batch
        $batch->lock();
        $batch->update(['status' => 'processing']);

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

        // Mark batch as processed
        $batch->markProcessed();
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
            $jobs[] = new ProcessNotification($createNotification);
        }

        // Add other notifications to be processed in parallel after create
        foreach ($otherNotifications as $notification) {
            /** @var \App\Models\Notification $notification */
            $jobs[] = new ProcessNotification($notification);
        }

        // Dispatch the job chain
        if (! empty($jobs)) {
            Bus::chain($jobs)
                ->onQueue(config('batching.queue', 'default'))
                ->dispatch();
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
            $jobs[] = new ProcessNotification($notification);
        }

        // Dispatch all jobs in parallel
        if (! empty($jobs)) {
            Bus::chain($jobs)
                ->onQueue(config('batching.queue', 'default'))
                ->dispatch();
        }
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
