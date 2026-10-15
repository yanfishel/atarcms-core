<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * One value out of Field::$options, stored as a string.
 */
final class SelectType implements FieldType
{
    public function name(): string
    {
        return 'select';
    }

    public function normalize(mixed $input, Field $field): ?string
    {
        if (is_int($input)) {
            $input = (string) $input;
        }
        if (is_string($input)) {
            $input = trim($input);
        }
        if ($input === null || $input === '') {
            return null;
        }
        if (!is_string($input) || !array_key_exists($input, $field->options)) {
            throw new FieldError('validation.field.option');
        }

        return $input;
    }

    public function read(mixed $stored, Field $field): ?string
    {
        return is_string($stored) ? $stored : null;
    }

    public function columnType(Field $field): string
    {
        return 'VARCHAR(191)';
    }
}
