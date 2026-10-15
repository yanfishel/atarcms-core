<?php

declare(strict_types=1);

namespace AtarCms\Content;

use DateTimeImmutable;
use Stringable;

/**
 * One term of a taxonomy in one locale. Stringable as its name, which is user input: escape it.
 */
final readonly class Term implements Stringable, TreeItem
{
    /**
     * @param Seo $seo the title and description for search engines of this locale; only the sections (the root tree)
     *                 have them, and their subsections and articles inherit them
     */
    public function __construct(
        public int $id,
        public string $taxonomy,
        public string $locale,
        public string $translationGroup,
        public string $slug,
        public string $name,
        public ?int $parentId,
        public int $position,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public Seo $seo = new Seo(),
    ) {}

    public function __toString(): string
    {
        return $this->name;
    }
}
