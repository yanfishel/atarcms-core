<?php

declare(strict_types=1);

namespace AtarCms\Http;

/**
 * Open-redirect guard: only same-origin relative paths are allowed as redirect targets.
 */
final class SafeRedirect
{
    public static function sanitize(mixed $target, string $fallback): string
    {
        if (!is_string($target) || $target === '' || strlen($target) > 2000 || $target[0] !== '/') {
            return $fallback;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target) === 1) {
            return $fallback;
        }
        $decoded = rawurldecode($target);
        if (str_starts_with($decoded, '//') || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            return $fallback;
        }

        return $target;
    }
}
