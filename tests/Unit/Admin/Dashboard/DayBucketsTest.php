<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin\Dashboard;

use AtarCms\Admin\Dashboard\DayBuckets;
use AtarCms\Database\TimeSlot;
use AtarCms\Kernel\SiteTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DayBucketsTest extends TestCase
{
    public function testAWeekOfDaysEndsTodayInTheSiteZone(): void
    {
        $buckets = self::buckets('UTC', '2026-10-10 12:00', 7);

        self::assertSame(['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'], $buckets->days());
        self::assertSame('2026-10-04 00:00:00', self::utc($buckets->since()));
        self::assertSame(1, $buckets->step());
        self::assertCount(7, $buckets->columns());
        self::assertSame(['2026-10-10'], $buckets->columns()[6]);
    }

    public function testTodayIsTheSiteDayNotTheUtcDay(): void
    {
        // 23:30 UTC is already the 11th in Jerusalem (UTC+3 in October) and still the 10th in New York.
        $east = self::buckets('Asia/Jerusalem', '2026-10-10 23:30', 7);
        $west = self::buckets('America/New_York', '2026-10-11 02:30', 7);

        self::assertSame('2026-10-11', $east->days()[6]);
        self::assertSame('2026-10-04 21:00:00', self::utc($east->since()), 'the local midnight, in UTC');
        self::assertSame('2026-10-10', $west->days()[6]);
        self::assertSame('2026-10-04 04:00:00', self::utc($west->since()));
    }

    public function testSlotsAreCountedOnTheirSiteDay(): void
    {
        $buckets = self::buckets('Asia/Jerusalem', '2026-10-10 12:00', 7);
        $slot = static fn(string $utc): int => TimeSlot::of(new DateTimeImmutable($utc, new DateTimeZone('UTC')));

        $counts = $buckets->count([
            $slot('2026-10-09 20:45') => 2, // 23:45 local: the 9th
            $slot('2026-10-09 21:00') => 3, // 00:00 local: the 10th
            $slot('2026-10-10 11:15') => 1,
            $slot('2026-09-20 10:00') => 9, // before the window: ignored
        ]);

        self::assertSame([0, 0, 0, 0, 0, 2, 4], $counts);
    }

    public function testADaylightSavingChangeKeepsWholeDays(): void
    {
        // Europe/Berlin leaves summer time on 2026-10-25: that day has 25 hours.
        $buckets = self::buckets('Europe/Berlin', '2026-10-27 09:00', 7);
        $slot = static fn(string $utc): int => TimeSlot::of(new DateTimeImmutable($utc, new DateTimeZone('UTC')));

        self::assertSame('2026-10-20 22:00:00', self::utc($buckets->since()), 'midnight in summer time is 22:00 UTC');
        self::assertSame([0, 0, 0, 0, 1, 1, 0], $buckets->count([
            $slot('2026-10-24 22:30') => 1, // 00:30 on the 25th (summer time)
            $slot('2026-10-25 23:15') => 1, // 00:15 on the 26th (winter time)
        ]));
    }

    public function testNinetyDaysAreThirteenWeeksTheFirstOneShort(): void
    {
        $buckets = self::buckets('UTC', '2026-10-10 12:00', 90);
        $columns = $buckets->columns();

        self::assertSame(7, $buckets->step());
        self::assertCount(90, $buckets->days());
        self::assertCount(13, $columns);
        self::assertCount(6, $columns[0]);
        self::assertSame('2026-07-13', $columns[0][0]);
        self::assertSame(['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'], $columns[12]);
        self::assertSame(30, count(self::buckets('UTC', '2026-10-10 12:00', 30)->columns()), 'thirty days stay daily');
    }

    public function testOnlyTheKnownRangesAreTaken(): void
    {
        self::assertSame([7, 30, 90], DayBuckets::RANGES);
        self::assertSame(30, DayBuckets::range('14'));
        self::assertSame(30, DayBuckets::range(null));
        self::assertSame(30, DayBuckets::range(['7']));
        self::assertSame(7, DayBuckets::range('7'));
        self::assertSame(90, DayBuckets::range('90'));
    }

    public function testDayLabelsAreWallClockDatesInTheLanguage(): void
    {
        self::assertSame('Oct 3', DayBuckets::label('2026-10-03', 'en'));
        self::assertSame('3 окт.', DayBuckets::label('2026-10-03', 'ru'));
        self::assertSame('Saturday, October 3', DayBuckets::label('2026-10-03', 'en', 'MMMMEEEEd'));
    }

    private static function buckets(string $zone, string $nowUtc, int $days): DayBuckets
    {
        return new DayBuckets(new SiteTime(new DateTimeZone($zone)), new DateTimeImmutable($nowUtc, new DateTimeZone('UTC')), $days);
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
