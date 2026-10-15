<?php

declare(strict_types=1);

namespace AtarCms\Auth;

/**
 * Dot-separated permission patterns: "*" matches everything, otherwise each "*" segment matches one segment.
 */
final class PermissionPattern
{
    private const string SEGMENT = '(?:\*|[a-z0-9_-]+)';

    public static function isValid(string $pattern): bool
    {
        return preg_match('/^' . self::SEGMENT . '(?:\.' . self::SEGMENT . ')*$/', $pattern) === 1;
    }

    public static function matches(string $pattern, string $permission): bool
    {
        if ($pattern === '*') {
            return true;
        }
        $expected = explode('.', $pattern);
        $actual = explode('.', $permission);
        if (count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $index => $segment) {
            if ($segment !== '*' && $segment !== $actual[$index]) {
                return false;
            }
        }

        return true;
    }
}
