<?php

$base = [
    'driver' => 'cloudwatch',
    'level'  => env('LOG_LEVEL', 'debug'),
    'aws'    => [
        'region'      => env('AWS_REGION', 'us-east-1'),
        'credentials' => [
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY')
        ]
    ],
    'log_group'  => env('CLOUDWATCH_LOG_GROUP', 'laravel-logs'),
    'batch_size' => env('CLOUDWATCH_BATCH_SIZE', 1000),
    'retention'  => env('CLOUDWATCH_RETENTION_DAYS', 365)
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
            'path'   => storage_path('logs/notifications.log')
        ]),

        'api-exceptions' => array_merge($base, [
            'driver' => 'daily',
            'path'   => storage_path('logs/api-exceptions.log')
        ]),

        'cloudwatch' => array_merge($base, [
            'log_stream' => env('CLOUDWATCH_LOG_STREAM', 'laravel')
        ]),

        'cloudwatch-notifications' => array_merge($base, [
            'log_stream' => 'notifications'
        ]),

        'cloudwatch-api-exceptions' => array_merge($base, [
            'log_stream' => 'api-exceptions'
        ])

    ]
];
