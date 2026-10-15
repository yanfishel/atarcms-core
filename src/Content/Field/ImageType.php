<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * One image of the media library, stored as its id. Themes print it with img($media->get($entry->field('cover')), …).
 */
final class ImageType implements MediaFieldType
{
    public function name(): string
    {
        return 'image';
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
        return true;
    }
}
