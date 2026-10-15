<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use InvalidArgumentException;

/**
 * Upload rules from config/media.php: the allowed types (a subset of TYPES), the size limit in bytes (videos have
 * their own, max_video_size), the pixel limit that is checked before an image is decoded (decompression bombs), the
 * longest side kept for images and the quality of re-encoded JPEG/WebP images.
 */
final readonly class MediaConfig
{
    /** Every type the uploader can handle => the extension of stored files. SVG is never supported (spec §6.6). */
    public const array TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    /** Names shown in the admin's upload help. */
    public const array LABELS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
        'image/gif' => 'GIF',
        'application/pdf' => 'PDF',
        'video/mp4' => 'MP4',
        'video/webm' => 'WebM',
    ];

    public const int DEFAULT_MAX_SIZE = 10485760;

    public const int DEFAULT_MAX_VIDEO_SIZE = 104857600;

    public const int DEFAULT_MAX_PIXELS = 25000000;

    public const int DEFAULT_MAX_DIMENSION = 4096;

    public const int DEFAULT_QUALITY = 82;

    /** @var list<string> */
    public array $types;

    /**
     * @param array<mixed> $types MIME types, a subset of the keys of TYPES
     */
    public function __construct(
        array $types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'video/mp4', 'video/webm'],
        public int $maxSize = self::DEFAULT_MAX_SIZE,
        public int $maxPixels = self::DEFAULT_MAX_PIXELS,
        public int $maxDimension = self::DEFAULT_MAX_DIMENSION,
        public int $quality = self::DEFAULT_QUALITY,
        public int $maxVideoSize = self::DEFAULT_MAX_VIDEO_SIZE,
    ) {
        if ($types === []) {
            throw new ConfigException('Config "media.types" must list at least one type');
        }
        $parsed = [];
        foreach ($types as $type) {
            if ($type === 'image/svg+xml') {
                throw new ConfigException('Config "media.types": SVG is not supported (SVG files can carry scripts)');
            }
            if (!is_string($type) || !isset(self::TYPES[$type])) {
                throw new ConfigException(sprintf(
                    'Config "media.types": unsupported type %s; use some of %s',
                    is_string($type) ? '"' . $type . '"' : get_debug_type($type),
                    implode(', ', array_keys(self::TYPES)),
                ));
            }
            $parsed[$type] = $type;
        }
        $this->types = array_values($parsed);
        if ($maxSize < 1) {
            throw new ConfigException('Config "media.max_size" must be at least 1 (bytes)');
        }
        if ($maxVideoSize < 1) {
            throw new ConfigException('Config "media.max_video_size" must be at least 1 (bytes)');
        }
        if ($maxPixels < 1) {
            throw new ConfigException('Config "media.max_pixels" must be at least 1');
        }
        if ($maxDimension < 16 || $maxDimension > 16384) {
            throw new ConfigException('Config "media.max_dimension" must be between 16 and 16384');
        }
        if ($quality < 1 || $quality > 100) {
            throw new ConfigException('Config "media.quality" must be between 1 and 100');
        }
    }

    public static function fromConfig(Config $config): self
    {
        $types = $config->get('media.types');
        if ($types !== null && (!is_array($types) || !array_is_list($types))) {
            throw new ConfigException('Config "media.types" must be a list of MIME types');
        }

        return new self(
            $types ?? array_keys(self::TYPES),
            $config->int('media.max_size', self::DEFAULT_MAX_SIZE),
            $config->int('media.max_pixels', self::DEFAULT_MAX_PIXELS),
            $config->int('media.max_dimension', self::DEFAULT_MAX_DIMENSION),
            $config->int('media.quality', self::DEFAULT_QUALITY),
            $config->int('media.max_video_size', self::DEFAULT_MAX_VIDEO_SIZE),
        );
    }

    public function allows(string $mime): bool
    {
        return in_array($mime, $this->types, true);
    }

    public function extension(string $mime): string
    {
        return self::TYPES[$mime] ?? throw new InvalidArgumentException(sprintf('Unsupported media type "%s"', $mime));
    }

    public function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    public function isVideo(string $mime): bool
    {
        return str_starts_with($mime, 'video/');
    }

    /**
     * @return list<string>
     */
    public function imageTypes(): array
    {
        return array_values(array_filter($this->types, $this->isImage(...)));
    }

    /**
     * @return list<string>
     */
    public function videoTypes(): array
    {
        return array_values(array_filter($this->types, $this->isVideo(...)));
    }

    /**
     * The size limit of a file of this type, in bytes: max_video_size for videos, max_size for the rest.
     */
    public function maxSizeOf(string $mime): int
    {
        return $this->isVideo($mime) ? $this->maxVideoSize : $this->maxSize;
    }

    /**
     * The largest file any allowed type may be (what PHP and the web server must let through).
     */
    public function largestUpload(): int
    {
        return $this->videoTypes() === [] ? $this->maxSize : max($this->maxSize, $this->maxVideoSize);
    }

    /**
     * @param list<string>|null $types the allowed types when null
     */
    public function labels(?array $types = null): string
    {
        return implode(', ', array_map(static fn(string $type): string => self::LABELS[$type] ?? $type, $types ?? $this->types));
    }

    /**
     * @param list<string>|null $types the allowed types when null
     */
    public function accept(?array $types = null): string
    {
        return implode(',', $types ?? $this->types);
    }

    /**
     * @param string|null $mime the limit of this type (maxSizeOf()); null for max_size
     */
    public function maxSizeMegabytes(?string $mime = null): float
    {
        return round(($mime === null ? $this->maxSize : $this->maxSizeOf($mime)) / 1048576, 1);
    }

    public function maxMegapixels(): float
    {
        return round($this->maxPixels / 1000000, 1);
    }
}
