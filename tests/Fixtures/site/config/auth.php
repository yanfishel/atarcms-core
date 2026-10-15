<?php

declare(strict_types=1);

use AtarCms\Kernel\Env;

return static fn(Env $env): array => [
    'google' => [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'allowed_domains' => array_values(array_filter(array_map(
            trim(...),
            explode(',', $env->get('ATAR_GOOGLE_ALLOWED_DOMAINS') ?? ''),
        ))),
    ],
];
