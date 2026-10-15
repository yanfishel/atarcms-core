<?php

declare(strict_types=1);

namespace AtarCms\Content\ShareImage;

use AtarCms\Content\Entry;
use AtarCms\Content\Field\MediaFieldType;

/**
 * An entry's main photo: the image of the first of its collection's share fields (Collection::shareImageFields())
 * that holds one, a gallery giving its first photo. The background of the generated share image and the og:image of a
 * page without one.
 */
final class MainPhoto
{
    /**
     * @return list<int> the first chosen media id of each share field, in order; the caller loads them and takes the
     *                   first image (image and gallery fields take images only, so normally the first one)
     */
    public static function candidates(Entry $entry): array
    {
        $ids = [];
        foreach ($entry->collection->shareImageFields() as $name) {
            $type = $entry->collection->field($name)?->type;
            if (!$type instanceof MediaFieldType) {
                continue;
            }
            $first = $type->mediaIds($entry->data[$name] ?? null)[0] ?? null;
            if ($first !== null && !in_array($first, $ids, true)) {
                $ids[] = $first;
            }
        }

        return $ids;
    }
}
