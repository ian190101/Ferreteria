<?php

declare(strict_types=1);

return [
    'purge_enabled' => (bool) env('MAINTENANCE_PURGE_ENABLED', false),
    'purge_inline' => (bool) env('MAINTENANCE_PURGE_INLINE', false),
    'git_updates_enabled' => (bool) env('MAINTENANCE_GIT_UPDATES_ENABLED', false),
    'queue_connection' => env('MAINTENANCE_QUEUE_CONNECTION', 'maintenance'),
    'restore_verification' => [
        'host' => env('RESTORE_DB_HOST'),
        'port' => env('RESTORE_DB_PORT'),
        'database' => env('RESTORE_DB_DATABASE'),
        'username' => env('RESTORE_DB_USERNAME'),
        'password' => env('RESTORE_DB_PASSWORD', ''),
    ],
];
