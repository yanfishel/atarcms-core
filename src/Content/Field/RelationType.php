<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * Ids of entries of Field::$target, stored as a list of unique positive integers.
 * Accepts an array, a comma-separated string or a single id. Field::max() limits the count.
 */
final class RelationType implements FieldType
{
    public function name(): string
    {
        return 'relation';
    }

    /**
     * @return list<int>|null
     */
    public function normalize(mixed $input, Field $field): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }
        if (is_int($input)) {
            $input = [$input];
        }
        if (is_string($input)) {
            $input = explode(',', $input);
        }
        if (!is_array($input)) {
            throw new FieldError('validation.field.invalid');
        }
        $ids = [];
        foreach ($input as $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                if (preg_match('/^[1-9]\d{0,9}$/', $value) !== 1) {
                    throw new FieldError('validation.field.invalid');
                }
                $value = (int) $value;
            }
            if (!is_int($value) || $value < 1 || $value > 4294967295) {
                throw new FieldError('validation.field.invalid');
            }
            $ids[$value] = $value;
        }
        if ($ids === []) {
            return null;
        }
        if ($field->max !== null && count($ids) > $field->max) {
            throw new FieldError('validation.field.too_many', ['max' => $field->max]);
        }

        return array_values($ids);
    }

    /**
     * @return list<int>
     */
    public function read(mixed $stored, Field $field): array
    {
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, static fn(mixed $value): bool => is_int($value) && $value > 0));
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }
}
