<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    /**
     * @param list<int|null> $expected
     */
    #[DataProvider('windows')]
    public function testTheWindowShowsTheEndsAndTheNeighboursOfTheCurrentPage(int $current, int $last, array $expected): void
    {
        self::assertSame($expected, Pagination::window($current, $last));
    }

    /**
     * @return iterable<string, array{int, int, list<int|null>}>
     */
    public static function windows(): iterable
    {
        yield 'one page' => [1, 1, [1]];
        yield 'two pages' => [2, 2, [1, 2]];
        yield 'first of five' => [1, 5, [1, 2, null, 5]];
        yield 'middle of twelve' => [5, 12, [1, null, 4, 5, 6, null, 12]];
        yield 'a gap of one page shows the page' => [4, 12, [1, 2, 3, 4, 5, null, 12]];
        yield 'near the start' => [3, 12, [1, 2, 3, 4, null, 12]];
        yield 'last of twelve' => [12, 12, [1, null, 11, 12]];
        yield 'second to last' => [11, 12, [1, null, 10, 11, 12]];
        yield 'a page past the end is the last' => [40, 3, [1, 2, 3]];
        yield 'no pages count as one' => [1, 0, [1]];
    }
}
