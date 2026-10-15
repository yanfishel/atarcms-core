<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Kernel\Config;
use Closure;

/**
 * The optional output formats of image variants: AVIF when GD can write it and config/media.php does not turn it off
 * ('avif' => false). With AVIF img() adds an AVIF <source> and /media/{variant}/{path}.avif is generated; without it
 * such URLs answer 404. "Can write" is checked twice: GD must be built with AVIF (imageavif() and gd_info()'s "AVIF
 * Support") and must really encode a 1×1 image (a libavif without a working encoder passes the first check). The probe
 * runs once per instance, when an image first needs the answer.
 */
final class ImageFormats
{
    private ?bool $avif;

    /** @var (Closure(): bool)|null */
    private readonly ?Closure $probe;

    /**
     * @param bool|(Closure(): bool) $avif the answer, or the probe that gives it on first use
     */
    public function __construct(bool|Closure $avif = false)
    {
        $this->avif = $avif instanceof Closure ? null : $avif;
        $this->probe = $avif instanceof Closure ? $avif : null;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->bool('media.avif', true) ? self::gdWritesAvif(...) : false);
    }

    public function avif(): bool
    {
        return $this->avif ??= $this->probe !== null && ($this->probe)();
    }

    /** GD is built with AVIF and its encoder works. */
    public static function gdWritesAvif(): bool
    {
        return self::gdHasAvif() && self::encodes();
    }

    /** GD is built with AVIF (says nothing about a working encoder). */
    public static function gdHasAvif(): bool
    {
        return function_exists('imageavif') && function_exists('gd_info') && (gd_info()['AVIF Support'] ?? false) === true;
    }

    /**
     * Encodes a 1×1 image into memory: false when the encoder returns false, writes nothing or fails.
     *
     * @param (Closure(\GdImage, resource): bool)|null $encode the encoder (default: imageavif at its fastest speed)
     */
    public static function encodes(?Closure $encode = null): bool
    {
        $encode ??= static fn(\GdImage $image, mixed $stream): bool => function_exists('imageavif') && is_resource($stream) && @imageavif($image, $stream, 50, 10);
        try {
            $image = imagecreatetruecolor(1, 1);
            $stream = fopen('php://memory', 'w+b');
            if ($image === false || $stream === false) {
                return false;
            }
            try {
                if (!$encode($image, $stream)) {
                    return false;
                }
                rewind($stream);

                return stream_get_contents($stream) !== '';
            } finally {
                fclose($stream);
            }
        } catch (\Throwable) {
            return false;
        }
    }
}
