<?php

declare(strict_types=1);

namespace AtarCms\Database;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use UnexpectedValueException;

/**
 * Typed accessors for rows returned by Connection (DATETIME columns are UTC).
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::typeError($key, 'int', $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : self::int($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        throw self::typeError($key, 'string', $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $key): ?string
    {
        return ($row[$key] ?? null) === null ? null : self::string($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function dateTime(array $row, string $key): DateTimeImmutable
    {
        $value = self::string($row, $key);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw self::typeError($key, 'DATETIME', $value);
        }

        return $date;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableDateTime(array $row, string $key): ?DateTimeImmutable
    {
        return ($row[$key] ?? null) === null ? null : self::dateTime($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<mixed>
     */
    public static function json(array $row, string $key): array
    {
        $value = self::string($row, $key);
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException(sprintf('Column "%s" is not valid JSON', $key), 0, $e);
        }
        if (!is_array($decoded)) {
            throw self::typeError($key, 'JSON object or array', $decoded);
        }

        return $decoded;
    }

    private static function typeError(string $key, string $expected, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('Column "%s" must be %s, got %s', $key, $expected, get_debug_type($value)));
    }
}
