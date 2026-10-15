<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * Parses media ids from form input: an int or a numeric string for one id, a list or a comma-separated string for
 * several. Anything else is FieldError('validation.field.media').
 */
final class MediaIds
{
    private const int MAX_ID = 4294967295;

    public static function one(mixed $input): ?int
    {
        if (is_string($input)) {
            $input = trim($input);
            if ($input === '') {
                return null;
            }
            if (preg_match('/^[1-9]\d{0,9}$/', $input) === 1) {
                $input = (int) $input;
            }
        }
        if ($input === null) {
            return null;
        }
        if (!is_int($input) || $input < 1 || $input > self::MAX_ID) {
            throw new FieldError('validation.field.media');
        }

        return $input;
    }

    /**
     * @return list<int>|null unique ids in the given order; null when there are none
     *
     * @throws FieldError
     */
    public static function many(mixed $input, Field $field): ?array
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
            throw new FieldError('validation.field.media');
        }
        $ids = [];
        foreach ($input as $value) {
            $id = self::one($value);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return null;
        }
        if ($field->max !== null && count($ids) > $field->max) {
            throw new FieldError('validation.field.too_many', ['max' => $field->max]);
        }

        return array_values($ids);
    }
}
