<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * A field type that stores ids of the media library (image, gallery, file). EntryService checks that the ids exist
 * and records them in entry_media, so used files cannot be deleted.
 */
interface MediaFieldType extends FieldType
{
    /**
     * @return list<int> the media ids of a stored value
     */
    public function mediaIds(mixed $stored): array;

    /**
     * Whether only images may be chosen (otherwise any allowed file).
     */
    public function imagesOnly(): bool;
}
