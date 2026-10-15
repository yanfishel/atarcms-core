<?php

declare(strict_types=1);

namespace AtarCms\Admin;

/**
 * The page numbers a list links to: the first and the last page, the current page with one neighbour on each side,
 * and null where pages are left out ("‹ 1 … 4 5 6 … 12 ›"). A gap of a single page shows that page instead.
 */
final class Pagination
{
    /**
     * @return list<int|null> page numbers in order, null for "…"
     */
    public static function window(int $current, int $last): array
    {
        $last = max(1, $last);
        $current = max(1, min($current, $last));
        $shown = array_values(array_unique(array_filter(
            [1, $current - 1, $current, $current + 1, $last],
            static fn(int $page): bool => $page >= 1 && $page <= $last,
        )));
        sort($shown);

        $window = [];
        $previous = 0;
        foreach ($shown as $page) {
            if ($page - $previous === 2) {
                $window[] = $page - 1;
            } elseif ($page - $previous > 2) {
                $window[] = null;
            }
            $window[] = $page;
            $previous = $page;
        }

        return $window;
    }
}
