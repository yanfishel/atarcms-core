<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\I18n\Locales;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;

/**
 * What /sitemap.xml lists: the public entries of routed collections and the terms of routed taxonomies. Up to
 * content.sitemap_limit URLs (default and maximum 50 000, the protocol's limit) it is one urlset; above that it is
 * a sitemap index of pages. A page holds whole translation groups of one source, at most limit / locales groups,
 * so a page never exceeds the limit and hreflang alternates stay together.
 */
final class Sitemap
{
    public const int MAX_URLS = 50000;

    public const int MIN_URLS = 10;

    public function __construct(
        private readonly Collections $collections,
        private readonly PublicContent $content,
        private readonly TermRepository $terms,
        private readonly EntryRepository $entries,
        private readonly Locales $locales,
        private readonly int $limit = self::MAX_URLS,
    ) {
        if ($limit < self::MIN_URLS || $limit > self::MAX_URLS) {
            throw new ConfigException(sprintf('content.sitemap_limit must be between %d and %d, got %d', self::MIN_URLS, self::MAX_URLS, $limit));
        }
    }

    public static function fromConfig(
        Config $config,
        Collections $collections,
        PublicContent $content,
        TermRepository $terms,
        EntryRepository $entries,
        Locales $locales,
    ): self {
        return new self($collections, $content, $terms, $entries, $locales, $config->int('content.sitemap_limit', self::MAX_URLS));
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function groupsPerPage(): int
    {
        return max(1, intdiv($this->limit, max(1, count($this->locales->all()))));
    }

    /**
     * Every sitemap URL: public entries of routed collections plus terms of routed taxonomies.
     */
    public function total(): int
    {
        $total = 0;
        foreach ($this->routedCollections() as $collection) {
            $total += $this->content->publicCount($collection);
        }
        foreach ($this->routedTaxonomies() as $taxonomy) {
            $total += $this->terms->count($taxonomy->name);
        }

        return $total;
    }

    public function isIndexed(): bool
    {
        return $this->total() > $this->limit;
    }

    /**
     * @return list<SitemapPage> collections first, then taxonomies, in config order
     */
    public function pages(): array
    {
        $pages = [];
        foreach ($this->routedCollections() as $collection) {
            array_push($pages, ...$this->pagesOf(SitemapPage::ENTRIES, $collection->name, $this->content->publicGroupCount($collection)));
        }
        foreach ($this->routedTaxonomies() as $taxonomy) {
            array_push($pages, ...$this->pagesOf(SitemapPage::TERMS, $taxonomy->name, $this->terms->groupCount($taxonomy->name)));
        }

        return $pages;
    }

    /**
     * The page with that kind, source and number, or null when it does not exist.
     */
    public function find(string $kind, string $source, int $number): ?SitemapPage
    {
        if ($number < 1) {
            return null;
        }
        $groups = null;
        if ($kind === SitemapPage::ENTRIES) {
            $collection = $this->collections->find($source);
            $groups = $collection !== null && $collection->route !== null ? $this->content->publicGroupCount($collection) : null;
        } elseif ($kind === SitemapPage::TERMS) {
            $taxonomy = $this->collections->taxonomies->find($source);
            $groups = $taxonomy !== null && $taxonomy->route !== null ? $this->terms->groupCount($taxonomy->name) : null;
        }
        if ($groups === null || $number > (int) ceil($groups / $this->groupsPerPage())) {
            return null;
        }

        return new SitemapPage($kind, $source, $number);
    }

    /**
     * The public entries of an "entries" page, plus the ancestors of nested ones (for their paths), by id.
     *
     * @return array{entries: list<Entry>, known: array<int, Entry>}
     */
    public function entries(SitemapPage $page): array
    {
        $collection = $this->collections->get($page->source);
        $entries = $this->content->sitemapChunk($collection, ($page->number - 1) * $this->groupsPerPage(), $this->groupsPerPage());
        $known = [];
        foreach ($entries as $entry) {
            $known[$entry->id] = $entry;
        }
        $nested = [];
        foreach ($entries as $entry) {
            if ($collection->hierarchical && $entry->parentId !== null && !isset($known[$entry->parentId])) {
                $nested[] = $entry->id;
            }
        }

        return ['entries' => $entries, 'known' => $known + $this->entries->ancestors($nested)];
    }

    /**
     * The terms of a "terms" page. A taxonomy that redirectToFirstChild() leaves out its terms with children: their
     * URLs only redirect (the counts still include them, so such a page may hold fewer URLs).
     *
     * @return list<Term>
     */
    public function terms(SitemapPage $page): array
    {
        $terms = $this->terms->groupsChunk($page->source, ($page->number - 1) * $this->groupsPerPage(), $this->groupsPerPage());
        if ($this->collections->taxonomies->find($page->source)?->redirectToFirstChild !== true) {
            return $terms;
        }
        $parents = array_flip($this->terms->parentIds($page->source));

        return array_values(array_filter($terms, static fn(Term $term): bool => !isset($parents[$term->id])));
    }

    /**
     * @return list<SitemapPage>
     */
    private function pagesOf(string $kind, string $source, int $groups): array
    {
        $pages = [];
        for ($number = 1; $number <= (int) ceil($groups / $this->groupsPerPage()); $number++) {
            $pages[] = new SitemapPage($kind, $source, $number);
        }

        return $pages;
    }

    /**
     * @return list<Collection>
     */
    private function routedCollections(): array
    {
        return array_values(array_filter($this->collections->all(), static fn(Collection $collection): bool => $collection->route !== null));
    }

    /**
     * @return list<Taxonomy>
     */
    private function routedTaxonomies(): array
    {
        return array_values(array_filter($this->collections->taxonomies->all(), static fn(Taxonomy $taxonomy): bool => $taxonomy->route !== null));
    }
}
