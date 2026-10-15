<?php

declare(strict_types=1);

namespace AtarCms\Search;

/**
 * One page of search hits: the entry ids, most relevant first, and the number of all hits.
 */
final readonly class SearchPage
{
    /**
     * @param list<int> $ids
     */
    public function __construct(
        public array $ids,
        public int $total,
        public int $number,
        public int $perPage,
    ) {}

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
