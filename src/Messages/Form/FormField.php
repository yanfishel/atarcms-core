<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use DateTimeImmutable;

/**
 * One field of a site form as config/forms.php declares it. $options are the option keys of a select or radio field
 * (their labels come from the theme catalog); $min and $max are the bounds as written: for a date "today", "+N days"
 * or "Y-m-d", for a time "H:i". Date and time values are wall-clock strings, never converted between time zones.
 */
final readonly class FormField
{
    private const string RELATIVE_DATE = '/^(?:today|\+(\d{1,4}) days?)$/D';

    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $name,
        public FieldType $type,
        public bool $required = false,
        public array $options = [],
        public ?string $min = null,
        public ?string $max = null,
    ) {}

    /**
     * The lower bound as a value of the field ("2026-10-15", "09:00") for the site's $today ("Y-m-d"), or null.
     */
    public function minValue(string $today): ?string
    {
        return $this->min === null ? null : self::resolve($this->type, $this->min, $today);
    }

    /**
     * The upper bound as a value of the field for the site's $today ("Y-m-d"), or null.
     */
    public function maxValue(string $today): ?string
    {
        return $this->max === null ? null : self::resolve($this->type, $this->max, $today);
    }

    /**
     * Whether $bound is a valid bound for $type (date: "today", "+N days", a real "Y-m-d"; time: "H:i").
     */
    public static function isBound(FieldType $type, string $bound): bool
    {
        return match ($type) {
            FieldType::Date => preg_match(self::RELATIVE_DATE, $bound) === 1 || self::isDate($bound),
            FieldType::Time => self::isTime($bound),
            default => false,
        };
    }

    /**
     * Whether $value is a real date written "Y-m-d".
     */
    public static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Whether $value is a time of day written "H:i" (00:00–23:59).
     */
    public static function isTime(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value) === 1;
    }

    /**
     * The days after today a relative date bound means ("today" 0, "+5 days" 5), or null for an absolute one.
     */
    public static function relativeDays(string $bound): ?int
    {
        return preg_match(self::RELATIVE_DATE, $bound, $match) === 1 ? (int) ($match[1] ?? 0) : null;
    }

    private static function resolve(FieldType $type, string $bound, string $today): string
    {
        $days = $type === FieldType::Date ? self::relativeDays($bound) : null;
        if ($days === null) {
            return $bound;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $today) ?: throw new \InvalidArgumentException('Today must be "Y-m-d", got ' . $today);

        return $date->modify(sprintf('+%d days', $days))->format('Y-m-d');
    }
}
