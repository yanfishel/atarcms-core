<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\I18n\LocalizedUrls;
use AtarCms\Kernel\Config;

/**
 * Route names and URLs of entries. Hierarchical collections put the ancestors' slugs in the {slug} parameter; a
 * route with a taxonomy placeholder ("/{sections}/{slug}") takes the trail of the entry's term of that taxonomy
 * (TermUrls::slugPath(): "about/team"), and an entry without one has no URL. Lists build their URLs with paths(), which
 * loads the terms and ancestors of all their entries at once.
 */
final class EntryUrls
{
    private const int MAX_DEPTH = 32;

    public function __construct(
        private readonly EntryRepository $entries,
        private readonly LocalizedUrls $urls,
        private readonly Config $config,
        private readonly TermRepository $terms,
        private readonly TermUrls $termUrls,
    ) {}

    public static function showRoute(Collection $collection): string
    {
        return 'content.' . $collection->name . '.show';
    }

    /**
     * The route of the entry page's markdown twin (EntryMarkdown): the show route's pattern + ".md".
     */
    public static function markdownRoute(Collection $collection): string
    {
        return 'content.' . $collection->name . '.md';
    }

    /**
     * The router pattern of the markdown twin of $collection's entry pages: its pattern + ".md" (slugs never contain
     * dots, so no address of another route gains one); a single at "/" answers at "/index.md". Null without a route.
     */
    public static function markdownPattern(Collection $collection): ?string
    {
        $pattern = $collection->routePattern();

        return $pattern === null ? null : ($pattern === '/' ? '/index.md' : $pattern . '.md');
    }

    public static function indexRoute(Collection $collection): string
    {
        return 'content.' . $collection->name . '.index';
    }

    /**
     * The route of the archive's markdown twin (ListingMarkdown), at archiveMarkdownPath().
     */
    public static function archiveMarkdownRoute(Collection $collection): string
    {
        return 'content.' . $collection->name . '.index.md';
    }

    /**
     * The address of the archive's markdown twin: the archive's path without a trailing slash + ".md" ("/news.md"); an
     * archive at "/" answers at "/index.md". Null without an archive.
     */
    public static function archiveMarkdownPath(Collection $collection): ?string
    {
        if ($collection->archive === null) {
            return null;
        }
        $path = rtrim($collection->archive, '/');

        return ($path === '' ? '/index' : $path) . '.md';
    }

    public static function feedRoute(Collection $collection): string
    {
        return 'content.' . $collection->name . '.feed';
    }

    /**
     * The {slug} route parameter: the slug, or "ancestor/…/slug" in a hierarchical collection. Ancestors missing
     * from $known are loaded with one query, whatever the depth.
     *
     * @param array<int, Entry> $known entries already loaded, by id
     */
    public function slugPath(Entry $entry, array $known = []): string
    {
        $slugs = [$entry->slug];
        $seen = [$entry->id => true];
        $parentId = $entry->collection->hierarchical ? $entry->parentId : null;
        $loaded = false;
        while ($parentId !== null && !isset($seen[$parentId]) && count($slugs) < self::MAX_DEPTH) {
            if (!isset($known[$parentId]) && !$loaded) {
                $known += $this->entries->ancestors([$entry->id]);
                $loaded = true;
            }
            $parent = $known[$parentId] ?? null;
            if ($parent === null) {
                break;
            }
            $seen[$parentId] = true;
            array_unshift($slugs, $parent->slug);
            $parentId = $parent->parentId;
        }

        return implode('/', $slugs);
    }

    /**
     * The entry's term of its route's taxonomy placeholder (one query); null without a placeholder or such a term.
     */
    public function routeTerm(Entry $entry): ?Term
    {
        return $this->routeTerms([$entry])[$entry->id] ?? null;
    }

    /**
     * The route terms (see routeTerm()) of many entries in one query; none when no entry's route has a placeholder.
     *
     * @param array<Entry> $entries
     *
     * @return array<int, Term> entry id => its route term; entries without one are omitted
     */
    public function routeTerms(array $entries): array
    {
        $taxonomies = [];
        foreach ($entries as $entry) {
            if ($entry->collection->routeTaxonomy !== null) {
                $taxonomies[$entry->id] = $entry->collection->routeTaxonomy;
            }
        }
        $found = [];
        foreach ($this->terms->forEntries(array_keys($taxonomies)) as $entryId => $terms) {
            foreach ($terms as $term) {
                if ($term->taxonomy === ($taxonomies[$entryId] ?? null)) {
                    $found[$entryId] ??= $term;
                }
            }
        }

        return $found;
    }

