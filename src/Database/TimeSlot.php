<?php

declare(strict_types=1);

namespace AtarCms\Database;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Stored UTC times grouped into 15-minute slots counted from 2000-01-01 00:00 UTC. Every time zone offset is a
 * multiple of 15 minutes, so a slot never straddles a local midnight: a query can count per slot in SQL (an
 * aggregate, without MariaDB's time zone tables) and the caller puts each slot on its site day (SiteTime).
 */
final class TimeSlot
{
    public const int MINUTES = 15;

    private const string EPOCH = '2000-01-01 00:00:00';

    /**
     * The SQL expression of $column's slot number ($column is a trusted, quoted or plain column name).
     */
    public static function sql(string $column): string
    {
        return sprintf("TIMESTAMPDIFF(MINUTE, '%s', %s) DIV %d", self::EPOCH, $column, self::MINUTES);
    }

    /**
     * The slot number of $time.
     */
    public static function of(DateTimeInterface $time): int
    {
        return (int) floor(($time->getTimestamp() - self::epoch()->getTimestamp()) / (60 * self::MINUTES));
    }

    /**
     * The UTC instant a slot starts at.
     */
    public static function start(int $slot): DateTimeImmutable
    {
        return self::epoch()->modify(sprintf('%+d minutes', $slot * self::MINUTES));
    }

    /**
     * The counts of a query that selects "slot" (sql()) and "n".
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, int> slot => count
     */
    public static function counts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[Row::int($row, 'slot')] = Row::int($row, 'n');
        }

        return $counts;
    }

    private static function epoch(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::EPOCH, new DateTimeZone('UTC'));
    }
}
