<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

final class TextType implements FieldType
{
    public const int DEFAULT_MAX = 255;

    public function name(): string
    {
        return 'text';
    }

    public function normalize(mixed $input, Field $field): ?string
    {
        $text = Text::clean($input, multiline: false);
        if ($text === '') {
            return null;
        }
        Text::assertLength($text, (int) ($field->max ?? self::DEFAULT_MAX));

        return $text;
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
