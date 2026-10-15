<?php

declare(strict_types=1);

namespace AtarCms\Admin\Dashboard;

use AtarCms\Database\TimeSlot;
use AtarCms\Kernel\SiteTime;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;

/**
 * The days a dashboard chart shows: the site's last N days ending today (site time), as columns of one day (7 and 30
 * days) or of a week (90 days: 13 columns, the last ending today, the first holding the days left over). Counts per
 * 15-minute UTC slot (TimeSlot) are put on their site day through SiteTime.
 */
final readonly class DayBuckets
{
    /** The ranges the activity chart offers; any other request shows the default. */
    public const array RANGES = [7, 30, 90];

    public const int DEFAULT = 30;

    /** @var list<string> the days, oldest first ("Y-m-d", site time) */
    private array $days;

    public function __construct(
        private SiteTime $time,
        private DateTimeImmutable $now,
        private int $length,
    ) {
        $today = new DateTimeImmutable($this->time->format($this->now, 'Y-m-d'), new DateTimeZone('UTC'));
        $days = [];
        for ($back = $this->length - 1; $back >= 0; $back--) {
            $days[] = $today->modify(sprintf('-%d days', $back))->format('Y-m-d');
        }
        $this->days = $days;
    }

    /**
     * The range of a query value: one of RANGES, else the default.
     */
    public static function range(mixed $value): int
    {
        foreach (self::RANGES as $range) {
            if ($value === (string) $range) {
                return $range;
            }
        }

        return self::DEFAULT;
    }

    /**
     * A day ("Y-m-d", a wall-clock date: no zone conversion) in $locale's words, by an ICU skeleton ("Oct 3").
     */
    public static function label(string $day, string $locale, string $skeleton = 'MMMd'): string
    {
        $pattern = (string) new IntlDatePatternGenerator($locale)->getBestPattern($skeleton);
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, $pattern);

        return (string) $formatter->format(new DateTimeImmutable($day, new DateTimeZone('UTC')));
    }

    /**
     * @return list<string>
     */
    public function days(): array
    {
        return $this->days;
    }

    /**
     * The UTC instant of the first day's local midnight (01:00 where the clocks skip midnight).
     */
    public function since(): DateTimeImmutable
    {
        return $this->time->parse($this->days[0] . ' 00:00', 'Y-m-d H:i')
            ?? $this->time->parse($this->days[0] . ' 01:00', 'Y-m-d H:i')
            ?? $this->now;
    }

    /**
     * Days per column: 1, or 7 for the long range.
     */
    public function step(): int
    {
        return $this->length > 30 ? 7 : 1;
    }

    /**
     * @return list<list<string>> the days of each column, oldest first
     */
    public function columns(): array
    {
        $step = $this->step();
        $columns = [];
        for ($end = count($this->days); $end > 0; $end -= $step) {
            $columns[] = array_slice($this->days, max(0, $end - $step), min($step, $end));
        }

        return array_reverse($columns);
    }

    /**
     * @param array<int, int> $slots slot number => count
     *
     * @return list<int> the count of each column; slots outside the days are left out
     */
    public function count(array $slots): array
    {
        $column = [];
        foreach ($this->columns() as $index => $days) {
            foreach ($days as $day) {
                $column[$day] = $index;
            }
        }
        $counts = array_fill(0, count($this->columns()), 0);
        foreach ($slots as $slot => $count) {
            $day = $this->time->format(TimeSlot::start($slot), 'Y-m-d');
            if (isset($column[$day])) {
                $counts[$column[$day]] += $count;
            }
        }

        return array_values($counts);
    }
}
