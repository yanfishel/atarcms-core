<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;

/**
 * Slugs an entry or term must not take because its URL would be answered by another route. FastRoute answers static
 * routes first and tries variable routes with a longer literal prefix earlier (ContentModule::registerRoutes()), so a
 * route whose path has a literal segment equal to the slug right after the owner's prefix hides the page: "/news/feed"
 * hides the entry "feed" of "/news/{slug}", "/pages/topics/{slug}" hides the subpages of the page "topics" of
 * "/pages/{slug}". Under the root prefix ("/{slug}") locale codes are reserved too. It applies to collections and
 * taxonomies whose route ends with "{slug}"; for a hierarchical collection only top-level entries are checked (a
 * subpage's URL starts with its parent's). A few slugs are reserved that would still be reachable ("/news/topics"
 * next to "/news/topics/{slug}"): an editor picks another slug instead of getting an unreachable page. A literal
 * segment "{slug}.md" reserves the slug too: it would hide the entry's markdown twin ("/index.md" of a single at "/"
 * keeps a page from the slug "index"). Only literal
 * path segments are detected (not "{param}" or optional "[...]" segments), and POST-only routes reserve slugs too
 * (a harmless over-reservation). Under the root prefix, with a $locale, the slugs of the other owners of "/{slug}" in
 * that locale are reserved as well (RootSlugs: a page never takes a top-level section's slug, nor a top-level section a
 * page's); without $rootSlugs (route-only unit tests) that check is skipped. A term of a root taxonomy that is not
 * $topLevel (a subsection, "/about/team") reserves nothing: its URL starts with its parent's. A collection's preset
 * slugs (Collection::presets()) are reserved for its preset pages in every locale (EntryService keeps a preset's own
 * slug without asking), and RootSlugs keeps them from the other owners of "/{slug}".
 */
final class ReservedSlugs
{
    public function __construct(
        private readonly Router $router,
        private readonly Locales $locales,
        private readonly ?RootSlugs $rootSlugs = null,
    ) {}

    public function applies(Collection|Taxonomy $owner): bool
    {
        return $owner->route !== null && str_ends_with($owner->route, '{slug}');
    }

    public function isReserved(Collection|Taxonomy $owner, string $slug, ?string $locale = null, bool $topLevel = true): bool
    {
        if (!$this->applies($owner)) {
            return false;
        }
        $prefix = substr((string) $owner->route, 0, -strlen('{slug}'));
        if ($prefix === '/' && !$topLevel) {
            return false;
        }
        // A preset page's address belongs to it in every language, even before "bin/atar migrate" created it there.
        if ($owner instanceof Collection && $topLevel && isset($owner->presets[$slug])) {
            return true;
        }
        if ($prefix === '/' && $locale !== null && $this->rootSlugs?->taken($owner, $locale, $slug) === true) {
            return true;
        }

        return $this->byRoutes($owner, $slug);
    }

    /**
     * What keeps $owner's preset page $slug from its address in $locale: another route, a locale code or (under the
     * root prefix) another owner of "/{slug}" using it there, such as a top-level section made before the preset.
     */
    public function blocksPreset(Collection $owner, string $slug, string $locale): bool
    {
        $root = $owner->route === Taxonomy::ROOT_ROUTE;

        return ($root && $this->rootSlugs?->taken($owner, $locale, $slug) === true) || $this->byRoutes($owner, $slug);
    }

    /**
     * Whether another route answers $slug's address under $owner's prefix, or (under the root prefix) it is a locale
     * code: the part of isReserved() that does not depend on the content (ContentModule::checkPresets() at boot).
     */
    public function byRoutes(Collection|Taxonomy $owner, string $slug): bool
    {
        if (!$this->applies($owner)) {
            return false;
        }
        $prefix = substr((string) $owner->route, 0, -strlen('{slug}'));
        if ($prefix === '/' && in_array($slug, $this->locales->all(), true)) {
            return true;
        }
        foreach ($this->router->routes() as $route) {
            // A placeholder segment ("{slug:…}", the owner's own route included) never equals a slug. A literal
            // "{slug}.md" segment ("/index.md", the twin of a single at "/") would hide the entry's markdown twin.
            $segment = str_starts_with($route->path, $prefix) ? explode('/', substr($route->path, strlen($prefix)))[0] : null;
            if ($segment === $slug || $segment === $slug . '.md') {
                return true;
            }
        }

        return false;
    }
}
