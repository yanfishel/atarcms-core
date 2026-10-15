<?php

declare(strict_types=1);

namespace AtarCms\Http;

use AtarCms\Kernel\Container;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * While the site settings hide the site from search engines (a site under construction), every public page asks them
 * not to index or follow it (X-Robots-Tag). The admin and the media files (/uploads/, /media/: nginx serves them
 * without PHP, and they read no database) are left alone, and a response that already has the header (the preview's
 * "noindex") keeps it. It sits inside ErrorMiddleware, so a broken database is an ordinary error page; error pages
 * carry no header (robots.txt disallows everything anyway). The settings come from the memoised
 * SiteSettings::forRequest(), the same read as the theme's siteSettings(); SiteSettings is resolved only then, since
 * building it connects to the database.
 */
final class SearchVisibilityMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Robots-Tag';

    public const string VALUE = 'noindex, nofollow';

    private const array SKIPPED = ['/admin/', '/uploads/', '/media/'];

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = $request->getUri()->getPath();
        // Error pages never read the settings: the database may be the error (and robots.txt keeps crawlers away).
        if ($path === '/admin' || $response->getStatusCode() >= 400 || $response->hasHeader(self::HEADER)) {
            return $response;
        }
        foreach (self::SKIPPED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $response;
            }
        }

        return $this->container->service(SiteSettings::class)->forRequest($request)->hideFromSearch
            ? $response->withHeader(self::HEADER, self::VALUE)
            : $response;
    }
}
