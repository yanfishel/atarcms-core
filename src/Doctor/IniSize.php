<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

/**
 * php.ini sizes ("128M", "1G", "-1") in bytes, the way PHP reads them.
 */
final class IniSize
{
    /** -1 means unlimited. */
    public static function bytes(?string $value): int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (preg_match('/^(\d+)\s*([kmg]?)$/i', $value, $match) !== 1) {
            return (int) $value;
        }
        $number = (int) $match[1];

        return match (strtolower($match[2])) {
            'k' => $number * 1024,
            'm' => $number * 1024 ** 2,
            'g' => $number * 1024 ** 3,
            default => $number,
        };
    }

    public static function format(int $bytes): string
    {
        if ($bytes < 0) {
            return 'unlimited';
        }
        if ($bytes >= 1024 ** 3 && $bytes % 1024 ** 3 === 0) {
            return ($bytes / 1024 ** 3) . 'G';
        }
        if ($bytes >= 1024 ** 2) {
            return (int) ceil($bytes / 1024 ** 2) . 'M';
        }

        return $bytes . ' bytes';
    }
}
