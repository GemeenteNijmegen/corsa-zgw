<?php

namespace App\Jobs\Notifications;

use App\Models\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The last job in the chain of a batch. A chain stops at a job that fails, and a job that is
 * released runs again before the chain continues, so this only runs once every notification job
 * before it has finished. Until then the batch stays "processing".
 */
class MarkBatchProcessed implements ShouldQueue
{
    use Queueable;

    /**
     * A batch that was removed in the meantime has nothing left to mark.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Batch $batch) {}

    public function handle(): void
    {
        $this->batch->markProcessed();
    }
}
