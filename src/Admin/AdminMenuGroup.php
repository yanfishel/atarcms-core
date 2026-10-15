<?php

declare(strict_types=1);

namespace AtarCms\Admin;

/**
 * A group of the admin sidebar: the items of one AdminMenu order band under one heading. The dashboard band has no
 * heading; a band without items has no group, so "Modules" appears only when a module adds an item.
 */
final readonly class AdminMenuGroup
{
    /** The first order of each band that has a heading, with the heading's translation key; lower orders have none. */
    private const array BANDS = [
        AdminMenu::SETTINGS => 'admin.menu.group.settings',
        AdminMenu::MODULES => 'admin.menu.group.modules',
        AdminMenu::MEDIA => 'admin.menu.group.files',
        AdminMenu::TAXONOMIES => 'admin.menu.group.structure',
        AdminMenu::CONTENT => 'admin.menu.group.content',
    ];

    /**
     * @param string|null $heading a translation key, or null for the dashboard band
     * @param list<AdminMenuItem> $items
     */
    public function __construct(
        public ?string $heading,
        public array $items,
    ) {}

    /**
     * @param list<AdminMenuItem> $items in menu order (AdminMenu::items())
     *
     * @return list<self> in menu order
     */
    public static function of(array $items): array
    {
        /** @var array<string, array{heading: string|null, items: list<AdminMenuItem>}> $groups */
        $groups = [];
        foreach ($items as $item) {
            $heading = self::heading($item->order);
            $groups[$heading ?? ''] ??= ['heading' => $heading, 'items' => []];
            $groups[$heading ?? '']['items'][] = $item;
        }

        return array_values(array_map(static fn(array $group): self => new self($group['heading'], $group['items']), $groups));
    }

    private static function heading(int $order): ?string
    {
        foreach (self::BANDS as $from => $heading) {
            if ($order >= $from) {
                return $heading;
            }
        }

        return null;
    }
}
