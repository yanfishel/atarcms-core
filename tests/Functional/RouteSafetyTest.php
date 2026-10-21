<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\Http\RequireAuthentication;
use AtarCms\Http\Routing\Route;
use AtarCms\Http\Routing\Router;

/**
 * Walks every route of the fixture application: the stateless opt-out must never reach a route that needs a user.
 */
final class RouteSafetyTest extends FunctionalTestCase
{
    public function testStatelessRoutesAreExactlyThePublicFileAndFeedRoutes(): void
    {
        $stateless = array_values(array_map(
            static fn(Route $route): string => (string) $route->name,
            array_filter($this->routes(), static fn(Route $route): bool => $route->isStateless()),
        ));
        sort($stateless);

        self::assertSame(
            [
                'assets.admin', 'assets.theme', 'content.articles.feed', 'content.articles.md', 'content.contact.md', 'content.events.feed',
                'content.events.md', 'content.info.md', 'content.llms', 'content.llms_full', 'content.pages.md', 'content.robots', 'content.sitemap',
                'content.sitemap.page', 'content.stories.feed', 'content.stories.md', 'content.welcome.md', 'doctor.probe', 'media.original',
                'media.share', 'media.variant', 'settings.favicon', 'settings.manifest',
            ],
            $stateless,
        );
    }

    public function testNoStatelessRouteNeedsAUser(): void
    {
        foreach ($this->routes() as $route) {
            if (!$route->isStateless()) {
                continue;
            }
            self::assertSame(['GET'], $route->methods, $route->path);
            self::assertFalse($route->path === '/admin' || str_starts_with($route->path, '/admin/'), $route->path);
            self::assertNotContains(RequireAuthentication::class, $route->middlewareList(), $route->path);
        }
    }

    public function testEveryAdminRouteExceptLoginRequiresAuthentication(): void
    {
        $checked = 0;
        foreach ($this->routes() as $route) {
            if (!($route->path === '/admin' || str_starts_with($route->path, '/admin/')) || str_starts_with($route->path, '/admin/login') || $route->path === '/admin/logout') {
                continue;
            }
            self::assertContains(RequireAuthentication::class, $route->middlewareList(), $route->path);
            self::assertFalse($route->isStateless(), $route->path);
            $checked++;
        }
        self::assertGreaterThan(20, $checked);
    }

    /**
     * @return list<Route>
     */
    private function routes(): array
    {
        return $this->app->container()->service(Router::class)->routes();
    }
}
