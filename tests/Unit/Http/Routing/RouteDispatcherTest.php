<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Routing;

use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\RequestPath;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\RouteDispatcher;
use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Support\Cast;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DispatcherControllerFixture
{
    public function __construct(private readonly Responses $responses) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::class);

        return $this->responses->text(sprintf(
            'slug=%s route=%s',
            Cast::string($request->getAttribute('slug')),
            $match instanceof RouteMatch ? $match->route->name : 'none',
        ));
    }

    public function notAResponse(ServerRequestInterface $request): string
    {
        return 'oops';
    }

    public function attribute(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->text(sprintf('%s', Cast::string($request->getAttribute(self::class))));
    }
}

final class TagMiddlewareFixture implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Route-Middleware', 'yes');
    }
}

final class RouteDispatcherTest extends TestCase
{
    private Router $router;

    private RouteDispatcher $dispatcher;

    protected function setUp(): void
    {
        $container = new Container();
        $this->router = $container->service(Router::class);
        $this->dispatcher = $container->service(RouteDispatcher::class);
    }

    public function testInvokesControllerWithRouteAttributes(): void
    {
        $this->router->get('/news/{slug}', [DispatcherControllerFixture::class, 'show'], 'news.show');

        $response = $this->dispatcher->handle($this->request('GET', '/news/hello'));

        self::assertSame('slug=hello route=news.show', (string) $response->getBody());
    }

    public function testAppliesRouteMiddleware(): void
    {
        $this->router->get('/news/{slug}', [DispatcherControllerFixture::class, 'show'])
            ->middleware(TagMiddlewareFixture::class);

        $response = $this->dispatcher->handle($this->request('GET', '/news/hello'));

        self::assertSame('yes', $response->getHeaderLine('X-Route-Middleware'));
    }

    public function testRedirectsTrailingSlashKeepingQuery(): void
    {
        $this->router->get('/news/{slug}', [DispatcherControllerFixture::class, 'show']);

        $response = $this->dispatcher->handle($this->request('GET', '/news/hello/?page=2'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/news/hello?page=2', $response->getHeaderLine('Location'));
    }

    public function testTrailingSlashRedirectDoesNotProduceAProtocolRelativeTarget(): void
    {
        // A catch-all matching any non-empty, non-slash-terminated path, e.g. a future content slug route.
        $this->router->get('/{path:.*[^/]}', [DispatcherControllerFixture::class, 'show']);

        // Psr17Factory/Nyholm's Uri collapses a "//"-prefixed path down to "/" when read back through
        // getUri()->getPath(), so the dangerous raw path is set directly as the RequestPath attribute,
        // exactly as it would be if some other layer produced it.
        $request = $this->request('GET', '/')
            ->withAttribute(RequestPath::class, RequestPath::fromRaw('//evil.example/'));

        $this->expectException(NotFound::class);

        $this->dispatcher->handle($request);
    }

    public function testTrailingSlashWithoutMatchingRouteIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->dispatcher->handle($this->request('GET', '/missing/'));
    }

    public function testTrailingSlashOnPostIsNotRedirected(): void
    {
        $this->router->post('/form', [DispatcherControllerFixture::class, 'show']);

        $this->expectException(NotFound::class);

        $this->dispatcher->handle($this->request('POST', '/form/'));
    }

    public function testRejectsHandlerNotReturningResponse(): void
    {
        $this->router->get('/bad', [DispatcherControllerFixture::class, 'notAResponse']);

        $this->expectException(LogicException::class);

        $this->dispatcher->handle($this->request('GET', '/bad'));
    }

    public function testRejectsMissingControllerMethod(): void
    {
        $this->router->get('/nope', [DispatcherControllerFixture::class, 'missing']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('::missing');

        $this->dispatcher->handle($this->request('GET', '/nope'));
    }

    public function testCopiesRouteAttributesToTheRequest(): void
    {
        $this->router->get('/x', [DispatcherControllerFixture::class, 'attribute'])
            ->attribute(DispatcherControllerFixture::class, 'from-route');

        self::assertSame('from-route', (string) $this->dispatcher->handle($this->request('GET', '/x'))->getBody());
    }

    public function testReusesTheMatchOfRouteMatcher(): void
    {
        $this->router->get('/a/{slug}', [DispatcherControllerFixture::class, 'show'], 'a.show');
        $this->router->get('/b/{slug}', [DispatcherControllerFixture::class, 'show'], 'b.show');
        $match = $this->router->match('GET', '/b/from-matcher');

        $response = $this->dispatcher->handle($this->request('GET', '/a/ignored')->withAttribute(RouteMatch::class, $match));

        self::assertSame('slug=from-matcher route=b.show', (string) $response->getBody());
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, $uri);
    }
}
