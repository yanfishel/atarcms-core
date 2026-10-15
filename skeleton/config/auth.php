<?php

declare(strict_types=1);

use AtarCms\Kernel\Env;

return static fn(Env $env): array => [
    'google' => [
        'client_id' => $env->get('ATAR_GOOGLE_CLIENT_ID') ?? '',
        'client_secret' => $env->get('ATAR_GOOGLE_CLIENT_SECRET') ?? '',
        // Optional: only Google Workspace accounts of these domains (the "hd" claim) may sign in.
        'allowed_domains' => array_values(array_filter(array_map(
            trim(...),
            explode(',', $env->get('ATAR_GOOGLE_ALLOWED_DOMAINS') ?? ''),
        ))),
    ],
];
