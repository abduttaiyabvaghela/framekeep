<?php

return [
    'admin_ids' => array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) env('FRAMEKEEP_ADMIN_IDS', ''))))),
    'free_downloads' => 10,
    'offer' => ['id' => 'pro-annual', 'amount_minor' => 2000, 'currency' => 'USD', 'interval' => 'year'],
    'deploy_webhook' => [
        'secret' => env('FRAMEKEEP_DEPLOY_WEBHOOK_SECRET'),
        'repository' => env('FRAMEKEEP_DEPLOY_REPOSITORY'),
    ],
    'youtube_key' => env('YOUTUBE_API_KEY'),
    'instagram' => [
        'app_id' => env('INSTAGRAM_APP_ID'),
        'secret' => env('INSTAGRAM_SECRET'),
        'version' => env('INSTAGRAM_GRAPH_VERSION', 'v27.0'),
    ],
    'x_token' => env('X_BEARER_TOKEN'),
    'pinterest_token' => env('PINTEREST_ACCESS_TOKEN'),
    'pinterest_boards' => preg_split('/[\s,]+/', (string) env('PINTEREST_PUBLIC_BOARDS', ''), -1, PREG_SPLIT_NO_EMPTY),
    'export_disk' => env('FRAMEKEEP_ARCHIVE_DISK', 'local'),
    'export_max_files' => (int) env('FRAMEKEEP_EXPORT_MAX_FILES', 200),
    'export_max_mib' => (int) env('FRAMEKEEP_EXPORT_MAX_MIB', 1024),
];
