<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;

/**
 * The site's time zone (app.timezone, default UTC) and the one place where times change zone: stored UTC times are
 * shown as local wall-clock times, and local input (the entry form's datetime-local) is read back into UTC. Storage
 * and every comparison (publishing, scheduling, sessions) stay in UTC.
 */
final readonly class SiteTime
{
    public const string DEFAULT = 'UTC';

    public function __construct(public DateTimeZone $zone) {}

    /**
     * @throws ConfigException unless app.timezone is a known time zone identifier ("Europe/Berlin", "UTC")
     */
    public static function fromConfig(Config $config): self
    {
        $name = $config->string('app.timezone', self::DEFAULT);
        if (!in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new ConfigException(sprintf('app.timezone must be a time zone identifier such as "Europe/Berlin" or "UTC"; got "%s"', $name));
        }

        return new self(new DateTimeZone($name));
    }

    /**
     * $time in the site zone, formatted with $format; '' for null.
     */
    public function format(?DateTimeInterface $time, string $format): string
    {
        return $time === null ? '' : DateTimeImmutable::createFromInterface($time)->setTimezone($this->zone)->format($format);
    }

    /**
     * $time in the site zone as a short date with a 24-hour time in $locale's own order ("Oct 4, 2026, 01:41",
     * "4 окт. 2026 г., 01:41"), or the day alone without $withTime ("Oct 4, 2026"); '' for null.
     */
    public function readable(?DateTimeInterface $time, string $locale, bool $withTime = true): string
    {
        if ($time === null) {
            return '';
        }
        $pattern = new IntlDatePatternGenerator($locale)->getBestPattern($withTime ? 'yMMMdHHmm' : 'yMMMd');
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $this->zone, null, (string) $pattern);

        return (string) $formatter->format($time);
    }

    /**
     * The site's date at the instant $now ("Y-m-d"): what "today" means for a visitor of the site.
     */
    public function today(DateTimeInterface $now): string
    {
        return $this->format($now, 'Y-m-d');
    }

    /**
     * A wall-clock date "Y-m-d" (a form's date field, never moved between zones) as a short date in $locale's own
     * order ("Oct 20, 2026", "20 окт. 2026 г."); $date itself unless it is a real date.
     */
    public function readableWallDate(string $date, string $locale): string
    {
        $utc = new DateTimeZone('UTC');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $utc);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            return $date;
        }
        $pattern = new IntlDatePatternGenerator($locale)->getBestPattern('yMMMd');

        return (string) new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $utc, null, (string) $pattern)->format($day);
    }

    /**
     * The UTC instant of the local wall-clock time $value, or null unless $value is exactly in $format and exists in
     * the site zone. A time skipped when the clocks go forward is null (not moved silently); a time repeated when they
     * go back is read as the later instant (standard time), as PHP does.
     */
    public function parse(string $value, string $format): ?DateTimeImmutable
    {
        $local = DateTimeImmutable::createFromFormat('!' . $format, $value, $this->zone);

        return $local !== false && $local->format($format) === $value ? $local->setTimezone(new DateTimeZone('UTC')) : null;
    }
}
