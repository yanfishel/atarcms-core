<?php

declare(strict_types=1);

namespace AtarCms\Http\Error;

use AtarCms\Http\RequestPath;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class PathPrefixErrorRenderer implements ErrorRenderer
{
    public function __construct(
        private readonly string $prefix,
        private readonly ErrorRenderer $matching,
        private readonly ErrorRenderer $fallback,
    ) {}

    public function render(Throwable $error, int $status, ServerRequestInterface $request): string
    {
        $path = RequestPath::of($request);
        $renderer = ($path->prefix === '' && $path->isUnder($this->prefix)) ? $this->matching : $this->fallback;

        return $renderer->render($error, $status, $request);
    }
}
