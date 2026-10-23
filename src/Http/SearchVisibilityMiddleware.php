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
 * How the public site presents itself to search engines and AI agents. While the site settings hide the site from
 * search engines (a site under construction), every public page asks them not to index or follow it (X-Robots-Tag).
 * Otherwise every public HTML page (2xx, text/html) points agents to /llms.txt with
 * `Link: </llms.txt>; rel="describedby"`, added to a Link header the response already has; a hidden site has no
 * /llms.txt, so it gets none. A response that already has an X-Robots-Tag (the preview's "noindex", a form page) is
 * left as it is: it is no public page to index. The admin and the media files (/uploads/,
 * /media/: nginx serves them without PHP, and they read no database) are left alone. It sits inside ErrorMiddleware,
 * so a broken database is an ordinary error page; error pages carry neither header (robots.txt disallows everything
 * anyway). The settings come from the memoised SiteSettings::forRequest(), the same read as the theme's
 * siteSettings(); SiteSettings is resolved only when a header may be added, since building it connects to the
 * database.
 */
final class SearchVisibilityMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Robots-Tag';

    public const string VALUE = 'noindex, nofollow';

    public const string DESCRIBED_BY = '</llms.txt>; rel="describedby"';

    private const array SKIPPED = ['/admin/', '/uploads/', '/media/'];

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = $request->getUri()->getPath();
        // Error pages never read the settings: the database may be the error (and robots.txt keeps crawlers away). A
        // response with its own X-Robots-Tag (a preview, a form page, a markdown twin) is not a public page to index.
        if ($path === '/admin' || $response->getStatusCode() >= 400 || $response->hasHeader(self::HEADER)) {
            return $response;
        }
        foreach (self::SKIPPED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $response;
            }
        }
        if ($this->container->service(SiteSettings::class)->forRequest($request)->hideFromSearch) {
            return $response->withHeader(self::HEADER, self::VALUE);
        }
        $html = $response->getStatusCode() < 300 && str_starts_with($response->getHeaderLine('Content-Type'), 'text/html');

        return $html ? $response->withAddedHeader('Link', self::DESCRIBED_BY) : $response;
    }
}
