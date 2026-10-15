<?php

declare(strict_types=1);

use App\SiteModule;
use AtarCms\Kernel\Env;

return static fn(Env $env): array => [
    'name' => 'AtarCMS Site',
    'env' => $env->get('APP_ENV', 'production'),
    // The public http(s) URL of the site root, without a path; every boot (web and bin/atar) stops when it is missing or invalid. .env.example supplies the local value.
    'url' => $env->get('APP_URL') ?? '',
    // The time zone the admin and the theme show dates in, and in which editors enter the publication date; the
    // database always stores UTC.
    'timezone' => 'UTC',
    'debug' => $env->bool('APP_DEBUG', false),
    'theme' => $env->get('APP_THEME', 'default'),
    'log_level' => $env->get('LOG_LEVEL', 'info'),
    'modules' => [SiteModule::class],
];
