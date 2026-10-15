<?php

declare(strict_types=1);

namespace AtarCms\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $fallback,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handlerAt(0)->handle($request);
    }

    private function handlerAt(int $index): RequestHandlerInterface
    {
        $middleware = $this->middleware[$index] ?? null;
        if ($middleware === null) {
            return $this->fallback;
        }

        return new CallableHandler(
            fn(ServerRequestInterface $request): ResponseInterface => $middleware->process($request, $this->handlerAt($index + 1)),
        );
    }
}
