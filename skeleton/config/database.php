<?php

declare(strict_types=1);

use AtarCms\Kernel\Env;

return static fn(Env $env): array => [
    'host' => $env->get('DB_HOST', '127.0.0.1'),
    'port' => $env->int('DB_PORT', 3306),
    'database' => $env->required('DB_DATABASE'),
    'username' => $env->required('DB_USERNAME'),
    'password' => $env->get('DB_PASSWORD', ''),
];
