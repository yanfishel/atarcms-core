<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Kernel\Clock;
use finfo;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Validates one uploaded file and writes it to storage (spec §6.6): the type is detected from the bytes with finfo
 * and must be allowed by config/media.php; the stored name is random and its extension comes from the detected type;
 * images must not exceed the pixel limit (checked from the header, before anything is decoded) and are decoded and
 * re-encoded, which strips metadata and neutralizes polyglots; PDFs and videos are stored as they are (videos up to
 * their own size limit, max_video_size). The client's name is
 * kept only as a label. Errors are ValidationFailed on the key "file", with the file's name as ICU parameter.
 * Right after an image is stored, its WebP "admin" thumbnail is written too, so the media library never has to
 * generate dozens of thumbnails from full-size originals at once.
 */
final class MediaUploader
{
    public function __construct(
        private readonly MediaConfig $config,
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $images,
        private readonly Clock $clock,
        private readonly ?ImageVariants $variants = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @throws ValidationFailed
     */
    public function store(UploadedFileInterface $file): StoredUpload
    {
        $name = self::clientName($file->getClientFilename());
        $error = $file->getError();
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw self::failure('validation.media.missing', $name);
        }
        // The client's type only picks the limit named in PHP's own refusal; the stored type is finfo's.
        $claimed = (string) $file->getClientMediaType();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($error === UPLOAD_ERR_OK && ($file->getSize() ?? 0) > $this->config->largestUpload())) {
            throw $this->tooLarge($name, $claimed);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw self::failure('validation.media.failed', $name);
        }

        $staging = $this->storage->stagingFile();
        try {
            try {
                $file->moveTo($staging);
            } catch (RuntimeException) {
                throw self::failure('validation.media.failed', $name);
            }
            clearstatcache(true, $staging);
            $size = is_file($staging) ? filesize($staging) : false;
            if ($size === false) {
                throw self::failure('validation.media.failed', $name);
            }
            if ($size > $this->config->largestUpload()) {
                throw $this->tooLarge($name, $claimed);
            }
            $mime = new finfo(FILEINFO_MIME_TYPE)->file($staging);
            if (!is_string($mime) || !$this->config->allows($mime)) {
                throw self::failure('validation.media.type', $name);
            }
            if ($size > $this->config->maxSizeOf($mime)) {
                throw $this->tooLarge($name, $mime);
            }
            $path = $this->storage->newPath($this->clock->now(), $this->config->extension($mime));

            return $this->config->isImage($mime)
                ? $this->storeImage($staging, $path, $name, $mime)
                : $this->storeDocument($staging, $path, $name, $mime, $size);
        } finally {
            if (is_file($staging)) {
                unlink($staging);
            }
        }
    }

    /**
     * The base name of the client's file name (no directories), without control characters or invalid UTF-8,
     * at most 255 characters; "file" when nothing is left.
     */
    public static function clientName(?string $name): string
    {
        $parts = explode('/', str_replace('\\', '/', (string) $name));
        $base = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', mb_scrub((string) end($parts), 'UTF-8'));
        $base = mb_substr(trim($base), 0, 255);

        return $base === '' || $base === '.' || $base === '..' ? 'file' : $base;
    }

    private function storeImage(string $staging, string $path, string $name, string $mime): StoredUpload
    {
        $info = @getimagesize($staging);
        if (!is_array($info) || $info['mime'] !== $mime || $info[0] < 1 || $info[1] < 1) {
            throw self::failure('validation.media.broken', $name);
        }
        if ($info[0] * $info[1] > $this->config->maxPixels) {
            throw self::failure('validation.media.too_many_pixels', $name, ['max' => $this->config->maxMegapixels()]);
        }
        try {
            $image = $this->images->reencode($staging, $mime, $this->config->maxDimension, $this->config->quality);
        } catch (ImageFailed) {
            throw self::failure('validation.media.broken', $name);
        }
        $this->storage->write($path, $image->bytes);
        $this->writeAdminThumbnail($path, $mime);

        return new StoredUpload($path, $name, $mime, strlen($image->bytes), $image->width, $image->height);
    }

    /**
     * The WebP admin thumbnail (what the admin's mediaThumb() links). A failure only costs the lazy generation on
     * the first request, so it is logged, never raised.
     */
    private function writeAdminThumbnail(string $path, string $mime): void
    {
        $admin = $this->variants?->get(ImageVariants::ADMIN);
        if ($admin === null) {
            return;
        }
        try {
            $this->storage->writeVariant(
                ImageVariants::ADMIN,
                $mime === 'image/webp' ? $path : $path . '.webp',
                $this->images->variant($this->storage->original($path), $admin, 'image/webp'),
            );
        } catch (\Throwable $e) {
            // Warnings turned into ErrorException and GD's Error/ValueError included: never fail the stored upload.
            $this->logger?->warning('Admin thumbnail of {path} failed: {message}', ['path' => $path, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Non-image types (PDF, MP4, WebM) are stored byte for byte, never re-encoded; nginx and MediaFileController serve
     * PDFs as downloads and videos inline, both in a sandbox. A PDF's magic bytes are checked in addition to finfo.
     */
    private function storeDocument(string $staging, string $path, string $name, string $mime, int $size): StoredUpload
    {
        if ($mime === 'application/pdf' && file_get_contents($staging, false, null, 0, 5) !== '%PDF-') {
            throw self::failure('validation.media.type', $name);
        }
        $this->storage->copy($staging, $path);

        return new StoredUpload($path, $name, $mime, $size, null, null);
    }

    private function tooLarge(string $name, string $mime): ValidationFailed
    {
        return self::failure('validation.media.too_large', $name, ['max' => $this->config->maxSizeMegabytes($mime)]);
    }

    /**
     * @param array<string, int|float|string> $params
     */
    private static function failure(string $key, string $name, array $params = []): ValidationFailed
    {
        return new ValidationFailed(['file' => $key], ['file' => ['name' => $name, ...$params]]);
    }
}
