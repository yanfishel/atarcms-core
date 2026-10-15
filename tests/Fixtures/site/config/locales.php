<?php

declare(strict_types=1);

use AtarCms\Kernel\Env;

return static fn(Env $env): array => [
    'default' => $env->get('APP_LOCALE') ?? 'en',
    'locales' => ['en', 'ru'],
    // The suites written before Plan 37 test the two separate switches; tests/Functional/LinkedLanguage the linked mode.
    'admin' => 'separate',
];
