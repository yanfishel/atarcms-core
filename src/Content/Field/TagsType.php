<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use LogicException;

/**
 * Terms of a flat taxonomy (Field::$taxonomy), posted as comma-separated names (the admin's tag box, fields/tags.php
 * and tags.js, keeps them in one text input). The terms are stored in
 * entry_terms by EntryService, never in entries.data; themes read them with $this->terms($entry, 'tags').
 */
final class TagsType implements FieldType
{
    public function name(): string
    {
        return 'tags';
    }

    public function normalize(mixed $input, Field $field): null
    {
        return null;
    }

    public function read(mixed $stored, Field $field): never
    {
        throw new LogicException(sprintf(
            'The tags field "%s" holds terms, not a value; read them with $this->terms($entry, \'%s\')',
            $field->name,
            $field->taxonomy ?? $field->name,
        ));
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }

    /**
     * Tag names from a comma-separated string (or a list of strings): whitespace collapsed, trimmed, empty
     * names dropped and case-insensitive duplicates removed (the first spelling wins).
     *
     * @return list<string>
     */
    public static function names(mixed $input): array
    {
        $parts = match (true) {
            is_string($input) => explode(',', $input),
            is_array($input) => array_values(array_filter($input, is_string(...))),
            default => [],
        };
        $names = [];
        foreach ($parts as $part) {
            $name = self::cleanName($part);
            $key = mb_strtolower($name);
            if ($name !== '' && !isset($names[$key])) {
                $names[$key] = $name;
            }
        }

        return array_values($names);
    }

    /**
     * Collapses runs of whitespace and control characters into a single space and trims the ends. Shared with
     * TermService, which applies the same cleanup to a term name.
     */
    public static function cleanName(string $raw): string
    {
        return trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $raw));
    }
}
