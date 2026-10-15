<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * One page of a paginated list of entries.
 */
final readonly class EntryPage
{
    /**
     * @param list<Entry> $entries
     */
    public function __construct(
        public array $entries,
        public int $number,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->number > 1;
    }

    public function hasNext(): bool
    {
        return $this->number < $this->lastPage();
    }
}
