<?php

declare(strict_types=1);

namespace AtarCms\Media;

/**
 * One page of the media library.
 */
final readonly class MediaPage
{
    /**
     * @param list<Media> $items
     */
    public function __construct(
        public array $items,
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
