<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use LogicException;

/**
 * Integers or decimals, stored as PHP int|float (no exponent notation once normalized).
 */
final class NumberType implements FieldType
{
    /** A defensive cap on how long a submitted number string may be, well past any legitimate value. */
    private const int MAX_LENGTH = 400;

    public function name(): string
    {
        return 'number';
    }

    public function normalize(mixed $input, Field $field): int|float|null
    {
        if (is_string($input)) {
            $input = trim($input);
        }
        if ($input === null || $input === '') {
            return null;
        }
        if (is_string($input) && strlen($input) > self::MAX_LENGTH) {
            throw new FieldError('validation.field.number');
        }
        $number = match (true) {
            is_int($input), is_float($input) => $input,
            // Length is already bounded by MAX_LENGTH above; parseInt() strips leading zeros and caps the
            // number at 19 significant digits so PHP_INT_MAX/PHP_INT_MIN (both 19 digits) round-trip through
            // format(), while an out-of-range 19-significant-digit string still fails FILTER_VALIDATE_INT and
            // is rejected below.
            is_string($input) && preg_match('/^-?\d+$/', $input) === 1 => self::parseInt($input),
            // A value with a decimal point is a float; the digit counts are only bounded by MAX_LENGTH above,
            // so this accepts every plain decimal NumberType::format() can produce (e.g. from 1.0E20 or 1.0E-20).
            is_string($input) && preg_match('/^-?\d+\.\d+$/', $input) === 1 => (float) $input,
            default => throw new FieldError('validation.field.number'),
        };
        if (is_float($number) && !is_finite($number)) {
            throw new FieldError('validation.field.number');
        }
        if ($field->min !== null && $number < $field->min) {
            throw new FieldError('validation.field.too_small', ['min' => $field->min]);
        }
        if ($field->max !== null && $number > $field->max) {
            throw new FieldError('validation.field.too_large', ['max' => $field->max]);
        }

        return $number;
    }

    public function read(mixed $stored, Field $field): int|float|null
    {
        return is_int($stored) || is_float($stored) ? $stored : null;
    }

    /**
     * An integer string with any number of leading zeros stripped (e.g. "007" -> "7", "-000" -> "-0"), capped
     * at 19 significant digits, then validated with FILTER_VALIDATE_INT so overflow (past PHP_INT_MIN/
     * PHP_INT_MAX, or 20+ significant digits) is still rejected.
     */
    private static function parseInt(string $input): int
    {
        if (preg_match('/^(-?)0*(\d{1,19})$/', $input, $matches) !== 1) {
            throw new FieldError('validation.field.number');
        }
        $number = filter_var($matches[1] . $matches[2], FILTER_VALIDATE_INT);
        if ($number === false) {
            throw new FieldError('validation.field.number');
        }

        return $number;
    }

    public function columnType(Field $field): string
    {
        return 'DOUBLE';
    }

    /**
     * A number as the admin shows it: the shortest decimal string that round-trips back to the exact same
     * value through normalize(), computed directly (not via var_export()/json_encode(), which follow the
     * "serialize_precision" ini setting and are therefore not reliably exact: at serialize_precision=14 they
     * round to 14 significant digits, losing precision on re-save; at low settings like 10 an extreme value
     * such as PHP_FLOAT_MAX can even print as a string that parses back to INF). No exponent notation (PHP
     * prints 0.00005 as "5.0E-5", which the old, narrower normalize() grammar used to reject) and no rounding
     * or truncation. A float keeps a decimal part (e.g. "1.0", not "1") so normalize() reads it back as a
     * float, not an int; ordinary values like 37.62 or 55.75 print unchanged, regardless of the ini setting.
     */
    public static function format(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value === 0.0) {
            // Negative zero (fdiv(1, -0.0) === -INF) keeps its sign; normalize('-0.0') reads it back as -0.0.
            // fdiv(), unlike "/", performs plain IEEE-754 division instead of raising DivisionByZeroError.
            return fdiv(1, $value) < 0 ? '-0.0' : '0.0';
        }
        $text = self::plainDecimal(self::shortestRoundTrip($value));
        if (!str_contains($text, '.')) {
            $text .= '.0';
        }

        return $text;
    }

    /**
     * The shortest "%e" (scientific notation) representation of $value that reads back to the exact same
     * float, found by trying increasing precision: 17 significant digits (precision 16) always round-trips
     * for a finite double, so the loop always terminates. Unlike var_export()/json_encode(), this does not
     * depend on the "serialize_precision" ini setting.
     */
    private static function shortestRoundTrip(float $value): string
    {
        for ($precision = 0; $precision <= 16; $precision++) {
            $candidate = sprintf('%.' . $precision . 'e', $value);
            if ((float) $candidate === $value) {
                return $candidate;
            }
        }

        // Unreachable for a finite float: precision 16 (17 significant digits) always round-trips.
        return sprintf('%.16e', $value);
    }

    /**
     * Expands scientific notation (e.g. "1.23456789e-10") into a plain decimal string with the same digits,
     * so the value stays exact: no digit is dropped, added or rounded.
     */
    private static function plainDecimal(string $scientific): string
    {
        if (preg_match('/^(-)?(\d)(?:\.(\d+))?e([+-]\d+)$/i', $scientific, $matches) !== 1) {
            throw new LogicException('Unexpected number format: ' . $scientific);
        }
        $sign = $matches[1];
        $digits = $matches[2] . $matches[3];
        $exponent = (int) $matches[4];
        $pointPosition = 1 + $exponent;
        if ($pointPosition <= 0) {
            $text = '0.' . str_repeat('0', -$pointPosition) . $digits;

            return $sign . (rtrim(rtrim($text, '0'), '.') ?: '0');
        }
        if ($pointPosition >= strlen($digits)) {
            // These zeros are real place values (not insignificant trailing zeros), so they are never trimmed.
            return $sign . $digits . str_repeat('0', $pointPosition - strlen($digits));
        }

        $text = substr($digits, 0, $pointPosition) . '.' . substr($digits, $pointPosition);

        return $sign . rtrim(rtrim($text, '0'), '.');
    }
}
