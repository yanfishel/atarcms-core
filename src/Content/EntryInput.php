<?php

declare(strict_types=1);

namespace AtarCms\Content;

use DateTimeImmutable;

/**
 * The complete submitted state of an entry, before validation. Field values are raw input keyed by field name.
 */
final readonly class EntryInput
{
    /**
     * @param string $slug '' generates one from the title on create and for a draft, and keeps a published or scheduled entry's slug
     * @param array<string, mixed> $fields
     * @param array<string, list<int|string>>|null $terms taxonomy name => term ids, or tag names for a tags field's
     *                                                    taxonomy (missing tags are created); a missing taxonomy
     *                                                    keeps its terms, and null keeps them all (none on create)
     */
    public function __construct(
        public string $title,
        public string $slug = '',
        public EntryStatus $status = EntryStatus::Draft,
        public ?DateTimeImmutable $publishedAt = null,
        public ?int $parentId = null,
        public int $position = 0,
        public array $fields = [],
        public string $metaTitle = '',
        public string $metaDescription = '',
        public ?array $terms = null,
    ) {}

    /**
     * The stored state of an entry as input, e.g. to change one value and save the rest unchanged
     * (its terms stay as they are).
     */
    public static function fromEntry(Entry $entry): self
    {
        return new self(
            title: $entry->title,
            slug: $entry->slug,
            status: $entry->status,
            publishedAt: $entry->publishedAt,
            parentId: $entry->parentId,
            position: $entry->position,
            fields: $entry->data,
            metaTitle: $entry->seo->metaTitle ?? '',
            metaDescription: $entry->seo->metaDescription ?? '',
        );
    }
}
