<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Kernel;

use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Application;
use AtarCms\Tests\Functional\FunctionalTestCase;

/**
 * Every environment boots the same way, from the sources: production needs no build step.
 */
final class ProductionBootTest extends FunctionalTestCase
{
    public function testProductionServesPagesWithoutAnyBuildStep(): void
    {
        // The fixture site sets no app.env, so it runs as production; the subject is booting and serving pages
        // without any build step.
        $this->bootApp();

        $response = $this->browser->get('/');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testEveryRouteHandlerAndMiddlewareIsAutowired(): void
    {
        $container = $this->app->container();
        $classes = Application::MIDDLEWARE;
        foreach ($container->service(Router::class)->routes() as $route) {
            $classes[] = $route->handler[0];
            array_push($classes, ...$route->middlewareList());
        }

        foreach (array_unique($classes) as $class) {
            self::assertInstanceOf($class, $container->get($class), $class);
        }
    }
}
