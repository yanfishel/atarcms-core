<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermUrls;
use AtarCms\I18n\Locales;
use AtarCms\I18n\LocalizedUrls;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Clock;
use AtarCms\Settings\SiteSettings;
use AtarCms\View\ThemeConfig;
use InvalidArgumentException;

/**
 * Public reads of menus for themes ($this->menu('main')). An entry item is shown only while its entry is public and
 * has a URL, a term item only while its taxonomy has a route; a hidden item hides its subitems. Targets are loaded in
 * batches: one query for the items, one for their entries, at most three for their URLs (EntryUrls::paths()) and one
 * for their terms with one more for their trails. With a main menu of sections (MainMenu), the "main" location is the
 * sections' tree in the menu's locale with the pinned collections among its top level: one query for the terms and
 * one for the stored order.
 */
final class PublicMenus
{
    public function __construct(
        private readonly MenuRepository $menus,
        private readonly ThemeConfig $theme,
        private readonly EntryRepository $entries,
        private readonly TermRepository $terms,
        private readonly EntryUrls $entryUrls,
        private readonly TermUrls $termUrls,
        private readonly Clock $clock,
        private readonly MainMenu $mainMenu,
        private readonly SiteSettings $settings,
        private readonly LocalizedUrls $urls,
        private readonly Locales $locales,
        private readonly Translator $translator,
    ) {}

    /**
     * @param string|null $currentPath the request's URL path ("/ru/about"); links to it are current
     *
     * @return list<MenuLink> the top level of the location's visible tree in $locale
     *
     * @throws InvalidArgumentException for a location the theme does not declare
     */
    public function tree(string $location, string $locale, ?string $currentPath = null): array
    {
        if (!$this->theme->hasMenu($location)) {
            throw new InvalidArgumentException(sprintf('Unknown menu location "%s"; declare it in the theme\'s theme.php', $location));
        }
        $current = $currentPath === null ? null : self::normalizePath($currentPath);
        if ($location === MainMenu::LOCATION && $this->mainMenu->enabled()) {
            return $this->sections($locale, $current);
        }
        $items = $this->menus->forLocation($location, $locale);
        if ($items === []) {
            return [];
        }
        $children = [];
        foreach ($items as $item) {
            $children[$item->parentId ?? 0][] = $item;
        }
        $seen = [];

        return $this->links($children, 0, $this->targets($items), $current, $seen);
    }

    /**
     * The main menu of sections: the term tree of $locale with the pinned collections placed among its top level.
     *
     * @return list<MenuLink>
     */
    private function sections(string $locale, ?string $current): array
    {
        $sections = $this->mainMenu->sections;
        $terms = [];
        foreach ($sections === null ? [] : $this->terms->forTaxonomy($sections->name, $locale) as $term) {
            $terms[$term->id] = $term;
        }
        $children = [];
        foreach ($terms as $term) {
            $children[$term->parentId !== null && isset($terms[$term->parentId]) ? $term->parentId : 0][] = $term;
        }
        $seen = [];
        $links = [];
        foreach ($this->mainMenu->topLevel($this->termLinks($children, 0, $current, $terms, $seen), $this->settings->mainMenuOrder()) as $item) {
            $links[] = $item instanceof Collection ? $this->pinnedLink($item, $locale, $current) : $item;
        }

        return $links;
    }

