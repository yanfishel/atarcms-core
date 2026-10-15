<?php

declare(strict_types=1);

namespace AtarCms\Http\Routing;

use AtarCms\Http\Exception\MethodNotAllowed;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\RequestPath;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Global middleware between ErrorMiddleware and SessionMiddleware: matches the route once, before the session
 * stack, so SessionMiddleware knows whether the route is stateless. RouteDispatcher reuses the match. A request that
 * matches nothing passes on unchanged; the dispatcher then answers 404/405 (or the trailing-slash redirect) inside
 * the full session stack, as before.
 */
final class RouteMatcher implements MiddlewareInterface
{
    public function __construct(private readonly Router $router) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $request = $request->withAttribute(RouteMatch::class, $this->router->matchPath($request->getMethod(), RequestPath::of($request)));
        } catch (NotFound|MethodNotAllowed) {
        }

        return $handler->handle($request);
    }
}
