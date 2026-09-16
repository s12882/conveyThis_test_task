<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File TTL
    |--------------------------------------------------------------------------
    |
    | Number of hours an uploaded file is kept before it's automatically
    | deleted.
    |
    */

    'ttl_hours' => (int) env('FILE_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Max upload size
    |--------------------------------------------------------------------------
    |
    | Maximum allowed upload size, in kilobytes, matching Laravel's `max`
    | file-validation rule.
    |
    */

    'max_size_kb' => (int) env('FILE_MAX_SIZE_KB', 10240),

    /*
    |--------------------------------------------------------------------------
    | Deletion notification recipient
    |--------------------------------------------------------------------------
    |
    | Address notified (via the rabbitmq:consume-file-deletions command)
    | whenever a file is deleted, manually or automatically.
    |
    */

    'notification_email' => env('FILE_DELETION_NOTIFICATION_EMAIL'),

];
