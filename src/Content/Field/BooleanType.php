<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * A checkbox: a missing input means false, so the value is always stored.
 */
final class BooleanType implements FieldType
{
    public function name(): string
    {
        return 'boolean';
    }

    public function normalize(mixed $input, Field $field): bool
    {
        return match (true) {
            is_bool($input) => $input,
            $input === null, $input === '', $input === '0', $input === 0 => false,
            $input === '1', $input === 'on', $input === 'true', $input === 1 => true,
            default => throw new FieldError('validation.field.invalid'),
        };
    }

    public function read(mixed $stored, Field $field): bool
    {
        return $stored === true;
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }
}
