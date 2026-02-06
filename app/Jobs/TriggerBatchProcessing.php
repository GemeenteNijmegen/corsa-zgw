<?php

namespace App\Jobs;

use App\Services\BatchingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TriggerBatchProcessing implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(BatchingService $batchingService): void
    {
        Log::info('Triggering batch processing for expired timers');

        // Get all batches with expired timers
        $batches = $batchingService->getUnprocessedBatches();

        Log::info('Found batches to process', [
            'batch_count' => $batches->count(),
        ]);

        foreach ($batches as $batch) {
            Log::info('Dispatching ProcessBatch job', [
                'batch_id' => $batch->id,
                'zaak_identificatie' => $batch->zaak_identificatie,
            ]);

            ProcessBatch::dispatch($batch)
                ->onQueue(config('batching.queue', 'default'));
        }
    }
}
