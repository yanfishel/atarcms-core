<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\SiteTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SiteTimeTest extends TestCase
{
    public function testTheZoneComesFromConfigAndDefaultsToUtc(): void
    {
        self::assertSame('UTC', SiteTime::fromConfig(new Config())->zone->getName());
        self::assertSame('Europe/Berlin', SiteTime::fromConfig(new Config(['app' => ['timezone' => 'Europe/Berlin']]))->zone->getName());
        self::assertSame('Europe/Kiev', SiteTime::fromConfig(new Config(['app' => ['timezone' => 'Europe/Kiev']]))->zone->getName(), 'backward-compatible names are known identifiers too');
    }

    public function testRejectsAnythingButAKnownIdentifier(): void
    {
        foreach (['Mars/Olympus', '+02:00', 'CEST', 'utc', ''] as $zone) {
            try {
                SiteTime::fromConfig(new Config(['app' => ['timezone' => $zone]]));
                self::fail('Expected ConfigException for "' . $zone . '"');
            } catch (ConfigException $e) {
                self::assertStringContainsString('app.timezone', $e->getMessage());
            }
        }
    }

    public function testFormatsReadableTimesInTheSiteZoneAndTheLocale(): void
    {
        $time = new SiteTime(new DateTimeZone('Europe/Berlin'));
        $utc = new DateTimeImmutable('2026-10-03 23:41', new DateTimeZone('UTC'));

        self::assertSame('Oct 4, 2026, 01:41', $time->readable($utc, 'en'));
        self::assertSame("4 окт. 2026\u{202F}г., 01:41", $time->readable($utc, 'ru'));
        self::assertSame('', $time->readable(null, 'en'));
        self::assertSame('Oct 4, 2026', $time->readable($utc, 'en', false), 'the day alone');
        self::assertSame("4 окт. 2026\u{202F}г.", $time->readable($utc, 'ru', false));
    }

    public function testFormatsStoredUtcTimesInTheSiteZone(): void
    {
        $time = new SiteTime(new DateTimeZone('Europe/Berlin'));

        self::assertSame('2026-07-01 12:00', $time->format(new DateTimeImmutable('2026-07-01 10:00', new DateTimeZone('UTC')), 'Y-m-d H:i'));
        self::assertSame('2026-12-01 11:00', $time->format(new DateTimeImmutable('2026-12-01 10:00', new DateTimeZone('UTC')), 'Y-m-d H:i'));
        self::assertSame('', $time->format(null, 'Y-m-d H:i'));
    }

    public function testParsesLocalInputIntoUtc(): void
    {
        $time = new SiteTime(new DateTimeZone('Europe/Berlin'));
        $utc = static fn(?DateTimeImmutable $date): ?string => $date?->format('Y-m-d H:i e');

        self::assertSame('2026-07-01 10:00 UTC', $utc($time->parse('2026-07-01T12:00', 'Y-m-d\TH:i')));
        // A local time skipped by the spring change does not exist: invalid, never silently moved.
        self::assertNull($time->parse('2026-03-29T02:30', 'Y-m-d\TH:i'));
        // A local time repeated by the autumn change is read as the later instant (standard time), as PHP does.
        self::assertSame('2026-10-25 01:30 UTC', $utc($time->parse('2026-10-25T02:30', 'Y-m-d\TH:i')));
        foreach (['2026-02-30T10:00', '2026-07-01 12:00', '2026-07-01T12:00:00', ''] as $invalid) {
            self::assertNull($time->parse($invalid, 'Y-m-d\TH:i'), $invalid);
        }
    }
}
