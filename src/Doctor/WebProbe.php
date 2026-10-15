<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Kernel\Paths;
use Throwable;

/**
 * The one-time handshake of `doctor --http` with the web server's PHP. bin/atar writes the SHA-256 of a random token
 * and an expiry into storage/tmp/doctor-probe.json (never the token itself); GET /_atar/doctor/{token} answers only
 * while that file exists, is fresh and matches, deletes it (single use) and returns facts() — versions and yes/no
 * answers, no paths, no settings, no secrets. Everything else is the site's normal 404.
 */
final class WebProbe
{
    public const string FILE = 'tmp/doctor-probe.json';

    public const int LIFETIME = 60;

    public const string TOKEN_PATTERN = '[0-9a-f]{64}';

    /**
     * Writes a fresh token's hash and returns the token.
     */
    public static function issue(Paths $paths, int $now): string
    {
        $token = bin2hex(random_bytes(32));
        $file = $paths->storage(self::FILE);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0o777, true);
        }
        $json = json_encode(['hash' => hash('sha256', $token), 'expires' => $now + self::LIFETIME], JSON_THROW_ON_ERROR);
        if (@file_put_contents($file, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write storage/' . self::FILE);
        }
        @chmod($file, 0o644);

        return $token;
    }

    /**
     * True once for the token issued last, within its lifetime. Every request that finds the file claims it first
     * (an atomic rename, so two requests can never both succeed) and removes it, matching or not: a wrong guess only
     * makes the doctor's own run report the probe as unanswered.
     */
    public static function consume(Paths $paths, string $token, int $now): bool
    {
        $file = $paths->storage(self::FILE);
        if (preg_match('/^' . self::TOKEN_PATTERN . '$/D', $token) !== 1 || !is_file($file)) {
            return false;
        }
        $claimed = $file . '.' . bin2hex(random_bytes(6));
        if (!@rename($file, $claimed)) {
            return false;
        }
        try {
            $data = json_decode((string) file_get_contents($claimed), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $data = null;
        } finally {
            @unlink($claimed);
        }

        return is_array($data)
            && is_string($data['hash'] ?? null)
            && is_int($data['expires'] ?? null)
            && $data['expires'] >= $now
            && hash_equals($data['hash'], hash('sha256', $token));
    }

    /**
     * What the PHP serving the site knows about itself.
     *
     * @param list<string> $locales
     *
     * @return array<string, mixed>
     */
    public static function facts(Platform $platform, Paths $paths, array $locales): array
    {
        $extensions = [];
        foreach ([...array_keys(PhpChecks::EXTENSIONS), 'openssl'] as $name) {
            $extensions[$name] = $platform->extensionLoaded($name);
        }
        $ini = [];
        foreach (['memory_limit', 'upload_max_filesize', 'post_max_size', 'allow_url_fopen', 'opcache.enable'] as $name) {
            $ini[$name] = $platform->ini($name);
        }
        $months = [];
        foreach (array_unique(['en', ...$locales]) as $locale) {
            $months[$locale] = $platform->januaryName($locale);
        }
        $storage = [];
        foreach (['', ...StorageChecks::DIRECTORIES] as $directory) {
            $path = $directory === '' ? $paths->storage() : $paths->storage($directory);
            if (is_dir($path)) {
                $storage[$directory === '' ? 'storage' : 'storage/' . $directory] = self::canWriteIn($path);
            }
        }
        $key = $paths->storage('app.key');
        $session = $platform->ini('session.save_path');
        $session = $session === null || $session === '' ? sys_get_temp_dir() : (string) preg_replace('/^.*;/', '', $session);
        $uid = $platform->effectiveUid();

        return [
            'php' => $platform->phpVersion(),
            'sapi' => PHP_SAPI,
            'extensions' => $extensions,
            'ini' => $ini,
            'gd' => array_filter($platform->gdInfo(), is_bool(...)),
            'months' => $months,
            'uid' => $uid,
            'user' => $uid === null ? null : $platform->userName($uid),
            'storage' => $storage,
            'app_key' => !is_file($key) ? 'missing' : (is_readable($key) ? 'readable' : 'unreadable'),
            'session' => is_dir($session) && is_writable($session),
        ];
    }

    /** Creates and deletes a file: is_writable() alone can be fooled by ACLs and mounts. */
    private static function canWriteIn(string $directory): bool
    {
        $file = $directory . '/.doctor-' . bin2hex(random_bytes(4));
        if (@file_put_contents($file, '') === false) {
            return false;
        }

        return @unlink($file);
    }
}
