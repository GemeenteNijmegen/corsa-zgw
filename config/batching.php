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

    /**
     * Minutes after which a batch that is still processing, without a failed notification, is shown
     * as taking long. A job in the chain waits up to 20 times 15 seconds (5 minutes) for a document
     * that another job is sending, and a single attempt may run up to the worker time-out (60
     * seconds by default, 5 minutes in production, see config/horizon.php), so a normal chain of a few jobs finishes well within 30 minutes, also with some
     * wait in the queue. A batch with many documents can take longer; raise the value if that is
     * normal for an environment.
     */
    'stuck_after_minutes' => env('NOTIFICATION_BATCH_STUCK_AFTER_MINUTES', 30),
];
