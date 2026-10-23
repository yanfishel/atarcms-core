<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Markdown\MarkdownTwin;
use AtarCms\Http\AcceptHeader;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Route;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The markdown side of the public pages that have a twin — entry and single pages (EntryMarkdown), collection
 * archives and term pages (ListingMarkdown) — shared by ContentController, TermController and RootController:
 * - a twin route ("{address}.md", twin()) is stateless and carries this class as a route attribute; a site hidden from
 *   search engines has no twins (requested() answers 404, like /llms.txt);
 * - the page's own address answers in markdown when the Accept header prefers text/markdown to text/html
 *   (negotiable() and prefersMarkdown(); never with "?preview=1" or while hidden);
 * - respond() sends a document: text/markdown with a Link to the canonical page, "X-Robots-Tag: noindex" at the twin's
 *   address, "Vary: Accept" at the page's (that address is the canonical page and stays indexable);
 * - page() finishes the HTML page: the Link header naming its twin (wherever markdownAlternate() prints its tag) and
 *   "Vary: Accept" when it negotiates.
 */
final readonly class MarkdownPages
{
    public function __construct(
        private Responses $responses,
        private SiteSettings $settings,
    ) {}

    /**
     * $route as a markdown twin: stateless and marked with this class's route attribute.
     */
    public static function twin(Route $route): Route
    {
        return $route->attribute(self::class, true)->stateless();
    }

    /**
     * Whether the matched route is a markdown twin, whatever the site's settings.
     */
    public static function isTwin(ServerRequestInterface $request): bool
    {
        return $request->getAttribute(self::class) === true;
    }

    /**
     * Whether the matched route is a markdown twin; a 404 while the site is hidden from search engines.
     *
     * @throws NotFound
     */
    public function requested(ServerRequestInterface $request): bool
    {
        if (!self::isTwin($request)) {
            return false;
        }
        if ($this->settings->forRequest($request)->hideFromSearch) {
            throw new NotFound('The site is hidden from search engines');
        }

        return true;
    }

    /**
     * Whether a page (not its twin) may answer in markdown when the Accept header prefers it: never while previewing
     * ("?preview=1") or while the site is hidden from search engines (it has no twins then).
     */
    public function negotiable(ServerRequestInterface $request): bool
    {
        return ($request->getQueryParams()['preview'] ?? null) !== '1' && !$this->settings->forRequest($request)->hideFromSearch;
    }

    /**
     * Whether the Accept header prefers text/markdown to text/html (a tie, "*\/*" and browsers' headers get HTML).
     */
    public static function prefersMarkdown(ServerRequestInterface $request): bool
    {
        return AcceptHeader::prefers($request->getHeaderLine('Accept'), 'text/markdown', 'text/html');
    }

    /**
     * The site's description in the request's language (Settings), which a twin's frontmatter falls back to; read
     * from the settings the request has loaded already (SiteSettings::forRequest()), no extra query.
     */
    public function siteDescription(ServerRequestInterface $request): string
    {
        return trim($this->settings->forRequest($request)->metaDescription ?? '');
    }

    /**
     * A page's markdown pointing to the page ($canonical, absolute) as canonical: at the twin's address ($twin) kept
     * out of search indexes, at the page's own address (negotiated) varying by Accept.
     */
    public function respond(string $markdown, string $canonical, bool $twin): ResponseInterface
    {
        $response = $this->responses->markdown($markdown)->withHeader('Link', '<' . $canonical . '>; rel="canonical"');

        return $twin ? $response->withHeader('X-Robots-Tag', 'noindex') : self::varyByAccept($response);
    }

    /**
     * The HTML page's response: with a markdown twin (MarkdownTwin on the request: never on previews) a Link header
     * names it, beside other Link values (SearchVisibilityMiddleware's describedby), unless the site is hidden from
     * search engines; a $negotiable page varies by Accept.
     */
    public function page(ServerRequestInterface $request, ResponseInterface $response, bool $negotiable): ResponseInterface
    {
        $twin = MarkdownTwin::of($request);
        if ($twin !== null && !$this->settings->forRequest($request)->hideFromSearch) {
            $response = $response->withAddedHeader('Link', $twin->link());
        }

        return $negotiable ? self::varyByAccept($response) : $response;
    }

    /**
     * Both variants of a negotiable page say that they depend on the Accept header (shared caches keep them apart).
     */
    private static function varyByAccept(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Vary', 'Accept');
    }
}
