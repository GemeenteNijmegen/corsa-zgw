<?php

namespace App\Console\Commands;

use App\Jobs\TriggerBatchProcessing;
use Illuminate\Console\Command;

class ProcessNotificationBatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:process-batches';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process notification batches that have expired timers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Dispatching batch processing job...');

        TriggerBatchProcessing::dispatch()
            ->onQueue(config('batching.queue', 'default'));

        $this->info('Batch processing job dispatched successfully.');

        return self::SUCCESS;
    }
}
