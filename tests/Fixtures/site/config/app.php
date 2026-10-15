<?php

declare(strict_types=1);

use AtarCms\Kernel\Env;
use AtarCms\Tests\Fixtures\FixtureModule;

return static fn(Env $env): array => [
    'name' => 'Fixture',
    'env' => $env->get('APP_ENV', 'production'),
    'debug' => $env->bool('APP_DEBUG'),
    'theme' => 'test',
    'log_level' => 'debug',
    'modules' => [FixtureModule::class],
    'url' => 'https://localhost',
];
