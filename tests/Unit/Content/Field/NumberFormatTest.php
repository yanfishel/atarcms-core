<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\NumberType;
use PHPUnit\Framework\TestCase;

final class NumberFormatTest extends TestCase
{
    /** @var list<float> values exercising ordinary, borderline and extreme magnitudes */
    private const array ROUND_TRIP_FLOATS = [
        1.0,
        5.0E-5,
        -1.5,
        37.62,
        999999999999999.5,
        123456789012.345,
        0.123456789012345,
        1.000000000000001,
        1.0E20,
        1.0E-20,
        1.23456789E-10,
        0.1 + 0.2,
        PHP_FLOAT_MAX,
        5.0e-324,
        2.2250738585072014e-308,
    ];

    public function testNumbersAreFormattedWithoutExponents(): void
    {
        self::assertSame('0.00005', NumberType::format(5.0E-5));
        self::assertSame('37.62', NumberType::format(37.62));
        self::assertSame('55.75', NumberType::format(55.75));
        // A float zero keeps a decimal point (so normalize() reads it back as a float, not an int); the sign
        // of a negative zero is kept (1 / -0.0 === -INF), an ordinary positive zero has none.
        self::assertSame('0.0', NumberType::format(0.0));
        self::assertSame('-0.0', NumberType::format(-0.0));
        self::assertSame('-12', NumberType::format(-12));
        // A whole float keeps a decimal point, so normalize() reads it back as a float, not an int.
        self::assertSame('1.0', NumberType::format(1.0));
    }

    /**
     * normalize(format(x), field) must yield the same stored value (same type and value) as x: format() emits
     * the shortest decimal that round-trips exactly, with no rounding, truncation or saturation.
     */
    public function testFormatRoundTripsThroughNormalizeForFloats(): void
    {
        $type = new NumberType();
        $field = Field::number('x');

        foreach (self::ROUND_TRIP_FLOATS as $float) {
            $formatted = NumberType::format($float);
            self::assertStringNotContainsString('E', $formatted, 'float ' . $float);
            self::assertStringNotContainsString('e', $formatted, 'float ' . $float);
            $roundTripped = $type->normalize($formatted, $field);
            self::assertIsFloat($roundTripped, 'float ' . $float);
            self::assertSame($float, $roundTripped, 'float ' . $float);
        }
    }

    public function testFormatRoundTripsThroughNormalizeForInts(): void
    {
        $type = new NumberType();
        $field = Field::number('x');

        foreach ([0, -12, 42, 1_000_000, -7, 1234567890123456, PHP_INT_MAX, PHP_INT_MIN] as $int) {
            $formatted = NumberType::format($int);
            self::assertStringNotContainsString('.', $formatted, 'int ' . $int);
            $roundTripped = $type->normalize($formatted, $field);
            self::assertIsInt($roundTripped, 'int ' . $int);
            self::assertSame($int, $roundTripped, 'int ' . $int);
        }
    }

    /**
     * format() computes its own shortest round-trip representation instead of relying on var_export() or
     * json_encode(), which follow the "serialize_precision" ini setting: at 14 they lose precision (e.g.
     * 0.1 + 0.2 would round to "0.3", 1.000000000000001 to "1.0"), and at low settings an extreme value can
     * even print as a string that parses back to a different value (or, for PHP_FLOAT_MAX, to INF). Vary the
     * ini setting and assert format() is unaffected either way.
     */
    public function testFormatIsIndependentOfSerializePrecisionIni(): void
    {
        $type = new NumberType();
        $field = Field::number('x');
        $original = ini_get('serialize_precision');
        self::assertIsString($original);

        try {
            foreach (['17', '14', '10'] as $precision) {
                ini_set('serialize_precision', $precision);
                self::assertSame('37.62', NumberType::format(37.62), 'serialize_precision=' . $precision);
                foreach (self::ROUND_TRIP_FLOATS as $float) {
                    $formatted = NumberType::format($float);
                    self::assertStringNotContainsString('E', $formatted, 'serialize_precision=' . $precision . ' float ' . $float);
                    self::assertStringNotContainsString('e', $formatted, 'serialize_precision=' . $precision . ' float ' . $float);
                    $roundTripped = $type->normalize($formatted, $field);
                    self::assertIsFloat($roundTripped, 'serialize_precision=' . $precision . ' float ' . $float);
                    self::assertSame($float, $roundTripped, 'serialize_precision=' . $precision . ' float ' . $float);
                }
            }
        } finally {
            ini_set('serialize_precision', $original);
        }
    }
}
