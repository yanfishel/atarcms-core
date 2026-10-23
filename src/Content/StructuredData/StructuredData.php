<?php

declare(strict_types=1);

namespace AtarCms\Content\StructuredData;

use AtarCms\Content\Entry;
use AtarCms\Http\CanonicalUrl;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Config;
use AtarCms\Media\OgImage;
use AtarCms\Settings\SiteSettings;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The schema.org JSON-LD of a public page for search engines, printed by the socialMeta() theme helper after the Open
 * Graph tags: one <script type="application/ld+json"> data block with an @graph list. A data block is never executed,
 * so it needs no CSP nonce.
 */
final class StructuredData
{
    /**
     * "<" and ">" are escaped (JSON_HEX_TAG), so no text can close the element or open a comment; the line terminators
     * U+2028/U+2029 stay escaped; invalid UTF-8 is replaced instead of failing the page.
     */
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly Config $config,
        private readonly SiteSettings $settings,
        private readonly OgImage $images,
        private readonly Locales $locales,
    ) {}

    /**
     * The page's data block, or '' when it has nothing to say: only pages with a canonical URL (CanonicalUrl) get one.
     * The home page (the single() collection routed at "/") is the WebSite of its language (@id its URL + "#website"),
     * published by the site's Organization node (its contact details from the settings of the page's language); an
     * entry of an articleLike() collection is an Article whose author and publisher name that node; any other entry
     * page is a WebPage, and a page without an entry (an archive, a term page) a CollectionPage, both part of the
     * WebSite of their language; a page with an ancestor in its PageTrail adds a BreadcrumbList.
     *
     * @param Entry|null $entry the entry the page shows
     * @param string $siteName the site name in the page's language
     * @param string $title the page's title as og:title has it
     * @param string $description the page's description as og:description has it; '' for none
     * @param string|null $image the page's og:image URL
     */
    public function script(ServerRequestInterface $request, ?Entry $entry, string $siteName, string $title, string $description, ?string $image): string
    {
        $canonical = CanonicalUrl::of($request);
        if ($canonical === null) {
            return '';
        }
        $organization = Organization::fromConfig($this->config);
        $graph = [];
        if ($entry !== null && $entry->collection->single && $entry->collection->route === '/') {
            $graph[] = [
                '@type' => 'WebSite',
                '@id' => $canonical->url . '#website',
                'name' => $siteName,
                'url' => $canonical->url,
                'inLanguage' => $entry->locale,
                'publisher' => ['@id' => $organization->id()],
            ];
            $site = $this->settings->forRequest($request);
            $logoId = $site->logoId ?? $site->iconId;
            $graph[] = $organization->node($siteName, $logoId === null ? null : $this->images->byId($request, $logoId), $site);
        } elseif ($entry !== null && $entry->collection->articleLike()) {
            $graph[] = $this->article($entry, $canonical->url, $description, $image, $organization->reference($siteName));
        } else {
            $graph[] = $this->page(
                $entry === null ? 'CollectionPage' : 'WebPage',
                $title,
                $description,
                $canonical->url,
                $entry->locale ?? RequestLocale::of($request)->code,
                $siteName,
            );
        }
        $trail = PageTrail::of($request);
        if ($trail !== null && count($trail->items) >= 2) {
            $list = [];
            foreach ($trail->items as $index => $item) {
                $list[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $item['name'], 'item' => $item['url']];
            }
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
        }

        return self::encode($graph);
    }

    /**
     * The data block of $graph, or '' when it is empty.
     *
     * @param list<array<string, mixed>> $graph
     */
    public static function encode(array $graph): string
    {
        if ($graph === []) {
            return '';
        }

        return '<script type="application/ld+json">'
            . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], self::JSON_FLAGS)
            . '</script>';
    }

    /**
     * A stored (UTC) time as ISO 8601 ("2026-01-02T03:04:05+00:00"), like the sitemap's <lastmod>.
     */
    public static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    /**
     * The address of the home page in $locale, as its canonical URL has it ("https://example.com/",
     * "https://example.com/ru"): the WebSite of that language, whose @id is this URL + "#website".
     */
    private function homeUrl(string $locale): string
    {
        return $this->config->appUrl() . ($locale === $this->locales->default() ? '/' : '/' . $locale);
    }

    /**
     * A WebPage or CollectionPage that is part of the WebSite of its language (named by its @id, type, name and URL,
     * like an article's organization); the description is left out when empty.
     *
     * @return array<string, mixed>
     */
    private function page(string $type, string $title, string $description, string $url, string $locale, string $siteName): array
    {
        $page = ['@type' => $type, 'name' => $title];
        if ($description !== '') {
            $page['description'] = $description;
        }

        return [
            ...$page,
            'url' => $url,
            'inLanguage' => $locale,
            'isPartOf' => ['@type' => 'WebSite', '@id' => $this->homeUrl($locale) . '#website', 'name' => $siteName, 'url' => $this->homeUrl($locale)],
        ];
    }

    /**
     * Google's recommended Article fields; a scheduled entry edited before it went out counts as modified when it was
     * published.
     *
     * @param array<string, mixed> $site the organization as author and publisher (Organization::reference())
     *
     * @return array<string, mixed>
     */
    private function article(Entry $entry, string $url, string $description, ?string $image, array $site): array
    {
        $article = ['@type' => 'Article', 'headline' => $entry->title];
        if ($description !== '') {
            $article['description'] = $description;
        }
        if ($image !== null) {
            $article['image'] = $image;
        }
        $modified = $entry->updatedAt;
        // A public entry always has a publication time (Entry::isPublicAt()); only a page without one would lack it.
        if ($entry->publishedAt !== null) {
            $article['datePublished'] = self::time($entry->publishedAt);
            $modified = max($modified, $entry->publishedAt);
        }

        return [
            ...$article,
            'dateModified' => self::time($modified),
            'inLanguage' => $entry->locale,
            'url' => $url,
            'author' => $site,
            'publisher' => $site,
        ];
    }
}
