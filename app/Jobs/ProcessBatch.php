<?php

namespace App\Jobs;

use App\Models\Batch;
use App\Services\BatchingService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessBatch implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(private readonly Batch $batch) {}

    /**
     * Execute the job.
     */
    public function handle(BatchingService $batchingService): void
    {
        Log::info('ProcessBatch job started', [
            'batch_id' => $this->batch->id,
            'zaak_identificatie' => $this->batch->zaak_identificatie,
        ]);

        // Process the batch completely (locking, sorting, creating jobs, etc.)
        $batchingService->processBatch($this->batch->fresh());

        Log::info('ProcessBatch job completed', [
            'batch_id' => $this->batch->id,
        ]);
    }
}
