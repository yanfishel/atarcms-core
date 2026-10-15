<?php

declare(strict_types=1);

namespace AtarCms\Http\Routing;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\Exception\MethodNotAllowed;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\MiddlewarePipeline;
use AtarCms\Http\RequestPath;
use AtarCms\Http\Responses;
use AtarCms\Http\SafeRedirect;
use AtarCms\Kernel\Container;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouteDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly Responses $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = RequestPath::of($request);

        // RouteMatcher has usually matched already (before the session middleware); match here otherwise.
        $match = RouteMatch::of($request);
        if ($match === null) {
            try {
                $match = $this->router->matchPath($request->getMethod(), $path);
            } catch (NotFound $notFound) {
                return $this->redirectWithoutTrailingSlash($request, $path) ?? throw $notFound;
            }
        }

        foreach ($match->route->attributes() as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }
        foreach ($match->params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        $request = $request->withAttribute(RouteMatch::class, $match);

        [$class, $method] = $match->route->handler;
        $controller = $this->container->get($class);
        if (!is_object($controller) || !method_exists($controller, $method)) {
            throw new LogicException(sprintf('Route handler %s::%s does not exist', $class, $method));
        }

        $handler = new CallableHandler(static function (ServerRequestInterface $request) use ($controller, $method): ResponseInterface {
            $response = $controller->{$method}($request);
            if (!$response instanceof ResponseInterface) {
                throw new LogicException(sprintf(
                    '%s::%s must return %s, got %s',
                    $controller::class,
                    $method,
                    ResponseInterface::class,
                    get_debug_type($response),
                ));
            }

            return $response;
        });
        $middleware = array_map(
            fn(string $id): MiddlewareInterface => $this->container->service($id),
            $match->route->middlewareList(),
        );

        return (new MiddlewarePipeline($middleware, $handler))->handle($request);
    }

    private function redirectWithoutTrailingSlash(ServerRequestInterface $request, RequestPath $path): ?ResponseInterface
    {
        $raw = $path->raw;
        if ($raw === '/' || !str_ends_with($raw, '/') || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $trimmed = rtrim($raw, '/');
        if ($trimmed === '' || SafeRedirect::sanitize($trimmed, '') !== $trimmed) {
            return null;
        }

        try {
            $this->router->match($request->getMethod(), $trimmed);
        } catch (NotFound|MethodNotAllowed) {
            return null;
        }
        $query = $request->getUri()->getQuery();

        return $this->responses->redirect($path->prefix . $trimmed . ($query === '' ? '' : '?' . $query), 301);
    }
}
