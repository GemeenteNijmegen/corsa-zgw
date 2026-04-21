<?php

use App\Jobs\Sync\SyncAllCatalogi;
use Illuminate\Support\Facades\Schedule;

Schedule::command('telescope:prune')->weekly();

// Process notification batches every minute to catch expired timers
Schedule::command('notifications:flush-expired-batches')
    ->everyMinute()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Batch processing command failed');
    });

// Sync zaaktype mappings from ZGW catalogi daily
Schedule::job(SyncAllCatalogi::class)
    ->daily()
    ->withoutOverlapping();
