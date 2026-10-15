<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * One file of the media library of any allowed type (e.g. a PDF), stored as its id.
 */
final class FileType implements MediaFieldType
{
    public function name(): string
    {
        return 'file';
    }

    public function normalize(mixed $input, Field $field): ?int
    {
        return MediaIds::one($input);
    }

    public function read(mixed $stored, Field $field): ?int
    {
        return is_int($stored) && $stored > 0 ? $stored : null;
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }

    public function mediaIds(mixed $stored): array
    {
        return is_int($stored) && $stored > 0 ? [$stored] : [];
    }

    public function imagesOnly(): bool
    {
        return false;
    }
}
