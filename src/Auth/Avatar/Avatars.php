<?php

declare(strict_types=1);

namespace AtarCms\Auth\Avatar;

use AtarCms\Media\ImageFailed;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\MediaConfig;
use finfo;
use Throwable;

/**
 * The users' profile photos: storage/avatars/{id}.webp, outside the public uploads (served by AvatarController to
 * signed-in users). A photo passes the media uploads' MIME allowlist of an image and its own pixel limit (MAX_PIXELS,
 * checked before decoding) and is re-encoded by GD into a SIZE × SIZE WebP square, written atomically.
 */
final class Avatars
{
    public const int SIZE = 96;

    /** A profile photo is small: far below the uploads' limit, so decoding one never needs much memory. */
    public const int MAX_PIXELS = 4_000_000;

    private const int QUALITY = 85;

    public function __construct(
        private readonly string $directory,
        private readonly MediaConfig $config,
        private readonly ImageProcessor $images,
    ) {}

    public function path(int $userId): string
    {
        return rtrim($this->directory, '/') . '/' . $userId . '.webp';
    }

    /**
     * Removes the photo of $userId (a deleted user's); nothing to do without one.
     */
    public function delete(int $userId): void
    {
        $path = $this->path($userId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Stores $bytes as the photo of $userId and returns its version (12 hex characters of the file's sha256).
     *
     * @throws AvatarFailed
     */
    public function save(int $userId, string $bytes): string
    {
        $this->ensureDirectory();
        $staging = $this->temporary('source');
        try {
            if (file_put_contents($staging, $bytes) !== strlen($bytes)) {
                throw new AvatarFailed('The photo could not be staged');
            }
            $mime = new finfo(FILEINFO_MIME_TYPE)->file($staging);
            if (!is_string($mime) || !$this->config->allows($mime) || !$this->config->isImage($mime)) {
                throw new AvatarFailed(sprintf('The photo is not an allowed image (%s)', is_string($mime) ? $mime : 'unknown'));
            }
            $info = @getimagesize($staging);
            if (!is_array($info) || $info['mime'] !== $mime || $info[0] < 1 || $info[1] < 1) {
                throw new AvatarFailed('The photo is broken');
            }
            if ($info[0] * $info[1] > self::MAX_PIXELS) {
                throw new AvatarFailed('The photo has too many pixels');
            }
            try {
                $webp = $this->images->square($staging, self::SIZE, 'image/webp', self::QUALITY);
            } catch (ImageFailed $e) {
                throw new AvatarFailed('The photo could not be decoded: ' . $e->getMessage(), 0, $e);
            }
        } finally {
            if (is_file($staging)) {
                unlink($staging);
            }
        }
        $this->write($this->path($userId), $webp);

        return substr(hash('sha256', $webp), 0, 12);
    }

    private function write(string $target, string $contents): void
    {
        $temporary = $this->temporary(basename($target));
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents) || !rename($temporary, $target)) {
                throw new AvatarFailed(sprintf('Could not write "%s"', $target));
            }
        } catch (Throwable $e) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $e instanceof AvatarFailed ? $e : new AvatarFailed($e->getMessage(), 0, $e);
        }
    }

    private function temporary(string $name): string
    {
        return rtrim($this->directory, '/') . '/.' . $name . '.' . bin2hex(random_bytes(6)) . '.tmp';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new AvatarFailed(sprintf('Could not create "%s"', $this->directory));
        }
    }
}
