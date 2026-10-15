<?php

declare(strict_types=1);

namespace AtarCms\Content;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One entry in one locale. Themes read field values with $entry->field('name').
 */
final readonly class Entry implements TreeItem
{
    /**
     * @param array<string, mixed> $data normalized field values; empty fields are absent
     * @param string|null $shareImage the file name of the generated share image (ShareImages), derived from the saved
     *                                entry after each save; null without one
     * @param string|null $preset the key of the collection's preset page this entry is (Collection::presets()); see isPreset()
     */
    public function __construct(
        public int $id,
        public Collection $collection,
        public string $locale,
        public string $translationGroup,
        public string $slug,
        public string $title,
        public EntryStatus $status,
        public ?DateTimeImmutable $publishedAt,
        public ?int $parentId,
        public int $position,
        public ?int $authorId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public Seo $seo,
        public array $data,
        public ?string $shareImage = null,
        public ?string $preset = null,
    ) {}

    /**
     * Whether this entry is a preset page of its collection (Collection::presets()): never deleted, its address fixed,
     * always published. A row whose key the configuration no longer declares is an ordinary page.
     */
    public function isPreset(): bool
    {
        return $this->preset !== null && isset($this->collection->presets[$this->preset]);
    }

    /**
     * This entry with another generated share image (ShareImages::sync()).
     */
    public function withShareImage(?string $shareImage): self
    {
        return new self(
            $this->id,
            $this->collection,
            $this->locale,
            $this->translationGroup,
            $this->slug,
            $this->title,
            $this->status,
            $this->publishedAt,
            $this->parentId,
            $this->position,
            $this->authorId,
            $this->createdAt,
            $this->updatedAt,
            $this->seo,
            $this->data,
            $shareImage,
            $this->preset,
        );
    }

    /**
     * The typed value of a field (see each FieldType::read()).
     */
    public function field(string $name): mixed
    {
        $field = $this->collection->field($name)
            ?? throw new InvalidArgumentException(sprintf('Collection "%s" has no field "%s"', $this->collection->name, $name));

        return $field->type->read($this->data[$name] ?? null, $field);
    }

    /**
     * The publishing rule: published or scheduled, with a publication date that has passed.
     */
    public function isPublicAt(DateTimeImmutable $now): bool
    {
        return $this->status !== EntryStatus::Draft && $this->publishedAt !== null && $this->publishedAt <= $now;
    }

    /**
     * The publishing rule as an SQL condition on the entries table and its bindings: the only SQL copy of
     * isPublicAt(), used by every public read (PublicContent, ContentQuery).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function publicSql(DateTimeImmutable $now): array
    {
        return ['status IN (?, ?) AND published_at <= ?', [...EntryStatus::PUBLIC, $now]];
    }
}