    /**
     * A pinned collection's link: a single() to its page, any other to its archive, labelled with the collection's
     * label in $locale (an untranslated collection links to its default-locale address). Its id is negative: menu ids
     * are term ids here. It is the ancestor of the current page below its address ("/news/hello" under "/news").
     */
    private function pinnedLink(Collection $collection, string $locale, ?string $current): MenuLink
    {
        $route = $collection->single ? EntryUrls::showRoute($collection) : EntryUrls::indexRoute($collection);
        $url = $this->urls->path($route, [], $collection->translatable ? $locale : $this->locales->default());
        $path = self::pathOf($url);
        $index = array_search($collection, $this->mainMenu->pinned ?? [], true);

        return new MenuLink(
            id: -1 - (is_int($index) ? $index : 0),
            label: $this->translator->label($locale, $collection->label),
            url: $url,
            newTab: false,
            current: $current !== null && $path === $current,
            ancestorOfCurrent: !$collection->single && $current !== null && $path !== null && $path !== '/' && str_starts_with($current, $path . '/'),
            children: [],
        );
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return array<int, array{label: string, url: string}> item id => its label and URL; hidden items are missing
     */
    private function targets(array $items): array
    {
        $entryIds = [];
        $termIds = [];
        foreach ($items as $item) {
            if ($item->target === MenuTarget::Entry && $item->entryId !== null) {
                $entryIds[$item->entryId] = $item->entryId;
            } elseif ($item->target === MenuTarget::Term && $item->termId !== null) {
                $termIds[$item->termId] = $item->termId;
            }
        }
        $now = $this->clock->now();
        $entries = array_filter($this->entries->byIds(array_values($entryIds)), static fn(Entry $entry): bool => $entry->isPublicAt($now));
        $paths = $this->entryUrls->paths($entries);
        $terms = $this->terms->byIds(array_values($termIds));
        $termPaths = $this->termUrls->paths($terms);

        $targets = [];
        foreach ($items as $item) {
            $title = '';
            $url = null;
            if ($item->target === MenuTarget::Entry) {
                $entry = $entries[$item->entryId ?? 0] ?? null;
                if ($entry !== null) {
                    $title = $entry->title;
                    $url = $paths[$entry->id] ?? null;
                }
            } elseif ($item->target === MenuTarget::Term) {
                $term = $terms[$item->termId ?? 0] ?? null;
                if ($term !== null) {
                    $title = $term->name;
                    $url = $termPaths[$term->id] ?? null;
                }
            } elseif ($item->target === MenuTarget::Url) {
                $url = $item->url;
            }
            if ($url !== null) {
                $targets[$item->id] = ['label' => $item->label !== '' ? $item->label : $title, 'url' => $url];
            }
        }

        return $targets;
    }

    /**
     * A term link is current on its own page, and the ancestor of the current page on the pages of its descendants
     * and of its entries ("/guides/php/hello" under "/guides/php").
     *
     * @param array<int, list<Term>> $children parent id (0 for the roots) => terms in order
     * @param array<int, Term> $known the terms by id (their trails)
     * @param array<int, true> $seen
     *
     * @return list<MenuLink>
     */
    private function termLinks(array $children, int $parentId, ?string $current, array $known, array &$seen): array
    {
        $links = [];
        foreach ($children[$parentId] ?? [] as $term) {
            $url = $this->termUrls->path($term, 1, $known);
            if (isset($seen[$term->id]) || $url === null) {
                continue;
            }
            $seen[$term->id] = true;
            $subitems = $this->termLinks($children, $term->id, $current, $known, $seen);
            $path = self::pathOf($url);
            $links[] = new MenuLink(
                id: $term->id,
                label: $term->name,
                url: $url,
                newTab: false,
                current: $current !== null && $path === $current,
                ancestorOfCurrent: ($current !== null && $path !== null && str_starts_with($current, rtrim($path, '/') . '/'))
                    || array_any($subitems, static fn(MenuLink $link): bool => $link->current || $link->ancestorOfCurrent),
                children: $subitems,
            );
        }

        return $links;
    }

    /**
     * @param array<int, list<MenuItem>> $children parent id (0 for the top level) => items in order
     * @param array<int, array{label: string, url: string}> $targets
     * @param array<int, true> $seen
     *
     * @return list<MenuLink>
     */
    private function links(array $children, int $parentId, array $targets, ?string $current, array &$seen): array
    {
        $links = [];
        foreach ($children[$parentId] ?? [] as $item) {
            // A hidden item hides its subitems too; $seen guards against a (corrupt) parent cycle.
            if (isset($seen[$item->id]) || !isset($targets[$item->id])) {
                continue;
            }
            $seen[$item->id] = true;
            $subitems = $this->links($children, $item->id, $targets, $current, $seen);
            $links[] = new MenuLink(
                id: $item->id,
                label: $targets[$item->id]['label'],
                url: $targets[$item->id]['url'],
                newTab: $item->newTab,
                current: $current !== null && self::pathOf($targets[$item->id]['url']) === $current,
                ancestorOfCurrent: array_any($subitems, static fn(MenuLink $link): bool => $link->current || $link->ancestorOfCurrent),
                children: $subitems,
            );
        }

        return $links;
    }

    /**
     * The comparable path of a site-relative URL (query and fragment dropped); null for absolute and other URLs.
     */
    private static function pathOf(string $url): ?string
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        return self::normalizePath(explode('#', explode('?', $url, 2)[0], 2)[0]);
    }

    private static function normalizePath(string $path): string
    {
        $path = rtrim(rawurldecode($path), '/');

        return $path === '' ? '/' : $path;
    }
}
