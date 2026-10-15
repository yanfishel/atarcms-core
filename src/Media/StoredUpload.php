<?php

declare(strict_types=1);

namespace AtarCms\Media;

/**
 * A validated upload, written to storage/uploads/{path}; MediaService records it in the media table.
 */
final readonly class StoredUpload
{
    public function __construct(
        public string $path,
        public string $originalName,
        public string $mime,
        public int $size,
        public ?int $width,
        public ?int $height,
    ) {}
}
