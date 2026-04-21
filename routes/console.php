<?php

use App\Jobs\SyncAllCatalogiJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('telescope:prune')->weekly();

// Process notification batches every minute to catch expired timers
Schedule::command('notifications:process-batches')
    ->everyMinute()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Batch processing command failed');
    });

// Sync zaaktype mappings from ZGW catalogi daily
Schedule::job(SyncAllCatalogiJob::class)
    ->daily()
    ->withoutOverlapping();
