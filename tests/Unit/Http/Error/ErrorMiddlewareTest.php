<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Error;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\Error\ErrorMiddleware;
use AtarCms\Http\Error\ErrorRenderer;
use AtarCms\Http\Exception\MethodNotAllowed;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Http\Security\CspSources;
use AtarCms\Tests\Support\MemoryLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

final class StatusErrorRendererFixture implements ErrorRenderer
{
    public function render(Throwable $error, int $status, ServerRequestInterface $request): string
    {
        return 'rendered ' . $status;
    }
}

final class ThrowingErrorRendererFixture implements ErrorRenderer
{
    public function render(Throwable $error, int $status, ServerRequestInterface $request): string
    {
        throw new RuntimeException('renderer broke');
    }
}

final class ErrorMiddlewareTest extends TestCase
{
    public function testPassesThroughSuccessfulResponses(): void
    {
        $response = $this->process(new StatusErrorRendererFixture(), static fn(): ResponseInterface => (new Psr17Factory())->createResponse(204));

        self::assertSame(204, $response->getStatusCode());
    }

    public function testRendersHttpExceptionsWithoutLogging(): void
    {
        $logger = new MemoryLogger();

        $response = $this->process(new StatusErrorRendererFixture(), static fn(): never => throw new NotFound(), $logger);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('rendered 404', (string) $response->getBody());
        self::assertSame([], $logger->records);
    }

    public function testAddsExceptionHeaders(): void
    {
        $response = $this->process(new StatusErrorRendererFixture(), static fn(): never => throw new MethodNotAllowed(['GET', 'POST']));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testUnexpectedErrorsBecome500AndAreLogged(): void
    {
        $logger = new MemoryLogger();

        $response = $this->process(new StatusErrorRendererFixture(), static fn(): never => throw new RuntimeException('db down'), $logger);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('db down', $logger->records[0]['context']['message']);
    }

    public function testFallsBackToPlainPageWhenRendererFails(): void
    {
        $logger = new MemoryLogger();

        $response = $this->process(new ThrowingErrorRendererFixture(), static fn(): never => throw new NotFound(), $logger);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('404 Not Found', (string) $response->getBody());
        self::assertSame('renderer broke', $logger->records[0]['context']['message']);
    }

    /**
     * @param callable(): ResponseInterface $handler
     */
    /**
     * The hosts a page allowed before it failed (consentHead() rendered, then an error) do not reach the error page:
     * its own render may add them again.
     */
    public function testTheErrorPageStartsWithoutTheFailedPagesCspSources(): void
    {
        $sources = new CspSources();
        $request = (new Psr17Factory())->createServerRequest('GET', '/x')->withAttribute(CspSources::class, $sources);
        $middleware = new ErrorMiddleware(new StatusErrorRendererFixture(), new Responses(new Psr17Factory()), new MemoryLogger());

        $middleware->process($request, new CallableHandler(static function (ServerRequestInterface $request): never {
            CspSources::of($request)?->allow('script-src', 'https://mc.yandex.ru');

            throw new RuntimeException('the template broke');
        }));

        self::assertFalse($sources->any());
    }

    /**
     * @param callable(): ResponseInterface $handler
     */
    private function process(ErrorRenderer $renderer, callable $handler, ?MemoryLogger $logger = null): ResponseInterface
    {
        $middleware = new ErrorMiddleware($renderer, new Responses(new Psr17Factory()), $logger ?? new MemoryLogger());

        return $middleware->process(
            (new Psr17Factory())->createServerRequest('GET', '/x'),
            new CallableHandler(static fn(ServerRequestInterface $request): ResponseInterface => $handler()),
        );
    }
}
