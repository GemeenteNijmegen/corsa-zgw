<?php

namespace App\Console\Commands;

use App\Jobs\Notifications\FlushExpiredBatches;
use Illuminate\Console\Command;

class FlushExpiredBatchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:flush-expired-batches';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush notification batches that have expired timers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Dispatching batch processing job...');

        FlushExpiredBatches::dispatch()
            ->onQueue(config('batching.queue', 'default'));

        $this->info('Batch processing job dispatched successfully.');

        return self::SUCCESS;
    }
}
