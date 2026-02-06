<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('telescope:prune')->weekly();

// Process notification batches every minute to catch expired timers
Schedule::command('notifications:process-batches')
    ->everyMinute()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Batch processing command failed');
    });
