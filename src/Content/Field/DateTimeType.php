<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A wall-clock date and time without a time zone, stored as "Y-m-d H:i:s".
 * Accepts the HTML datetime-local format ("2026-10-01T18:30") and "Y-m-d H:i[:s]".
 */
final class DateTimeType implements FieldType
{
    private const array FORMATS = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'];

    public function name(): string
    {
        return 'datetime';
    }

    public function normalize(mixed $input, Field $field): ?string
    {
        if (is_string($input)) {
            $input = trim($input);
        }
        if ($input === null || $input === '') {
            return null;
        }
        if (is_string($input)) {
            foreach (self::FORMATS as $format) {
                $date = DateTimeImmutable::createFromFormat('!' . $format, $input, new DateTimeZone('UTC'));
                if ($date !== false && $date->format($format) === $input && (int) $date->format('Y') >= 1000) {
                    return $date->format('Y-m-d H:i:s');
                }
            }
        }

        throw new FieldError('validation.field.datetime');
    }

    public function read(mixed $stored, Field $field): ?DateTimeImmutable
    {
        if (!is_string($stored)) {
            return null;
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stored, new DateTimeZone('UTC')) ?: null;
    }

    public function columnType(Field $field): string
    {
        return 'DATETIME';
    }
}
