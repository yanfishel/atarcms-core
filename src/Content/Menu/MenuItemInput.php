<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

/**
 * The submitted state of a menu item, before validation. Only the value of the chosen target is used.
 */
final readonly class MenuItemInput
{
    /**
     * @param string $label '' shows the target's title (entry and term items only)
     * @param int|null $parentId the item it goes inside, null for the top level
     */
    public function __construct(
        public string $label,
        public MenuTarget $target,
        public ?int $entryId = null,
        public ?int $termId = null,
        public string $url = '',
        public bool $newTab = false,
        public ?int $parentId = null,
    ) {}
}
