<?php

declare(strict_types = 1);

$base = [
    'level' => env('LOG_LEVEL', 'debug'),
];

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

        'notifications' => array_merge($base, [
            'driver' => 'daily',
            'path'   => storage_path('logs/notifications.log'),
        ]),

        'api-exceptions' => array_merge($base, [
            'driver' => 'daily',
            'path'   => storage_path('logs/api-exceptions.log'),
        ]),

    ],
];
