<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
