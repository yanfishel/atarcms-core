<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\MiddlewarePipeline;
use AtarCms\Tests\Support\Cast;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TraceMiddlewareFixture implements MiddlewareInterface
{
    public function __construct(private readonly string $name) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $seen = Cast::string($request->getAttribute('seen', ''));

        return $handler
            ->handle($request->withAttribute('seen', $seen . $this->name))
            ->withAddedHeader('X-Trace', $this->name);
    }
}

final class DenyMiddlewareFixture implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return (new Psr17Factory())->createResponse(403);
    }
}

final class MiddlewarePipelineTest extends TestCase
{
    public function testRunsMiddlewareInOrderAroundFallback(): void
    {
        $fallback = new CallableHandler(static fn(ServerRequestInterface $request): ResponseInterface => (new Psr17Factory())
            ->createResponse(200)
            ->withHeader('X-Seen', Cast::string($request->getAttribute('seen')))
            ->withAddedHeader('X-Trace', 'handler'));
        $pipeline = new MiddlewarePipeline(
            [new TraceMiddlewareFixture('A'), new TraceMiddlewareFixture('B')],
            $fallback,
        );

        $response = $pipeline->handle((new Psr17Factory())->createServerRequest('GET', '/'));

        self::assertSame('AB', $response->getHeaderLine('X-Seen'));
        self::assertSame(['handler', 'B', 'A'], $response->getHeader('X-Trace'));
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $called = false;
        $fallback = new CallableHandler(static function () use (&$called): ResponseInterface {
            $called = true;

            return (new Psr17Factory())->createResponse(200);
        });

        $response = (new MiddlewarePipeline([new DenyMiddlewareFixture()], $fallback))
            ->handle((new Psr17Factory())->createServerRequest('GET', '/'));

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($called);
    }

    public function testEmptyPipelineCallsFallback(): void
    {
        $fallback = new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(204));

        $response = (new MiddlewarePipeline([], $fallback))->handle((new Psr17Factory())->createServerRequest('GET', '/'));

        self::assertSame(204, $response->getStatusCode());
    }
}
