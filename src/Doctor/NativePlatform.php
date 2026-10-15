<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Media\ImageFormats;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;

/**
 * The real machine: the PHP running bin/atar (or the web server's PHP for the web probe).
 */
final class NativePlatform implements Platform
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    public function ini(string $name): ?string
    {
        $value = ini_get($name);

        return $value === false ? null : $value;
    }

    public function gdInfo(): array
    {
        $info = [];
        foreach (function_exists('gd_info') ? gd_info() : [] as $key => $value) {
            $info[(string) $key] = $value;
        }
        // A GD built with AVIF whose libavif cannot encode (no encoder compiled in) still reports "AVIF Support".
        if (($info['AVIF Support'] ?? false) === true) {
            $info['AVIF Encodes'] = ImageFormats::encodes();
        }

        return $info;
    }

    public function januaryName(string $locale): ?string
    {
        if (!class_exists(IntlDateFormatter::class)) {
            return null;
        }
        $formatter = IntlDateFormatter::create($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'LLLL');
        $name = $formatter?->format(new DateTimeImmutable('2026-01-15', new DateTimeZone('UTC')));

        return is_string($name) ? $name : null;
    }

    public function effectiveUid(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    public function userName(int $uid): ?string
    {
        if (!function_exists('posix_getpwuid')) {
            return null;
        }
        $user = posix_getpwuid($uid);

        return is_array($user) ? $user['name'] : null;
    }

    public function fileOwner(string $path): ?int
    {
        if ($this->effectiveUid() === null) {
            return null;
        }
        $owner = @fileowner($path);

        return $owner === false ? null : $owner;
    }

    public function fileMode(string $path): ?int
    {
        if ($this->effectiveUid() === null) {
            return null;
        }
        $mode = @fileperms($path);

        return $mode === false ? null : $mode & 0o777;
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }
}
