<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use GdImage;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Image bytes generated with GD for upload tests (no binary fixtures in the repository).
 */
final class TestImages
{
    /**
     * @param string $exif an APP1 payload from exif(), inserted right after the JPEG's start marker
     */
    public static function jpeg(int $width = 64, int $height = 48, string $exif = ''): string
    {
        $jpeg = self::encode(self::canvas($width, $height), 'jpeg');
        if ($exif === '') {
            return $jpeg;
        }

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    }

    /**
     * An EXIF APP1 payload: one IFD entry with the Orientation tag, followed by a marker that stands for private
     * metadata (GPS position, camera serial) and must not survive re-encoding.
     */
    public static function exif(int $orientation = 1, string $marker = 'SECRET-GPS-51.5'): string
    {
        $tiff = "MM\0*" . pack('N', 8) . pack('n', 1) . pack('nnNnn', 0x0112, 3, 1, $orientation, 0) . pack('N', 0) . $marker;

        return "Exif\0\0" . $tiff;
    }

    public static function png(int $width = 64, int $height = 48): string
    {
        return self::encode(self::canvas($width, $height), 'png');
    }

    public static function gif(int $width = 64, int $height = 48): string
    {
        return self::encode(self::canvas($width, $height), 'gif');
    }

    public static function webp(int $width = 64, int $height = 48): string
    {
        return self::encode(self::canvas($width, $height), 'webp');
    }

    /**
     * A PNG whose header declares $width × $height pixels but holds no pixel data: decoding it would need gigabytes.
     */
    public static function pngBomb(int $width = 60000, int $height = 60000): string
    {
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress(''))
            . $chunk('IEND', '');
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * The start of an MP4 file (an ftyp box and an empty mdat box): enough for finfo to say video/mp4, padded to
     * $size bytes.
     */
    public static function mp4(int $size = 64): string
    {
        $head = pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isomiso2' . pack('N', 8) . 'mdat';

        return str_pad($head, $size, "\0");
    }

    /**
     * The start of a WebM file (its EBML header with the "webm" doc type and a segment): finfo says video/webm.
     */
    public static function webm(): string
    {
        $header = "\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02";

        return "\x1A\x45\xDF\xA3" . chr(0x80 | strlen($header)) . $header . "\x18\x53\x80\x67\x01\x00\x00\x00\x00\x00\x00\x00" . str_repeat("\0", 32);
    }

    /**
     * A Matroska file (the same container as WebM, another doc type): finfo says video/x-matroska.
     */
    public static function matroska(): string
    {
        $header = "\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x88matroska";

        return "\x1A\x45\xDF\xA3" . chr(0x80 | strlen($header)) . $header . str_repeat("\0", 32);
    }

    public static function uploaded(string $contents, string $clientName, string $clientType = 'application/octet-stream', int $error = UPLOAD_ERR_OK): UploadedFileInterface
    {
        $factory = new Psr17Factory();

        return $factory->createUploadedFile($factory->createStream($contents), strlen($contents), $error, $clientName, $clientType);
    }

    private static function canvas(int $width, int $height): GdImage
    {
        if ($width < 1 || $height < 1) {
            throw new RuntimeException(sprintf('A test image needs a positive size, got %d×%d', $width, $height));
        }
        $image = imagecreatetruecolor($width, $height) ?: throw new RuntimeException('GD could not create an image');
        $red = imagecolorallocate($image, 200, 40, 40);
        $blue = imagecolorallocate($image, 40, 40, 200);
        if ($red === false || $blue === false) {
            throw new RuntimeException('GD could not allocate a color');
        }
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $red);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), $blue);

        return $image;
    }

    private static function encode(GdImage $image, string $format): string
    {
        $stream = fopen('php://memory', 'w+b') ?: throw new RuntimeException('Could not open a memory stream');
        $written = match ($format) {
            'jpeg' => imagejpeg($image, $stream, 90),
            'png' => imagepng($image, $stream),
            'gif' => imagegif($image, $stream),
            'webp' => imagewebp($image, $stream, 80),
            default => throw new RuntimeException('Unknown format ' . $format),
        };
        if (!$written) {
            throw new RuntimeException('GD could not encode ' . $format);
        }
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
