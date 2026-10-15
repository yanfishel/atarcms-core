<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * Images of the media library in a chosen order, stored as a list of unique ids; Field::max() limits the count.
 */
final class GalleryType implements MediaFieldType
{
    public function name(): string
    {
        return 'gallery';
    }

    /**
     * @return list<int>|null
     */
    public function normalize(mixed $input, Field $field): ?array
    {
        return MediaIds::many($input, $field);
    }

    /**
     * @return list<int>
     */
    public function read(mixed $stored, Field $field): array
    {
        return $this->mediaIds($stored);
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }

    public function mediaIds(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, static fn(mixed $id): bool => is_int($id) && $id > 0));
    }

    public function imagesOnly(): bool
    {
        return true;
    }
}
