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

];
