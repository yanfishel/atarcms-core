<?php

declare(strict_types=1);

namespace AtarCms\Auth\Http;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Http\Routing\RequiresSession;
use AtarCms\Http\Routing\Route;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route guard; must come after RequireAuthentication. Register routes with RequirePermission::on().
 */
final class RequirePermission implements MiddlewareInterface, RequiresSession
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public static function on(Route $route, string $permission): Route
    {
        return $route->middleware(self::class)->attribute(self::class, $permission);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $permission = $request->getAttribute(self::class);
        if (!is_string($permission) || $permission === '') {
            throw new LogicException('RequirePermission needs a permission; register the route with RequirePermission::on()');
        }
        $this->authorizer->authorize(CurrentUser::of($request), $permission);

        return $handler->handle($request);
    }
}
