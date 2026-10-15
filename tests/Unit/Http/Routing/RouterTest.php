<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Routing;

use AtarCms\Http\Exception\MethodNotAllowed;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Routing\Router;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;

final class GroupMiddlewareFixture implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

final class RouterTest extends TestCase
{
    public function testMatchesStaticAndParameterRoutes(): void
    {
        $router = new Router();
        $about = $router->get('/about', [stdClass::class, 'about'], 'about');
        $news = $router->get('/news/{slug}', [stdClass::class, 'news'], 'news.show');

        self::assertSame($about, $router->match('GET', '/about')->route);
        $match = $router->match('GET', '/news/hello');
        self::assertSame($news, $match->route);
        self::assertSame(['slug' => 'hello'], $match->params);
    }

    public function testDecodesPercentEncodedPaths(): void
    {
        $router = new Router();
        $router->get('/news/{slug}', [stdClass::class, 'news'], 'news.show');

        $match = $router->match('GET', '/news/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82');

        self::assertSame(['slug' => 'привет'], $match->params);
    }

    public function testHeadFallsBackToGet(): void
    {
        $router = new Router();
        $route = $router->get('/about', [stdClass::class, 'about']);

        self::assertSame($route, $router->match('HEAD', '/about')->route);
    }

    public function testUnknownPathThrowsNotFound(): void
    {
        $this->expectException(NotFound::class);

        (new Router())->match('GET', '/missing');
    }

    public function testWrongMethodThrowsMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/about', [stdClass::class, 'about']);

        try {
            $router->match('POST', '/about');
            self::fail('Expected MethodNotAllowed');
        } catch (MethodNotAllowed $e) {
            self::assertSame(['GET'], $e->allowed);
            self::assertSame(['Allow' => 'GET'], $e->headers());
        }
    }

    public function testGeneratesUrls(): void
    {
        $router = new Router();
        $router->get('/news/{slug}', [stdClass::class, 'news'], 'news.show');
        $router->get('/archive[/{year:\d{4}}]', [stdClass::class, 'archive'], 'archive');

        self::assertSame('/news/hello', $router->url('news.show', ['slug' => 'hello']));
        self::assertSame('/news/hello?page=2', $router->url('news.show', ['slug' => 'hello', 'page' => 2]));
        self::assertSame(
            '/news/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82',
            $router->url('news.show', ['slug' => 'привет']),
        );
        self::assertSame('/archive', $router->url('archive'));
        self::assertSame('/archive/2026', $router->url('archive', ['year' => 2026]));
    }

    public function testUrlRejectsValueNotMatchingPattern(): void
    {
        $router = new Router();
        $router->get('/archive/{year:\d{4}}', [stdClass::class, 'archive'], 'archive');

        $this->expectException(InvalidArgumentException::class);

        $router->url('archive', ['year' => 'abcd']);
    }

    public function testUrlRequiresParameters(): void
    {
        $router = new Router();
        $router->get('/news/{slug}', [stdClass::class, 'news'], 'news.show');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing parameters for route "news.show"');

        $router->url('news.show');
    }

    public function testUrlForUnknownRouteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Router())->url('nope');
    }

    public function testGroupsApplyPrefixAndMiddleware(): void
    {
        $router = new Router();
        $router->group('/admin', static function (Router $router): void {
            $router->get('/', [stdClass::class, 'dashboard'], 'admin.dashboard');
            $router->get('/users', [stdClass::class, 'users']);
        }, GroupMiddlewareFixture::class);
        $router->get('/outside', [stdClass::class, 'outside']);

        self::assertSame([GroupMiddlewareFixture::class], $router->match('GET', '/admin')->route->middlewareList());
        self::assertSame('/admin/users', $router->match('GET', '/admin/users')->route->path);
        self::assertSame([], $router->match('GET', '/outside')->route->middlewareList());
        self::assertSame('/admin', $router->url('admin.dashboard'));
    }

    public function testDuplicateNamesAreRejected(): void
    {
        $router = new Router();
        $router->get('/a', [stdClass::class, 'a'], 'same');

        $this->expectException(LogicException::class);

        $router->get('/b', [stdClass::class, 'b'], 'same');
    }

    public function testAnAliasNamesTheSameRouteForUrls(): void
    {
        $router = new Router();
        $route = $router->get('/{slug:[a-z]+}', [stdClass::class, 'show'], 'pages.show');
        $router->alias('sections.show', 'pages.show');

        self::assertSame($route, $router->named('sections.show'));
        self::assertSame('/guides', $router->url('sections.show', ['slug' => 'guides']));
        self::assertSame('pages.show', $router->match('GET', '/guides')->route->name);
        self::assertCount(1, $router->routes());

        $this->expectException(LogicException::class);
        $router->alias('pages.show', 'sections.show');
    }

    public function testAnAliasNeedsAnExistingRoute(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Router()->alias('sections.show', 'ghost');
    }

    public function testEmptyOrSlashPrefixGroupsDoNotProduceDeadRoutes(): void
    {
        foreach (['', '/'] as $prefix) {
            $router = new Router();
            $router->group($prefix, static function (Router $router): void {
                $router->get('/news', [stdClass::class, 'news'], 'news.index');
            });

            self::assertSame('/news', $router->match('GET', '/news')->route->path);
            self::assertSame('/news', $router->url('news.index'));
        }
    }

    public function testNestedGroupsComposePrefixes(): void
    {
        $router = new Router();
        $router->group('/a', static function (Router $router): void {
            $router->group('/b', static function (Router $router): void {
                $router->get('/c', [stdClass::class, 'c'], 'nested.c');
            });
        });

        self::assertSame('/a/b/c', $router->match('GET', '/a/b/c')->route->path);
        self::assertSame('/a/b/c', $router->url('nested.c'));
    }

    public function testInvalidUtf8PathIsNotFound(): void
    {
        $router = new Router();
        $router->get('/news/{slug}', [stdClass::class, 'news']);

        $this->expectException(NotFound::class);

        $router->match('GET', '/news/%C3%28');
    }
}
