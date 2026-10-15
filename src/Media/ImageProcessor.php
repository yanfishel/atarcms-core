<?php

declare(strict_types=1);

namespace AtarCms\Media;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ExceptionInterface;
use InvalidArgumentException;

/**
 * The only code that touches intervention/image (GD driver). Re-encoding scales down first and only then applies the
 * EXIF orientation (the box is square, so the order does not change the result, but rotating a full-size bitmap would
 * need a second full-size copy in memory). Animations keep their first frame; encoding writes no metadata, so
 * re-encoding strips EXIF/GPS data and anything appended to the image (polyglot payloads).
 */
final class ImageProcessor
{
    public const float AVIF_QUALITY_FACTOR = 0.7;

    public const int AVIF_SPEED = 6;

    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(Driver::class, autoOrientation: false, decodeAnimation: false, strip: true);
    }

    /**
     * Decodes $file, scales it down so that neither side exceeds $maxDimension and encodes it as $mime again.
     *
     * @throws ImageFailed
     */
    public function reencode(string $file, string $mime, int $maxDimension, int $quality): ProcessedImage
    {
        try {
            $image = $this->manager->decodePath($file);
            if ($image->width() > $maxDimension || $image->height() > $maxDimension) {
                $image->scaleDown($maxDimension, $maxDimension);
            }
            // Orient after scaling: rotating the full-size bitmap would need a second full-size copy in memory.
            $image->orient();
            $bytes = $image->encode(self::encoder($mime, $quality))->toString();

            return new ProcessedImage($bytes, $image->width(), $image->height());
        } catch (ExceptionInterface $e) {
            throw new ImageFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * The bytes of $variant of the image $file, encoded as $mime (image/avif only where ImageFormats::$avif is on).
     *
     * @throws ImageFailed
     */
    public function variant(string $file, ImageVariant $variant, string $mime): string
    {
        try {
            $image = $this->manager->decodePath($file);
            if ($variant->fit === ImageVariant::CROP) {
                $image->coverDown($variant->width, (int) $variant->height);
            } else {
                $image->scaleDown($variant->width, $variant->height);
            }

            if ($mime === 'image/avif') {
                return self::avif($image->core()->native(), $variant->quality);
            }

            return $image->encode(self::encoder($mime, $variant->quality))->toString();
        } catch (ExceptionInterface $e) {
            throw new ImageFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * The image $file cut to exactly $size × $size (cover: scaled up or down until it fills the square, then centred and
     * cropped), encoded as $mime. Like reencode(), the EXIF orientation is applied after the cut (the box is square,
     * so the result is the same) and never to the full-size bitmap. For small images (profile photos): the caller
     * checks the pixel count first.
     *
     * @throws ImageFailed
     */
    public function square(string $file, int $size, string $mime, int $quality): string
    {
        try {
            $image = $this->manager->decodePath($file);
            $image->cover($size, $size);
            $image->orient();

            return $image->encode(self::encoder($mime, $quality))->toString();
        } catch (ExceptionInterface $e) {
            throw new ImageFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * The image $file cut to exactly $width × $height (cover: scaled up or down until it fills the box, then centred and
     * cropped) as a GD image to draw on (the share card). The EXIF orientation is applied before the cut, since the
     * box is not square (stored originals are already oriented by their upload, so it is normally a no-op).
     *
     * @throws ImageFailed
     */
    public function cover(string $file, int $width, int $height): \GdImage
    {
        try {
            $image = $this->manager->decodePath($file);
            $image->orient();
            $image->cover($width, $height);
            $native = $image->core()->native();
        } catch (ExceptionInterface $e) {
            throw new ImageFailed($e->getMessage(), 0, $e);
        }
        if (!$native instanceof \GdImage) {
            throw new ImageFailed(sprintf('Could not decode "%s" as a GD image', $file));
        }

        return $native;
    }

    /**
     * AVIF through GD itself, for the encoder speed: a variant is made on its first request, and speed 6 (of 0, the
     * slowest and smallest, to 10) keeps that tolerable. AVIF at the same quality number looks better than JPEG or
     * WebP, so the variant's quality is scaled by AVIF_QUALITY_FACTOR (82 becomes 57).
     *
     * @throws ImageFailed
     */
    private static function avif(mixed $native, int $quality): string
    {
        if (!$native instanceof \GdImage || !function_exists('imageavif')) {
            throw new ImageFailed('This GD cannot write AVIF');
        }
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new ImageFailed('Could not open a memory stream');
        }
        try {
            if (!imageavif($native, $stream, max(1, (int) round($quality * self::AVIF_QUALITY_FACTOR)), self::AVIF_SPEED)) {
                throw new ImageFailed('GD could not encode the image as AVIF');
            }
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private static function encoder(string $mime, int $quality): EncoderInterface
    {
        return match ($mime) {
            'image/jpeg' => new JpegEncoder(quality: $quality, progressive: true, strip: true),
            'image/webp' => new WebpEncoder(quality: $quality, strip: true),
            'image/png' => new PngEncoder(),
            'image/gif' => new GifEncoder(),
            default => throw new InvalidArgumentException(sprintf('Cannot encode images as "%s"', $mime)),
        };
    }
}
