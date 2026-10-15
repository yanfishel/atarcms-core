<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures;

use AtarCms\Auth\Http\RequireAuthentication;
use AtarCms\Auth\Http\RequirePermission;
use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Module;

final class FixtureModule extends Module
{
    public function routes(Router $router): void
    {
        $router->get('/', [PageController::class, 'home'], 'home');
        $router->get('/news/{slug}', [PageController::class, 'news'], 'news.show');
        $router->get('/boom', [PageController::class, 'boom']);
        $router->get('/echo-query', [PageController::class, 'echoQuery'], 'echo.query');
        $router->get('/menus', [PageController::class, 'menus'], 'menus.demo');
        $router->get('/whoami', [PageController::class, 'whoami'], 'whoami')->middleware(RequireAuthentication::class);
        RequirePermission::on(
            $router->get('/secret', [PageController::class, 'whoami'], 'secret')->middleware(RequireAuthentication::class),
            'users.manage',
        );
    }

    public function adminTemplatePaths(): array
    {
        return [__DIR__ . '/admin-templates'];
    }
}
