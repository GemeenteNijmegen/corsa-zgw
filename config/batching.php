<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Notification Batching Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how notifications are batched and processed.
    |
    */

    /**
     * Batch timeout in seconds
     * Time to wait after the last notification arrives before processing the batch
     */
    'batch_timeout' => env('NOTIFICATION_BATCH_TIMEOUT', 60),

    /**
     * Maximum number of notifications in a single batch before forcing processing
     */
    'batch_max_size' => env('NOTIFICATION_BATCH_MAX_SIZE', 100),

    /**
     * Whether to use queues for batch processing
     */
    'use_queue' => env('NOTIFICATION_USE_QUEUE', true),

    /**
     * Queue name for batch processing jobs
     */
    'queue' => env('NOTIFICATION_QUEUE', 'default'),
];
