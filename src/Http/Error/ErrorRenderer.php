<?php

declare(strict_types=1);

namespace AtarCms\Http\Error;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

interface ErrorRenderer
{
    public function render(Throwable $error, int $status, ServerRequestInterface $request): string;
}
