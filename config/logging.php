<?php

declare(strict_types = 1);

return [

    /*
    |---------------------------------------------------------------------------
    | Log Channels
    |---------------------------------------------------------------------------
    |
    | Define any custom log channels provided by the toolkit.
    |
    */

    'channels' => [

        'notifications' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/notifications.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
        ],

        'api-exceptions' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/api-exceptions.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
        ],

    ],
];
