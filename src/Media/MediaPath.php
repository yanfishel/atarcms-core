<?php

declare(strict_types=1);

namespace AtarCms\Media;

/**
 * The only path shapes the media code accepts: "YYYY/MM/<32 hex>.<ext>" for stored files and, for variants, an
 * image path optionally followed by ".webp", ".avif" or ".png". Anything else (traversal, other extensions, upper-case hex) is refused
 * before it reaches the file system.
 */
final class MediaPath
{
    public const string PATTERN = '[0-9]{4}/(?:0[1-9]|1[0-2])/[0-9a-f]{32}\.(?:jpg|png|webp|gif|pdf|mp4|webm)';

    public const string VARIANT_PATTERN = '[0-9]{4}/(?:0[1-9]|1[0-2])/[0-9a-f]{32}\.(?:jpg|png|webp|gif)(?:\.webp|\.png|\.avif)?';

    /** A generated share image below uploads/share/: "{entry id}-{16 hex fingerprint}.jpg" (ShareImages). */
    public const string SHARE_PATTERN = '[1-9][0-9]{0,9}-[0-9a-f]{16}\.jpg';

    private const array MIMES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    public static function isValid(string $path): bool
    {
        return preg_match('~^' . self::PATTERN . '$~D', $path) === 1;
    }

    public static function isShareName(string $name): bool
    {
        return preg_match('~^' . self::SHARE_PATTERN . '$~D', $name) === 1;
    }

    /**
     * The type of a valid path's file, from its extension (which the uploader derived from the detected type).
     */
    public static function mime(string $path): string
    {
        return self::MIMES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    public static function isImage(string $path): bool
    {
        return str_starts_with(self::mime($path), 'image/');
    }

    public static function isVideo(string $path): bool
    {
        return str_starts_with(self::mime($path), 'video/');
    }

    /**
     * Splits a variant file name into the original's path and the format asked for (null = the original's):
     * "…/x.jpg" => ["…/x.jpg", null], "…/x.jpg.webp" => ["…/x.jpg", "image/webp"], "…/x.webp" => ["…/x.webp", null],
     * "…/x.jpg.png" => ["…/x.jpg", "image/png"] (the site icon's variants), "…/x.webp.avif" => ["…/x.webp", "image/avif"].
     *
     * @return array{0: string, 1: string|null}|null null for anything that is not a variant of a stored image
     */
    public static function variantSource(string $requested): ?array
    {
        if (preg_match('~^' . self::VARIANT_PATTERN . '$~D', $requested) !== 1) {
            return null;
        }
        // The pattern allows exactly one dot in the name, or two for "<name>.<ext>.webp" / ".avif" / ".png".
        $format = null;
        $original = $requested;
        if (substr_count($requested, '.') === 2) {
            $suffix = substr($requested, (int) strrpos($requested, '.') + 1);
            $format = 'image/' . $suffix;
            $original = substr($requested, 0, -strlen('.' . $suffix));
            if (str_ends_with($original, '.' . $suffix)) {
                return null;
            }
        }

        return self::isValid($original) && self::isImage($original) ? [$original, $format] : null;
    }
}
