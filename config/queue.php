<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => [
            'driver' => 'database', 'connection' => env('DB_QUEUE_CONNECTION'), 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 900, 'after_commit' => true,
        ],
        'redis' => [
            'driver' => 'redis', 'connection' => env('REDIS_QUEUE_CONNECTION', 'default'), 'queue' => 'default',
            'retry_after' => 900, 'block_for' => 5, 'after_commit' => true,
        ],
    ],
    'failed' => ['driver' => 'database-uuids', 'database' => env('DB_CONNECTION', 'sqlite'), 'table' => 'failed_jobs'],
];
