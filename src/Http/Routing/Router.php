<?php

declare(strict_types=1);

namespace AtarCms\Http\Routing;

use AtarCms\Http\Exception\MethodNotAllowed;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\RequestPath;
use Closure;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Server\MiddlewareInterface;

use function FastRoute\simpleDispatcher;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    private ?Dispatcher $dispatcher = null;

    private string $prefix = '';

    /** @var list<class-string<MiddlewareInterface>> */
    private array $groupMiddleware = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function get(string $path, array $handler, ?string $name = null): Route
    {
        return $this->add(['GET'], $path, $handler, $name);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function post(string $path, array $handler, ?string $name = null): Route
    {
        return $this->add(['POST'], $path, $handler, $name);
    }

    /**
     * @param list<string> $methods
     * @param array{0: class-string, 1: string} $handler
     */
    public function add(array $methods, string $path, array $handler, ?string $name = null): Route
    {
        if ($this->prefix !== '') {
            $path = $path === '/' ? $this->prefix : $this->prefix . $path;
        }
        $route = new Route(array_map(strtoupper(...), $methods), $path, $handler, $name);
        if ($this->groupMiddleware !== []) {
            $route->middleware(...$this->groupMiddleware);
        }
        if ($name !== null) {
            if (isset($this->named[$name])) {
                throw new LogicException(sprintf('Duplicate route name "%s"', $name));
            }
            $this->named[$name] = $route;
        }
        $this->routes[] = $route;
        $this->dispatcher = null;

        return $route;
    }

    /**
     * @param Closure(self): void $define
     * @param class-string<MiddlewareInterface> ...$middleware
     */
    public function group(string $prefix, Closure $define, string ...$middleware): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;
        $trimmedPrefix = trim($prefix, '/');
        if ($trimmedPrefix !== '') {
            $this->prefix .= '/' . $trimmedPrefix;
        }
        $this->groupMiddleware = [...$this->groupMiddleware, ...array_values($middleware)];

        try {
            $define($this);
        } finally {
            $this->prefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    public function match(string $method, string $path): RouteMatch
    {
        return $this->matchPath($method, RequestPath::fromRaw($path));
    }

    public function matchPath(string $method, RequestPath $path): RouteMatch
    {
        if (!$path->valid) {
            throw new NotFound(sprintf('Invalid request path %s', $path->raw));
        }

        /** @var array{0: int, 1?: mixed, 2?: array<string, string>} $result */
        $result = $this->dispatcher()->dispatch(strtoupper($method), $path->decoded);

        $status = $result[0];

        if ($status === Dispatcher::FOUND) {
            /** @var int $routeIndex */
            $routeIndex = $result[1] ?? throw new NotFound(sprintf('No route for %s %s', $method, $path->raw));
            /** @var array<string, string> $params */
            $params = $result[2] ?? [];

            return new RouteMatch($this->routes[$routeIndex], $params);
        }

        if ($status === Dispatcher::METHOD_NOT_ALLOWED) {
            /** @var list<string> $allowedMethods */
            $allowedMethods = (isset($result[1]) && is_array($result[1])) ? array_values($result[1]) : [];

            throw new MethodNotAllowed($allowedMethods);
        }

        throw new NotFound(sprintf('No route for %s %s', $method, $path->raw));
    }

    /**
     * @param array<string, scalar> $params
     */
    public function url(string $name, array $params = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException(sprintf('Unknown route "%s"', $name));

        foreach (array_reverse((new Std())->parse($route->path)) as $segments) {
            $url = '';
            $used = [];
            foreach ($segments as $segment) {
                if (is_string($segment)) {
                    $url .= $segment;

                    continue;
                }
                if (!is_array($segment) || !isset($segment[0], $segment[1])) {
                    continue;
                }
                /** @var string $param */
                $param = $segment[0];
                /** @var string $regex */
                $regex = $segment[1];
                if (!array_key_exists($param, $params)) {
                    continue 2;
                }
                $value = (string) $params[$param];
                if (preg_match('~^(?:' . $regex . ')$~u', $value) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Parameter "%s" value "%s" does not match %s in route "%s"',
                        $param,
                        $value,
                        $regex,
                        $name,
                    ));
                }
                $url .= implode('/', array_map(rawurlencode(...), explode('/', $value)));
                $used[$param] = true;
            }
            $query = array_diff_key($params, $used);

            return $query === [] ? $url : $url . '?' . http_build_query($query);
        }

        throw new InvalidArgumentException(sprintf('Missing parameters for route "%s"', $name));
    }

    /**
     * A second name for the route named $name, for url() and named() only (matching reports the route's own name):
     * two owners of one path, such as a collection and a taxonomy both routed at "/{slug}", each build their URLs by
     * their own route name.
     */
    public function alias(string $alias, string $name): void
    {
        if (isset($this->named[$alias])) {
            throw new LogicException(sprintf('Duplicate route name "%s"', $alias));
        }
        $this->named[$alias] = $this->named($name);
    }

    public function named(string $name): Route
    {
        return $this->named[$name] ?? throw new InvalidArgumentException(sprintf('Unknown route "%s"', $name));
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    private function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as $index => $route) {
                $collector->addRoute($route->methods, $route->path, $index);
            }
        });
    }
}
