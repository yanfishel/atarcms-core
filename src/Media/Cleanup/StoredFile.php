<?php

declare(strict_types=1);

namespace AtarCms\Media\Cleanup;

/**
 * One media file on disk, as MediaStorage::inventory() found it.
 */
final readonly class StoredFile
{
    /**
     * @param string $file the absolute file name
     * @param string $display the name below the storage directory, e.g. "uploads/2026/09/<32 hex>.jpg",
     *                        "cache/media/thumb/…", "tmp/uploads/…"
     * @param string|null $key what owns it: the media path of an original or a variant's original, the share image's
     *                         name; null for staged uploads and temporary files, which nothing owns
     * @param int $modified the modification time (a Unix timestamp)
     */
    public function __construct(
        public StoredFileKind $kind,
        public string $file,
        public string $display,
        public ?string $key,
        public int $modified,
    ) {}
}
