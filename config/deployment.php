<?php

declare(strict_types=1);

return [
    'commit' => env('SOURCE_COMMIT', 'sin identificar'),
    'built_at' => env('BUILD_TIMESTAMP', 'sin identificar'),
    'pipeline_url' => env('DEPLOY_PIPELINE_URL'),
    'restart_command' => json_decode(env('DEPLOY_RESTART_COMMAND_JSON', '[]'), true),
];
