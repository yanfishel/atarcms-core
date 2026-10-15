<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use AtarCms\Database\Connection;
use Closure;

/**
 * Counts the SQL statements a piece of work sends on a connection (MariaDB's session "Questions" counter).
 */
final class QueryCounter
{
    /**
     * @param Closure(): mixed $work
     */
    public static function measure(Connection $db, Closure $work): int
    {
        $before = self::questions($db);
        $work();

        // The counter's own SHOW statement is counted too.
        return self::questions($db) - $before - 1;
    }

    private static function questions(Connection $db): int
    {
        $row = $db->selectOne("SHOW SESSION STATUS LIKE 'Questions'");

        return Cast::int(is_array($row) ? ($row['Value'] ?? 0) : 0);
    }
}
