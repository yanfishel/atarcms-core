<?php

declare(strict_types=1);

namespace AtarCms\Admin\Menu;

use AtarCms\Content\EntryRepository;
use AtarCms\Content\Menu\MenuItem;
use AtarCms\Content\Menu\MenuTarget;
use AtarCms\Content\PublicContent;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\TermRepository;

/**
 * How the admin names menu items (the tree editor's rows, the "Inside" select, dialog titles): the item's own label,
 * else its target's title (an entry's title, a term's name, the address), else "#id"; with the target and whether the
 * public menu leaves the item out. Entries and terms are loaded in one query each.
 */
final readonly class MenuItemLabels
{
    public function __construct(
        private EntryRepository $entries,
        private TermRepository $terms,
        private PublicContent $content,
        private Taxonomies $taxonomies,
    ) {}

    /**
     * @param list<MenuItem> $items
     *
     * @return array<int, array{label: string, target: string, hidden: bool}> item id => its names
     */
    public function of(array $items): array
    {
        $entryIds = [];
        $termIds = [];
        foreach ($items as $item) {
            if ($item->entryId !== null) {
                $entryIds[$item->entryId] = $item->entryId;
            }
            if ($item->termId !== null) {
                $termIds[$item->termId] = $item->termId;
            }
        }
        $entries = $this->entries->byIds(array_values($entryIds));
        $terms = $this->terms->byIds(array_values($termIds));

        $labels = [];
        foreach ($items as $item) {
            $hidden = false;
            if ($item->target === MenuTarget::Entry) {
                $entry = $entries[$item->entryId ?? 0] ?? null;
                $target = $entry === null ? '' : $entry->title;
                $hidden = $entry === null || $entry->collection->route === null || !$this->content->isPublic($entry);
            } elseif ($item->target === MenuTarget::Term) {
                $term = $terms[$item->termId ?? 0] ?? null;
                $target = $term === null ? '' : $term->name;
                $hidden = $term === null || $this->taxonomies->find($term->taxonomy)?->route === null;
            } else {
                $target = (string) $item->url;
            }
            $labels[$item->id] = [
                'label' => $item->label !== '' ? $item->label : ($target !== '' ? $target : '#' . $item->id),
                'target' => $target,
                'hidden' => $hidden,
            ];
        }

        return $labels;
    }

    public function label(MenuItem $item): string
    {
        return $this->of([$item])[$item->id]['label'];
    }
}
