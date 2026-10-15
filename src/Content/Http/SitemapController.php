<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\EntryUrls;
use AtarCms\Content\Sitemap;
use AtarCms\Content\SitemapPage;
use AtarCms\Content\TermUrls;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Http\Xml;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SiteSettings;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /sitemap.xml: every public entry of every collection with a route and every term of every taxonomy with a
 * route, in every locale, with xhtml:link alternates for their translations (plus x-default, the default locale's
 * URL, as in LocalizedUrls::hreflangs()). Past content.sitemap_limit URLs it becomes a sitemap index of
 * /sitemap-{entries|terms}-{source}-{n}.xml pages (see Sitemap).
 */
final class SitemapController
{
    private const string URLSET = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';

    public function __construct(
        private readonly Sitemap $sitemap,
        private readonly EntryUrls $urls,
        private readonly TermUrls $termUrls,
        private readonly Config $config,
        private readonly Responses $responses,
        private readonly Locales $locales,
        private readonly SiteSettings $settings,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->refuseWhileHidden($request);
        if ($this->sitemap->isIndexed()) {
            $base = $this->config->appUrl();
            $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
            foreach ($this->sitemap->pages() as $page) {
                $lines[] = '  <sitemap>';
                $lines[] = '    <loc>' . Xml::escape($base . $page->path()) . '</loc>';
                $lines[] = '  </sitemap>';
            }
            $lines[] = '</sitemapindex>';

            return $this->responses->xml(implode("\n", $lines) . "\n");
        }

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', self::URLSET];
        foreach ($this->sitemap->pages() as $page) {
            array_push($lines, ...$this->pageLines($page));
        }
        $lines[] = '</urlset>';

        return $this->responses->xml(implode("\n", $lines) . "\n");
    }

    /**
     * /sitemap-{kind}-{source}-{number}.xml: one page of the index; 404 while the site needs no index.
     */
    public function page(ServerRequestInterface $request): ResponseInterface
    {
        $this->refuseWhileHidden($request);
        $kind = $request->getAttribute('kind');
        $source = $request->getAttribute('source');
        $number = $request->getAttribute('number');
        $page = is_string($kind) && is_string($source) && is_string($number) && $this->sitemap->isIndexed()
            ? $this->sitemap->find($kind, $source, (int) $number)
            : null;
        if ($page === null) {
            throw new NotFound('No such sitemap page');
        }

        return $this->responses->xml(implode("\n", ['<?xml version="1.0" encoding="UTF-8"?>', self::URLSET, ...$this->pageLines($page), '</urlset>']) . "\n");
    }

    /**
     * @return list<string>
     */
    private function pageLines(SitemapPage $page): array
    {
        $groups = [];
        if ($page->kind === SitemapPage::ENTRIES) {
            ['entries' => $entries, 'known' => $known] = $this->sitemap->entries($page);
            $paths = $this->urls->paths($entries, $known);
            $base = $this->config->appUrl();
            foreach ($entries as $entry) {
                if (isset($paths[$entry->id])) {
                    $groups[$entry->translationGroup][$entry->locale] = [$base . $paths[$entry->id], $entry->updatedAt];
                }
            }
        } else {
            // The trails of nested sections come with one query for the whole page.
            $terms = $this->sitemap->terms($page);
            $paths = $this->termUrls->paths($terms);
            $base = $this->config->appUrl();
            foreach ($terms as $term) {
                if (isset($paths[$term->id])) {
                    $groups[$term->translationGroup][$term->locale] = [$base . $paths[$term->id], $term->updatedAt];
                }
            }
        }
        $lines = [];
        foreach ($groups as $urls) {
            array_push($lines, ...self::urlElements($urls, $this->locales->default()));
        }

        return $lines;
    }

    /**
     * @param array<string, array{0: string, 1: DateTimeImmutable}> $urls locale => [absolute URL, last change] of one page's translations
     *
     * @return list<string>
     */
    private static function urlElements(array $urls, string $defaultLocale): array
    {
        // hreflang => URL: every translation, plus x-default when the default locale is among them.
        $alternates = [];
        if (count($urls) > 1) {
            $alternates = array_map(static fn(array $url): string => $url[0], $urls);
            if (isset($alternates[$defaultLocale])) {
                $alternates['x-default'] = $alternates[$defaultLocale];
            }
        }
        $lines = [];
        foreach ($urls as [$url, $modified]) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . Xml::escape($url) . '</loc>';
            $lines[] = '    <lastmod>' . $modified->format(DATE_ATOM) . '</lastmod>';
            foreach ($alternates as $hreflang => $alternate) {
                $lines[] = sprintf('    <xhtml:link rel="alternate" hreflang="%s" href="%s"/>', Xml::escape($hreflang), Xml::escape($alternate));
            }
            $lines[] = '  </url>';
        }

        return $lines;
    }

    /**
     * A site hidden from search engines (the site settings) has no sitemap.
     */
    private function refuseWhileHidden(ServerRequestInterface $request): void
    {
        if ($this->settings->forRequest($request)->hideFromSearch) {
            throw new NotFound('The site is hidden from search engines');
        }
    }
}
