<?php

declare(strict_types=1);

namespace AtarCms\Http\Routing;

use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteMatch
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public Route $route,
        public array $params,
    ) {}

    /**
     * The match RouteMatcher (or RouteDispatcher) stored on the request, if any.
     */
    public static function of(ServerRequestInterface $request): ?self
    {
        $match = $request->getAttribute(self::class);

        return $match instanceof self ? $match : null;
    }
}
