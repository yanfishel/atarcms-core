<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Analytics;

use AtarCms\Analytics\ConsentChoice;
use PHPUnit\Framework\TestCase;

/**
 * The atar_consent cookie (Plan 41): a token per decided category, "s" statistics and "a" advertising, "1" granted and
 * "0" denied; Plan 40's "1"/"0" were a statistics-only choice, so advertising stays undecided.
 */
final class ConsentChoiceTest extends TestCase
{
    public function testTheCategoriesAreRead(): void
    {
        foreach ([
            's1' => [true, null],
            's0' => [false, null],
            'a1' => [null, true],
            's1.a0' => [true, false],
            's0.a1' => [false, true],
            's1.a1' => [true, true],
            '1' => [true, null],
            '0' => [false, null],
        ] as $value => $expected) {
            $choice = ConsentChoice::fromCookie((string) $value);
            self::assertNotNull($choice, (string) $value);
            self::assertSame($expected, [$choice->statistics, $choice->advertising], (string) $value);
        }
    }

    public function testAnUnreadableValueIsNoChoice(): void
    {
        foreach ([null, '', 's', 's2', 'x1', 's1.', '.a1', 's1.s0', 'a1.s1', 's1.a1.a0', 'S1', 's1,a0', '10'] as $value) {
            self::assertNull(ConsentChoice::fromCookie($value), var_export($value, true));
        }
    }
}
