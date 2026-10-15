<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use AtarCms\Http\Routing\Route;
use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Config;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Locale-aware URLs: routes are localizable unless they carry RequireDefaultLocale.
 */
final class LocalizedUrls
{
    public function __construct(
        private readonly Router $router,
        private readonly Locales $locales,
        private readonly Config $config,
    ) {}

    public static function isLocalized(Route $route): bool
    {
        return !in_array(RequireDefaultLocale::class, $route->middlewareList(), true);
    }

    /**
     * @param array<string, scalar> $params
     */
    public function path(string $name, array $params, string $locale): string
    {
        $path = $this->router->url($name, $params);
        if ($locale === $this->locales->default() || !self::isLocalized($this->router->named($name))) {
            return $path;
        }

        return '/' . $locale . ($path === '/' || str_starts_with($path, '/?') ? substr($path, 1) : $path);
    }

    /**
     * @return array<string, string> locale => absolute URL of the current page in that locale
     */
    public function alternates(ServerRequestInterface $request): array
    {
        $base = $this->config->appUrl();
        $override = $request->getAttribute(Alternates::class);
        if ($override instanceof Alternates) {
            $urls = [];
            foreach ($this->locales->all() as $locale) {
                if (isset($override->paths[$locale])) {
                    $urls[$locale] = $base . $override->paths[$locale];
                }
            }

            return count($urls) < 2 ? [] : $urls;
        }

        $match = $request->getAttribute(RouteMatch::class);
        $locales = $this->locales->all();
        if (!$match instanceof RouteMatch || $match->route->name === null || !self::isLocalized($match->route) || count($locales) < 2) {
            return [];
        }
        $urls = [];
        foreach ($locales as $locale) {
            $urls[$locale] = $base . $this->path($match->route->name, $match->params, $locale);
        }

        return $urls;
    }

    /**
     * The alternates plus "x-default" (the default locale's URL) when the default locale is among them.
     *
     * @return array<string, string> hreflang => absolute URL
     */
    public function hreflangs(ServerRequestInterface $request): array
    {
        $alternates = $this->alternates($request);
        $default = $this->locales->default();
        if (isset($alternates[$default])) {
            $alternates['x-default'] = $alternates[$default];
        }

        return $alternates;
    }
}
