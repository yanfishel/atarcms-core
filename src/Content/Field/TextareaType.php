<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

final class TextareaType implements FieldType
{
    public const int DEFAULT_MAX = 10000;

    public function name(): string
    {
        return 'textarea';
    }

    public function normalize(mixed $input, Field $field): ?string
    {
        $text = Text::clean($input, multiline: true);
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

    public function columnType(Field $field): ?string
    {
        return null;
    }
}
