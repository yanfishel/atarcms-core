<?php

declare(strict_types=1);

namespace AtarCms\Media;

use DateTimeImmutable;

/**
 * A folder of the media library. Folders exist only in the database: a file's path and URL never depend on its folder.
 */
final readonly class MediaFolder
{
    /**
     * @param int|null $parentId null for a folder at the root ("All files")
     * @param string $name user input, always escape it
     */
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $name,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
