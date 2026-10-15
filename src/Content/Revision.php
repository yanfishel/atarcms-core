<?php

declare(strict_types=1);

namespace AtarCms\Content;

use DateTimeImmutable;

final readonly class Revision
{
    /**
     * @param array<mixed> $snapshot the entry's columns and field data after the save (see RevisionRepository::snapshot())
     */
    public function __construct(
        public int $id,
        public int $entryId,
        public array $snapshot,
        public ?int $authorId,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Whether an autosave wrote this revision (RevisionRepository::recordAutosave()).
     */
    public function isAutosave(): bool
    {
        return ($this->snapshot['autosave'] ?? false) === true;
    }

    public function title(): string
    {
        $title = $this->snapshot['title'] ?? '';

        return is_string($title) ? $title : '';
    }
}