    /**
     * The entry's path in its own locale, or null when its collection has no route (or its route needs a term the
     * entry lacks).
     *
     * @param array<int, Entry> $known
     * @param Term|null $routeTerm the entry's route term when the caller has it; loaded otherwise
     * @param array<int, Term> $knownTerms the route term's ancestors when the caller has them (TermUrls::ancestors())
     * @param bool $markdown the path of the page's markdown twin (markdownRoute()) instead
     */
    public function path(Entry $entry, array $known = [], ?Term $routeTerm = null, array $knownTerms = [], bool $markdown = false): ?string
    {
        if ($entry->collection->routeTaxonomy !== null) {
            $routeTerm ??= $this->routeTerm($entry);
        }

        return $this->build($entry, $known, $routeTerm, $knownTerms, $markdown);
    }

    /**
     * The paths of many entries (a list, a menu, search results, a sitemap page) with at most three queries: one for
     * the ancestors of nested entries (none when $known already holds their parents), one for the route terms and one
     * for the ancestors of nested route terms (their trails).
     *
     * @param array<Entry> $entries
     * @param array<int, Entry> $known entries already loaded, by id (a sitemap page's ancestors)
     * @param bool $markdown the paths of the pages' markdown twins (markdownRoute()) instead
     *
     * @return array<int, string> entry id => path, in the order of $entries; entries without a URL are omitted
     */
    public function paths(array $entries, array $known = [], bool $markdown = false): array
    {
        $nested = [];
        foreach ($entries as $entry) {
            $known[$entry->id] = $entry;
        }
        foreach ($entries as $entry) {
            // A nested entry needs the ancestors query once its chain of parents leaves $known.
            $parentId = $entry->collection->hierarchical ? $entry->parentId : null;
            $seen = [];
            while ($parentId !== null && isset($known[$parentId]) && !isset($seen[$parentId])) {
                $seen[$parentId] = true;
                $parentId = $known[$parentId]->parentId;
            }
            if ($parentId !== null && !isset($seen[$parentId])) {
                $nested[] = $entry->id;
            }
        }
        if ($nested !== []) {
            $known += $this->entries->ancestors($nested);
        }
        $terms = $this->routeTerms($entries);
        $knownTerms = $this->termUrls->ancestors(array_values($terms));
        $paths = [];
        foreach ($entries as $entry) {
            $path = $this->build($entry, $known, $terms[$entry->id] ?? null, $knownTerms, $markdown);
            if ($path !== null) {
                $paths[$entry->id] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param array<int, Entry> $known
     * @param array<int, Term> $knownTerms
     */
    public function absolute(Entry $entry, array $known = [], ?Term $routeTerm = null, array $knownTerms = []): ?string
    {
        $path = $this->path($entry, $known, $routeTerm, $knownTerms);

        return $path === null ? null : $this->config->appUrl() . $path;
    }

    /**
     * The absolute URL of page $page of the collection's archive in $locale ("?page=N" from page 2 on, like the
     * pagination links), or null when the collection has no archive.
     */
    public function archive(Collection $collection, string $locale, int $page = 1): ?string
    {
        $path = $this->archivePath($collection, $locale, $page);

        return $path === null ? null : $this->config->appUrl() . $path;
    }

    /**
     * The path of page $page of the collection's archive in $locale (see archive()), or of its markdown twin
     * (archiveMarkdownRoute()) with $markdown; null without an archive.
     */
    public function archivePath(Collection $collection, string $locale, int $page = 1, bool $markdown = false): ?string
    {
        if ($collection->route === null || $collection->archive === null) {
            return null;
        }
        $route = $markdown ? self::archiveMarkdownRoute($collection) : self::indexRoute($collection);

        return $this->urls->path($route, $page > 1 ? ['page' => $page] : [], $locale);
    }

    /**
     * @param array<int, Entry> $known
     * @param array<int, Term> $knownTerms
     */
    private function build(Entry $entry, array $known, ?Term $routeTerm, array $knownTerms, bool $markdown): ?string
    {
        $collection = $entry->collection;
        if ($collection->route === null) {
            return null;
        }
        $route = $markdown ? self::markdownRoute($collection) : self::showRoute($collection);
        if ($collection->single) {
            return $this->urls->path($route, [], $entry->locale);
        }
        $params = ['slug' => $this->slugPath($entry, $known)];
        if ($collection->routeTaxonomy !== null) {
            if ($routeTerm === null || $routeTerm->taxonomy !== $collection->routeTaxonomy) {
                return null;
            }
            $params[$collection->routeTaxonomy] = $this->termUrls->slugPath($routeTerm, $knownTerms);
        }

        return $this->urls->path($route, $params, $entry->locale);
    }
}
