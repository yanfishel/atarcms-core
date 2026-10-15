<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Taxonomy;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\View\ThemeConfig;

/**
 * The site's main menu when config/content.php declares 'main_menu' => ['home', 'news', 'contacts']: the theme's
 * "main" location then shows the sections (the hierarchical taxonomy routed at "/{slug}") with those collections
 * pinned among them, a single() linking to its page and any other to its archive. The admin's Sections screen orders
 * them; the pinned items' top-level positions are a site setting (locale-independent), the sections keep their own
 * order per locale. Without the key, "main" is an ordinary menu of the Menus screen.
 */
final class MainMenu
{
    public const string LOCATION = 'main';

    /**
     * @param list<Collection>|null $pinned null when the site has no main menu of sections
     */
    public function __construct(
        public readonly ?Taxonomy $sections,
        public readonly ?array $pinned,
    ) {}

    public static function fromConfig(Config $config, Collections $collections): self
    {
        $root = $collections->taxonomies->root();
        $sections = $root !== null && $root->isRootTree() ? $root : null;
        if (!$config->has('content.main_menu')) {
            return new self($sections, null);
        }
        $names = $config->get('content.main_menu');
        if (!is_array($names) || !array_is_list($names)) {
            throw new ConfigException('content.main_menu must be a list of collection names');
        }
        if ($sections === null) {
            throw new ConfigException('content.main_menu needs a hierarchical() taxonomy routed at "/{slug}": the sections the menu is made of');
        }
        $pinned = [];
        foreach ($names as $name) {
            $collection = is_string($name) ? $collections->find($name) : null;
            if ($collection === null) {
                throw new ConfigException(sprintf('content.main_menu names the unknown collection "%s"', is_string($name) ? $name : get_debug_type($name)));
            }
            if (isset($pinned[$collection->name])) {
                throw new ConfigException(sprintf('content.main_menu names the collection "%s" twice', $collection->name));
            }
            if (!($collection->single && $collection->route !== null) && $collection->archive === null) {
                throw new ConfigException(sprintf('content.main_menu: the collection "%s" needs a route() as a single() or an archive() to link to', $collection->name));
            }
            $pinned[$collection->name] = $collection;
        }

        return new self($sections, array_values($pinned));
    }

    public function enabled(): bool
    {
        return $this->pinned !== null;
    }

    /**
     * The theme's menu locations the Menus screen edits: all but "main" when it is the menu of sections.
     *
     * @return array<string, string> location => label
     */
    public function menuLocations(ThemeConfig $theme): array
    {
        return $this->enabled() ? array_diff_key($theme->menus, [self::LOCATION => true]) : $theme->menus;
    }

    /**
     * The top level of the menu: $sections in their order with the pinned collections placed at $positions (their
     * top-level indexes, SiteSettings::mainMenuOrder()), clamped to the end. Without stored positions the pinned
     * collections come first in config order; one missing from stored positions comes last.
     *
     * @template T
     *
     * @param list<T> $sections
     * @param array<string, int> $positions collection name => top-level index
     *
     * @return list<Collection|T>
     */
    public function topLevel(array $sections, array $positions): array
    {
        $pinned = [];
        foreach ($this->pinned ?? [] as $index => $collection) {
            $pinned[] = [$positions === [] ? $index : ($positions[$collection->name] ?? PHP_INT_MAX), $index, $collection];
        }
        sort($pinned);
        $items = $sections;
        foreach ($pinned as [$position, , $collection]) {
            array_splice($items, max(0, min($position, count($items))), 0, [$collection]);
        }

        return $items;
    }
}
