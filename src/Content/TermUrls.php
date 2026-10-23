<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\I18n\LocalizedUrls;
use AtarCms\Kernel\Config;

/**
 * Route names and URLs of term pages. A term's URL uses its own slug, except in a hierarchical taxonomy routed at the
 * root (the sections): there it is the term's trail, its ancestors' slugs and its own joined by "/" ("about/team").
 * Lists build trails with ancestors() (one query for all their terms) or paths().
 */
final class TermUrls
{
    private const int MAX_DEPTH = 32;

    public function __construct(
        private readonly Taxonomies $taxonomies,
        private readonly LocalizedUrls $urls,
        private readonly Config $config,
        private readonly TermRepository $terms,
    ) {}

    public static function showRoute(Taxonomy $taxonomy): string
    {
        return 'taxonomy.' . $taxonomy->name . '.show';
    }

    /**
     * The route of the term page's markdown twin (ListingMarkdown): the show route's address + ".md".
     */
    public static function markdownRoute(Taxonomy $taxonomy): string
    {
        return 'taxonomy.' . $taxonomy->name . '.md';
    }

    /**
     * The router pattern of the markdown twins of $taxonomy's term pages: its pattern + ".md" (slugs never contain
     * dots). Null without a route.
     */
    public static function markdownPattern(Taxonomy $taxonomy): ?string
    {
        $pattern = $taxonomy->routePattern();

        return $pattern === null ? null : $pattern . '.md';
    }

    /**
     * The {slug} route parameter of the term: its slug, or its trail in a root tree. Ancestors missing from $known are
     * loaded with one query.
     *
     * @param array<int, Term> $known terms already loaded, by id (the term's taxonomy and locale, ancestors())
     */
    public function slugPath(Term $term, array $known = []): string
    {
        if ($term->parentId === null || $this->taxonomies->find($term->taxonomy)?->isRootTree() !== true) {
            return $term->slug;
        }
        $slugs = [$term->slug];
        $seen = [$term->id => true];
        $loaded = false;
        for ($parentId = $term->parentId; $parentId !== null && !isset($seen[$parentId]) && count($slugs) < self::MAX_DEPTH;) {
            if (!isset($known[$parentId]) && !$loaded) {
                $known += $this->terms->ancestors([$term->id]);
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
     * $terms and the ancestors their trails need, by id, with at most one query (none when no term of a root tree is
     * nested).
     *
     * @param array<Term> $terms
     *
     * @return array<int, Term>
     */
    public function ancestors(array $terms): array
    {
        $known = [];
        $nested = [];
        foreach ($terms as $term) {
            $known[$term->id] = $term;
            if ($term->parentId !== null && $this->taxonomies->find($term->taxonomy)?->isRootTree() === true) {
                $nested[] = $term->id;
            }
        }

        return $nested === [] ? $known : $known + $this->terms->ancestors($nested);
    }

    /**
     * The path of page $page of the term page in the term's locale ("?page=N" from page 2 on, like the pagination
     * links), or null when its taxonomy has no route.
     *
     * @param array<int, Term> $known see slugPath()
     * @param bool $markdown the path of the page's markdown twin (markdownRoute()) instead
     */
    public function path(Term $term, int $page = 1, array $known = [], bool $markdown = false): ?string
    {
        $taxonomy = $this->taxonomies->find($term->taxonomy);
        if ($taxonomy === null || $taxonomy->route === null) {
            return null;
        }
        $route = $markdown ? self::markdownRoute($taxonomy) : self::showRoute($taxonomy);

        return $this->urls->path($route, ['slug' => $this->slugPath($term, $known), ...($page > 1 ? ['page' => $page] : [])], $term->locale);
    }

    /**
     * The paths of many terms (a sitemap page, a term's translations) with at most one query.
     *
     * @param array<Term> $terms
     * @param bool $markdown the paths of the pages' markdown twins (markdownRoute()) instead
     *
     * @return array<int, string> term id => path; terms of taxonomies without a route are omitted
     */
    public function paths(array $terms, bool $markdown = false): array
    {
        $known = $this->ancestors($terms);
        $paths = [];
        foreach ($terms as $term) {
            $path = $this->path($term, 1, $known, $markdown);
            if ($path !== null) {
                $paths[$term->id] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param array<int, Term> $known see slugPath()
     */
    public function absolute(Term $term, int $page = 1, array $known = []): ?string
    {
        $path = $this->path($term, $page, $known);

        return $path === null ? null : $this->config->appUrl() . $path;
    }
}
