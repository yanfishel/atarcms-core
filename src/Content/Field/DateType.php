<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A calendar date, stored as "Y-m-d" (years 1000-9999, the MariaDB DATE range).
 */
final class DateType implements FieldType
{
    public function name(): string
    {
        return 'date';
    }

    public function normalize(mixed $input, Field $field): ?string
    {
        if (is_string($input)) {
            $input = trim($input);
        }
        if ($input === null || $input === '') {
            return null;
        }
        if (!is_string($input)) {
            throw new FieldError('validation.field.date');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $input || (int) $date->format('Y') < 1000) {
            throw new FieldError('validation.field.date');
        }

        return $input;
    }

    public function read(mixed $stored, Field $field): ?DateTimeImmutable
    {
        if (!is_string($stored)) {
            return null;
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $stored, new DateTimeZone('UTC')) ?: null;
    }

    public function columnType(Field $field): string
    {
        return 'DATE';
    }
}
