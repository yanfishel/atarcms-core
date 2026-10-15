<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

/**
 * Points DB_HOST/DB_PORT at a closed port, so any code that connects fails fast. Call restore() in tearDown().
 */
final class UnreachableDatabase
{
    /** @var array<string, string|false> */
    private static array $previous = [];

    public static function apply(): void
    {
        foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1'] as $name => $value) {
            self::$previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    public static function restore(): void
    {
        foreach (self::$previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        self::$previous = [];
    }
}
