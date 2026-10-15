<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TreeNode;
use AtarCms\Content\TreeOrder;
use AtarCms\Content\ValidationErrors;
use AtarCms\Database\Connection;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Clock;
use AtarCms\View\ThemeConfig;
use InvalidArgumentException;

/**
 * The only write path for menu items: validation of labels and targets, the tree order and the audit log.
 * Error values are translation keys; ICU parameters travel in ValidationFailed::$params.
 */
final class MenuService
{
    /** Managing menus (the admin "Menus" screens) needs this permission. */
    public const string PERMISSION = 'menus.manage';

    /** A top level with two levels of dropdowns. */
    public const int MAX_DEPTH = 3;

    public const int MAX_LABEL = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly MenuRepository $menus,
        private readonly EntryRepository $entries,
        private readonly TermRepository $terms,
        private readonly Taxonomies $taxonomies,
        private readonly ThemeConfig $theme,
        private readonly Locales $locales,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Adds an item at the end of the location's top level, or after the last child of the input's parent: an item of
     * the same location and locale (validation.menu.parent) under which the new item still fits MAX_DEPTH levels
     * (validation.tree.depth). The tree editor places it.
     *
     * @throws ValidationFailed
     */
    public function create(string $location, string $locale, MenuItemInput $input, ?User $actor, ?string $ip): MenuItem
    {
        $this->assertLocation($location);
        $errors = new ValidationErrors();
        if (!$this->locales->isSupported($locale)) {
            $errors->add('locale', 'validation.locale.unsupported');
        }
        $values = $this->values($locale, $input, $errors);
        $parentId = $input->parentId;
        if ($parentId !== null) {
            $this->checkParent($location, $locale, $parentId, null, $errors);
        }
        $errors->throwIfAny();

        return $this->db->transaction(function () use ($location, $locale, $parentId, $values, $actor, $ip): MenuItem {
            $now = $this->clock->now();
            $item = $this->mustFind($this->menus->insert([
                ...$values,
                'location' => $location,
                'locale' => $locale,
                'parent_id' => $parentId,
                'position' => $this->menus->nextPosition($location, $locale, $parentId),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $this->audit->record('menu_item.created', $actor?->id, 'menu_item', $item->id, self::auditMeta($item), $ip);

            return $item;
        });
    }

    /**
     * Saves the label, target, "new tab" flag and parent. An unchanged parent keeps the item's place; a new one (checked
     * like create()'s, and neither the item nor one of its subitems, with the whole subtree within MAX_DEPTH levels)
     * moves it with its subitems to the end of that parent's children, or of the top level.
     *
     * @throws ValidationFailed
     */
    public function update(MenuItem $item, MenuItemInput $input, ?User $actor, ?string $ip): MenuItem
    {
        $errors = new ValidationErrors();
        $values = $this->values($item->locale, $input, $errors);
        $moved = $input->parentId !== $item->parentId;
        if ($moved && $input->parentId !== null) {
            $this->checkParent($item->location, $item->locale, $input->parentId, $item, $errors);
        }
        $errors->throwIfAny();

        return $this->db->transaction(function () use ($item, $input, $moved, $values, $actor, $ip): MenuItem {
            $place = $moved
                ? ['parent_id' => $input->parentId, 'position' => $this->menus->nextPosition($item->location, $item->locale, $input->parentId)]
                : [];
            $this->menus->update($item->id, [...$values, ...$place, 'updated_at' => $this->clock->now()]);
            $updated = $this->mustFind($item->id);
            $meta = [...self::auditMeta($updated), ...($moved ? ['parent_id' => $updated->parentId] : [])];
            $this->audit->record('menu_item.updated', $actor?->id, 'menu_item', $updated->id, $meta, $ip);

            return $updated;
        });
    }

    /**
     * Deletes an item together with its subitems.
     *
     * @return int the number of subitems deleted with it
     */
    public function delete(MenuItem $item, ?User $actor, ?string $ip): int
    {
        return $this->db->transaction(function () use ($item, $actor, $ip): int {
            $subitems = $this->menus->descendantCount($item);
            $this->menus->delete($item->id);
            $this->audit->record('menu_item.deleted', $actor?->id, 'menu_item', $item->id, [...self::auditMeta($item), 'subitems' => $subitems], $ip);

            return $subitems;
        });
    }

    /**
     * Replaces the order and nesting of a location's items in one locale with $tree, atomically: the rows are locked,
     * $version must still match the stored order (validation.tree.stale), $tree must hold exactly those items
     * (validation.tree.invalid) at most MAX_DEPTH levels deep (validation.tree.depth). Moved items get a new
     * updated_at.
     *
     * @param list<TreeNode> $tree
     *
     * @return int the number of items that moved
     *
     * @throws ValidationFailed
     */
    public function reorder(string $location, string $locale, array $tree, string $version, ?User $actor, ?string $ip): int
    {
        $this->assertLocation($location);
        if (!$this->locales->isSupported($locale)) {
            throw new ValidationFailed(['locale' => 'validation.locale.unsupported']);
        }

        return $this->db->transaction(function () use ($location, $locale, $tree, $version, $actor, $ip): int {
            $items = $this->menus->forLocation($location, $locale, true);
            if (!hash_equals(TreeOrder::version($items), $version)) {
                throw new ValidationFailed(['tree' => 'validation.tree.stale']);
            }
            $placements = TreeOrder::placements($tree, array_map(static fn(MenuItem $item): int => $item->id, $items), self::MAX_DEPTH);
            $moved = TreeOrder::changed($items, $placements);
            $now = $this->clock->now();
            foreach ($moved as $item) {
                $this->menus->update($item->id, [
                    'parent_id' => $placements[$item->id]['parentId'],
                    'position' => $placements[$item->id]['position'],
                    'updated_at' => $now,
                ]);
            }
            if ($moved !== []) {
                $this->audit->record('menu.reordered', $actor?->id, 'menu', $location, ['location' => $location, 'locale' => $locale, 'moved' => count($moved)], $ip);
            }

            return count($moved);
        });
    }

    /**
     * @return array{label: string, target_type: string, entry_id: int|null, term_id: int|null, url: string|null, new_tab: bool}
     */
    private function values(string $locale, MenuItemInput $input, ValidationErrors $errors): array
    {
        $label = preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $input->label);
        if ($label === null) {
            $errors->add('label', 'validation.field.invalid');
            $label = '';
        }
        $label = trim($label);
        if ($label === '' && $input->target === MenuTarget::Url) {
            $errors->add('label', 'validation.field.required');
        } elseif (mb_strlen($label) > self::MAX_LABEL) {
            $errors->add('label', 'validation.field.too_long', ['max' => self::MAX_LABEL]);
        }

        $values = ['label' => $label, 'target_type' => $input->target->value, 'entry_id' => null, 'term_id' => null, 'url' => null, 'new_tab' => $input->newTab];
        if ($input->target === MenuTarget::Entry) {
            if (!$this->isLinkableEntry($input->entryId, $locale)) {
                $errors->add('entry_id', 'validation.menu.entry');
            }
            $values['entry_id'] = $input->entryId;
        } elseif ($input->target === MenuTarget::Term) {
            if (!$this->isLinkableTerm($input->termId, $locale)) {
                $errors->add('term_id', 'validation.menu.term');
            }
            $values['term_id'] = $input->termId;
        } else {
            $url = MenuUrl::normalize($input->url);
            if ($url === null) {
                $errors->add('url', 'validation.menu.url');
            }
            $values['url'] = $url;
        }

        return $values;
    }

    /**
     * The parent of a new item, or the new parent of $moving: an item of the same location and locale, neither $moving
     * nor one of its subitems (validation.menu.parent), under which the item and its subitems still fit MAX_DEPTH
     * levels (validation.tree.depth).
     */
    private function checkParent(string $location, string $locale, int $parentId, ?MenuItem $moving, ValidationErrors $errors): void
    {
        $parents = [];
        foreach ($this->menus->forLocation($location, $locale) as $item) {
            $parents[$item->id] = $item->parentId;
        }
        // The parent and its ancestors; the visited set stops at a (corrupt) cycle.
        $chain = [];
        for ($id = $parentId; $id !== null && array_key_exists($id, $parents) && !isset($chain[$id]); $id = $parents[$id]) {
            $chain[$id] = true;
        }
        if (!array_key_exists($parentId, $parents) || ($moving !== null && isset($chain[$moving->id]))) {
            $errors->add('parent_id', 'validation.menu.parent');

            return;
        }
        // The levels above the item (the chain) plus the levels of the item and its subitems.
        if (count($chain) + self::height($parents, $moving?->id) > self::MAX_DEPTH) {
            $errors->add('parent_id', 'validation.tree.depth', ['max' => self::MAX_DEPTH]);
        }
    }

    /**
     * How many levels an item and its subitems take (1 for a new item or one without subitems).
     *
     * @param array<int, int|null> $parents item id => parent id
     * @param array<int, true> $seen the items on the way down, to stop at a (corrupt) cycle
     */
    private static function height(array $parents, ?int $id, array $seen = []): int
    {
        if ($id === null || isset($seen[$id])) {
            return $id === null ? 1 : 0;
        }
        $seen[$id] = true;
        $height = 1;
        foreach ($parents as $child => $parent) {
            if ($parent === $id) {
                $height = max($height, 1 + self::height($parents, $child, $seen));
            }
        }

        return $height;
    }

    /**
     * An existing entry with a public route, in $locale (or, for a non-translatable collection, the default locale).
     */
    private function isLinkableEntry(?int $id, string $locale): bool
    {
        $entry = $id === null ? null : $this->entries->find($id);
        if ($entry === null || $entry->collection->route === null) {
            return false;
        }

        return $entry->locale === ($entry->collection->translatable ? $locale : $this->locales->default());
    }

    /**
     * An existing term in $locale whose taxonomy has a route.
     */
    private function isLinkableTerm(?int $id, string $locale): bool
    {
        $term = $id === null ? null : $this->terms->find($id);

        return $term !== null && $term->locale === $locale && $this->taxonomies->find($term->taxonomy)?->route !== null;
    }

    private function assertLocation(string $location): void
    {
        if (!$this->theme->hasMenu($location)) {
            throw new InvalidArgumentException(sprintf('Unknown menu location "%s"; declare it in the theme\'s theme.php', $location));
        }
    }

    private function mustFind(int $id): MenuItem
    {
        return $this->menus->find($id) ?? throw new NotFound(sprintf('Menu item #%d no longer exists', $id));
    }

    /**
     * @return array<string, mixed>
     */
    private static function auditMeta(MenuItem $item): array
    {
        return ['location' => $item->location, 'locale' => $item->locale, 'label' => $item->label, 'target' => $item->target->value];
    }
}
