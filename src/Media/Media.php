<?php

declare(strict_types=1);

namespace AtarCms\Media;

use DateTimeImmutable;

/**
 * A file of the media library. Its public URLs come from MediaUrls; themes print images with img().
 */
final readonly class Media
{
    /**
     * @param string $path a MediaPath: "YYYY/MM/<32 hex>.<ext>" below storage/uploads
     * @param string $originalName the uploader's file name (base name only); user input, always escape it
     * @param array<string, string> $alt locale => alternative text; user input, always escape it
     * @param int|null $folderId the MediaFolder it is in; null for the root ("All files")
     */
    public function __construct(
        public int $id,
        public string $path,
        public string $originalName,
        public string $mime,
        public int $size,
        public ?int $width,
        public ?int $height,
        public array $alt,
        public ?int $uploadedBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?int $folderId = null,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime, 'video/');
    }

    /**
     * The alternative text in $locale; null when there is none.
     */
    public function alt(string $locale): ?string
    {
        $text = $this->alt[$locale] ?? '';

        return $text === '' ? null : $text;
    }

    public function extension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }
}
