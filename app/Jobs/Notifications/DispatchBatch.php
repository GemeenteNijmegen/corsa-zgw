<?php

namespace App\Jobs\Notifications;

use App\Models\Batch;
use App\Services\BatchingService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DispatchBatch implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(private readonly Batch $batch) {}

    public function displayName(): string
    {
        return "Dispatch Batch {$this->batch->id} / {$this->batch->zaak_identificatie}";
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            "batch:{$this->batch->id}",
            "zaak:{$this->batch->zaak_identificatie}",
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(BatchingService $batchingService): void
    {
        Log::info('DispatchBatch job started', [
            'batch_id' => $this->batch->id,
            'zaak_identificatie' => $this->batch->zaak_identificatie,
        ]);

        // Process the batch completely (locking, sorting, creating jobs, etc.)
        $batchingService->processBatch($this->batch->fresh());

        Log::info('DispatchBatch job completed', [
            'batch_id' => $this->batch->id,
        ]);
    }
}
