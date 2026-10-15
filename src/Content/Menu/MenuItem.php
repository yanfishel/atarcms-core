<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

use AtarCms\Content\TreeItem;
use DateTimeImmutable;

/**
 * One item of a menu location in one locale. Only the column of its target is set: entryId, termId or url (entryId
 * and termId become null when their entry or term is deleted). An empty label means "the target's title".
 */
final readonly class MenuItem implements TreeItem
{
    public function __construct(
        public int $id,
        public string $location,
        public string $locale,
        public ?int $parentId,
        public int $position,
        public string $label,
        public MenuTarget $target,
        public ?int $entryId,
        public ?int $termId,
        public ?string $url,
        public bool $newTab,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
