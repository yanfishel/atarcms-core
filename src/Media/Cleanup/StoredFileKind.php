<?php

declare(strict_types=1);

namespace AtarCms\Media\Cleanup;

/**
 * The kinds of media files MediaStorage::inventory() recognises (see StoredFile).
 */
enum StoredFileKind: string
{
    /** uploads/YYYY/MM/<32 hex>.<ext>, owned by a media row with that path. */
    case Original = 'original';

    /** cache/media/{variant}/<image path>[.webp|.avif|.png], owned by the media row of its original. */
    case Variant = 'variant';

    /** uploads/share/{id}-{fingerprint}.jpg, owned by the entry whose share_image names it. */
    case Share = 'share image';

    /** tmp/uploads/<32 hex>, an upload being validated; never owned by anything. */
    case Staged = 'staged upload';

    /** .<name>.<12 hex>.tmp below uploads/ or cache/media/, an atomic write in progress; never owned. */
    case Temporary = 'temporary file';

    /**
     * The kind's name for $count files: "1 variant", "2 variants", "0 share images".
     */
    public function count(int $count): string
    {
        return $count . ' ' . $this->value . ($count === 1 ? '' : 's');
    }
}
