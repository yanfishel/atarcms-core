<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

use AtarCms\Auth\User;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermService;
use AtarCms\Content\TreeNode;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;

/**
 * The order of the admin's Sections screen: the sections of one locale and, with a main menu (MainMenu), its pinned
 * collections among their top level. In the tree editor a pinned collection is the node -1, -2… (its index in
 * content.main_menu, negated, minus one). Saving moves the sections through TermService::reorder() and stores the
 * pinned collections' top-level indexes in the site settings (SettingsService::saveMainMenuOrder()).
 */
final class MainMenuService
{
    public function __construct(
        private readonly MainMenu $menu,
        private readonly TermService $terms,
        private readonly SettingsService $settings,
        private readonly SiteSettings $site,
    ) {}

    public static function pinnedId(int $index): int
    {
        return -1 - $index;
    }

    /**
     * The index in content.main_menu of the pinned collection with the tree editor id $id.
     */
    private static function pinnedIndex(int $id): int
    {
        return -1 - $id;
    }

    /**
     * The tree editor's tree: the sections nested as stored, the pinned collections at their places in the top level.
     *
     * @param list<Term> $terms the sections of one locale (TermRepository::forTaxonomy())
     *
     * @return list<TreeNode>
     */
    public function tree(array $terms): array
    {
        $pinned = [];
        foreach ($this->menu->pinned ?? [] as $index => $collection) {
            $pinned[$collection->name] = new TreeNode(self::pinnedId($index));
        }
        $nodes = [];
        foreach ($this->menu->topLevel(TreeOrder::nest($terms), $this->site->mainMenuOrder()) as $item) {
            $nodes[] = $item instanceof TreeNode ? $item : $pinned[$item->name];
        }

        return $nodes;
    }

    /**
     * A token for the stored order of the sections and of the pinned collections.
     *
     * @param list<Term> $terms
     */
    public function version(array $terms): string
    {
        return TreeOrder::version($terms) . '.' . sha1(json_encode($this->site->mainMenuOrder(), JSON_THROW_ON_ERROR));
    }

    /**
     * Saves the submitted tree of $locale: the pinned collections must stay at the top level without children
     * (validation.sections.pinned), the version must still match (validation.tree.stale), and the sections are checked by
     * TermService::reorder().
     *
     * @param list<TreeNode> $tree
     *
     * @throws ValidationFailed
     */
    public function reorder(Taxonomy $sections, string $locale, array $tree, string $version, ?User $actor, ?string $ip): void
    {
        [$termsVersion, $orderVersion] = [...explode('.', $version, 2), ''];
        $pinned = [];
        $sectionTree = [];
        foreach ($tree as $position => $node) {
            if ($node->id > 0) {
                $sectionTree[] = $node;
                if (self::holdsPinned($node->children)) {
                    throw new ValidationFailed(['tree' => 'validation.sections.pinned']);
                }

                continue;
            }
            $pinnedCollections = $this->menu->pinned ?? [];
            $collection = $pinnedCollections[self::pinnedIndex($node->id)] ?? throw new ValidationFailed(['tree' => 'validation.tree.invalid']);
            if ($node->children !== []) {
                throw new ValidationFailed(['tree' => 'validation.sections.pinned']);
            }
            $pinned[$collection->name] = $position;
        }
        if (count($pinned) !== count($this->menu->pinned ?? [])) {
            throw new ValidationFailed(['tree' => 'validation.tree.invalid']);
        }
        if (!hash_equals(sha1(json_encode($this->site->mainMenuOrder(), JSON_THROW_ON_ERROR)), $orderVersion)) {
            throw new ValidationFailed(['tree' => 'validation.tree.stale']);
        }
        $this->terms->reorder($sections, $locale, $sectionTree, $termsVersion, $actor, $ip);
        if ($pinned !== []) {
            $this->settings->saveMainMenuOrder($pinned, $actor, $ip);
        }
    }

    /**
     * @param list<TreeNode> $nodes
     */
    private static function holdsPinned(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node->id < 0 || self::holdsPinned($node->children)) {
                return true;
            }
        }

        return false;
    }
}
