<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Routing;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Http\Routing\RouteMatcher;
use AtarCms\Http\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class RouteMatcherTest extends TestCase
{
    public function testStoresTheMatch(): void
    {
        $router = new Router();
        $router->get('/news/{slug}', [stdClass::class, 'news'], 'news.show');

        $match = $this->matched($router, 'GET', '/news/hello');

        self::assertNotNull($match);
        self::assertSame('news.show', $match->route->name);
        self::assertSame(['slug' => 'hello'], $match->params);
    }

    public function testUnmatchedRequestsPassOnUnchanged(): void
    {
        $router = new Router();
        $router->get('/news/{slug}', [stdClass::class, 'news']);

        self::assertNull($this->matched($router, 'GET', '/missing'));
        self::assertNull($this->matched($router, 'POST', '/news/hello'));
        self::assertNull($this->matched($router, 'GET', '/news/%FF'));
    }

    private function matched(Router $router, string $method, string $uri): ?RouteMatch
    {
        $seen = null;
        (new RouteMatcher($router))->process(
            (new Psr17Factory())->createServerRequest($method, $uri),
            new CallableHandler(static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = RouteMatch::of($request);

                return (new Psr17Factory())->createResponse();
            }),
        );

        return $seen;
    }
}
