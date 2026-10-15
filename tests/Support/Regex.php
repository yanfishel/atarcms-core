<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * preg_match() for tests that go on to use the groups: asserts that the pattern matches (the test fails there, with
 * the pattern in the message, instead of on a missing offset later) and returns the matches.
 */
final class Regex
{
    /**
     * @return array<array-key, string>
     */
    public static function match(string $pattern, string $subject, string $message = ''): array
    {
        Assert::assertSame(1, preg_match($pattern, $subject, $matches), $message !== '' ? $message : sprintf('%s matches', $pattern));

        return $matches;
    }
}
