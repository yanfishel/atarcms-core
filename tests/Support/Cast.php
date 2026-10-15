<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use Stringable;
use UnexpectedValueException;

/**
 * PHP's (int) and (string) casts for the untyped values tests read (Connection::scalar(), selectOne() rows, decoded
 * JSON): the same result for scalars and null, an exception for anything a cast would turn into nonsense (an array,
 * an object). Typed for PHPStan, unlike a cast of mixed.
 */
final class Cast
{
    public static function int(mixed $value): int
    {
        if ($value === null || is_scalar($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException(sprintf('Expected a scalar to cast to int, got %s', get_debug_type($value)));
    }

    public static function string(mixed $value): string
    {
        if ($value === null || is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new UnexpectedValueException(sprintf('Expected a scalar to cast to string, got %s', get_debug_type($value)));
    }
}
