<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\FieldError;
use AtarCms\Kernel\ConfigException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScalarFieldTypesTest extends TestCase
{
    public function testFieldNamesAreValidatedAndCoreColumnsAreReserved(): void
    {
        self::assertSame('event_date', Field::date('event_date')->name);
        foreach (['Title', '1st', 'a-b', '', str_repeat('a', 49)] as $bad) {
            try {
                Field::text($bad);
                self::fail(sprintf('"%s" was accepted', $bad));
            } catch (ConfigException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('reserved');
        Field::text('title');
    }

    public function testBuilderKeepsSettings(): void
    {
        $field = Field::number('rating')->label('Rating')->required()->indexed()->min(0)->max(10);

        self::assertSame('Rating', $field->label);
        self::assertTrue($field->required);
        self::assertTrue($field->indexed);
        self::assertSame(0, $field->min);
        self::assertSame(10, $field->max);
        self::assertSame('number', $field->type->name());
        self::assertSame('rating', Field::number('rating')->label);
        self::assertFalse(Field::number('rating')->required);
    }

    public function testText(): void
    {
        $field = Field::text('name');

        self::assertSame('Hello world', $field->type->normalize("  Hello\n\tworld ", $field));
        self::assertNull($field->type->normalize('   ', $field));
        self::assertNull($field->type->normalize(null, $field));
        self::assertSame('42', $field->type->normalize(42, $field));
        self::assertSame(str_repeat('я', 255), $field->type->normalize(str_repeat('я', 255), $field));
        self::assertSame('Hello', $field->type->read('Hello', $field));
        self::assertNull($field->type->read(null, $field));
        self::assertSame('VARCHAR(191)', $field->type->columnType($field));
        self::assertError('validation.field.too_long', ['max' => 255], $field, str_repeat('я', 256));
        self::assertError('validation.field.too_long', ['max' => 3], Field::text('code')->max(3), 'abcd');
        self::assertError('validation.field.invalid', [], $field, ['array']);
        self::assertError('validation.field.invalid', [], $field, "\xC3\x28");
    }

    public function testTextarea(): void
    {
        $field = Field::textarea('summary');

        self::assertSame("a\nb\n\tc", $field->type->normalize(" a\r\nb\r\tc\x07 ", $field));
        self::assertNull($field->type->normalize("\n\n", $field));
        self::assertNull($field->type->columnType($field));
        self::assertSame('textarea', $field->type->name());
        self::assertError('validation.field.too_long', ['max' => 10000], $field, str_repeat('a', 10001));
    }

    public function testNumber(): void
    {
        $field = Field::number('rating')->min(0)->max(10);

        self::assertSame(7, $field->type->normalize(' 7 ', $field));
        self::assertSame(2.5, $field->type->normalize('2.5', $field));
        self::assertSame(10, $field->type->normalize(10, $field));
        self::assertSame(0.5, $field->type->normalize(0.5, $field));
        self::assertNull($field->type->normalize('', $field));
        self::assertSame(-3.25, Field::number('delta')->type->normalize('-3.25', Field::number('delta')));
        // Leading zeros are stripped before the int is parsed (not rejected as malformed).
        self::assertSame(7, $field->type->normalize('007', $field));
        self::assertSame(-7, Field::number('delta')->type->normalize('-007', Field::number('delta')));
        self::assertSame(0, $field->type->normalize('00', $field));
        self::assertSame(0, $field->type->normalize('-000', $field));
        self::assertSame(7, $field->type->normalize('0000000000000000007', $field));
        self::assertError('validation.field.number', [], $field, 'abc');
        self::assertError('validation.field.number', [], $field, '1e3');
        // A 16-digit integer string is a well-formed number (up to 19 digits round-trip through format()); it
        // still fails the field's own max, not the format.
        self::assertError('validation.field.too_large', ['max' => 10], $field, '1234567890123456');
        self::assertError('validation.field.number', [], $field, '123456789012345678901');
        // 19 digits (PHP_INT_MAX has 19 digits) but one past PHP_INT_MAX: still rejected as overflow.
        self::assertError('validation.field.number', [], $field, '9223372036854775808');
        // Leading zeros do not exempt an out-of-range value from overflow rejection.
        self::assertError('validation.field.number', [], $field, '00009223372036854775808');
        self::assertError('validation.field.number', [], $field, true);
        self::assertError('validation.field.number', [], $field, INF);
        self::assertError('validation.field.too_small', ['min' => 0], $field, -1);
        self::assertError('validation.field.too_large', ['max' => 10], $field, '10.5');
        self::assertSame(5, $field->type->read(5, $field));
        self::assertNull($field->type->read('x', $field));
        self::assertSame('DOUBLE', $field->type->columnType($field));
    }

    public function testBoolean(): void
    {
        $field = Field::boolean('featured');

        foreach ([true, '1', 'on', 'true', 1] as $yes) {
            self::assertTrue($field->type->normalize($yes, $field));
        }
        foreach ([false, null, '', '0', 0] as $no) {
            self::assertFalse($field->type->normalize($no, $field));
        }
        self::assertError('validation.field.invalid', [], $field, 'yes');
        self::assertTrue($field->type->read(true, $field));
        self::assertFalse($field->type->read(null, $field));
        self::assertNull($field->type->columnType($field));
    }

    public function testDate(): void
    {
        $field = Field::date('event_date');

        self::assertSame('2026-10-01', $field->type->normalize(' 2026-10-01 ', $field));
        self::assertNull($field->type->normalize('', $field));
        self::assertError('validation.field.date', [], $field, '2026-02-30');
        self::assertError('validation.field.date', [], $field, '01.10.2026');
        self::assertError('validation.field.date', [], $field, '0999-01-01');
        self::assertError('validation.field.date', [], $field, 20261001);
        $read = $field->type->read('2026-10-01', $field);
        self::assertInstanceOf(DateTimeImmutable::class, $read);
        self::assertSame('2026-10-01 00:00:00 UTC', $read->format('Y-m-d H:i:s T'));
        self::assertNull($field->type->read(null, $field));
        self::assertSame('DATE', $field->type->columnType($field));
    }

    public function testDateTime(): void
    {
        $field = Field::datetime('starts_at');

        self::assertSame('2026-10-01 18:30:00', $field->type->normalize('2026-10-01T18:30', $field));
        self::assertSame('2026-10-01 18:30:15', $field->type->normalize('2026-10-01 18:30:15', $field));
        self::assertSame('2026-10-01 18:30:00', $field->type->normalize('2026-10-01 18:30', $field));
        self::assertNull($field->type->normalize(null, $field));
        self::assertError('validation.field.datetime', [], $field, '2026-10-01T25:00');
        self::assertError('validation.field.datetime', [], $field, '2026-10-01');
        $read = $field->type->read('2026-10-01 18:30:00', $field);
        self::assertInstanceOf(DateTimeImmutable::class, $read);
        self::assertSame('2026-10-01 18:30:00', $read->format('Y-m-d H:i:s'));
        self::assertSame('DATETIME', $field->type->columnType($field));
    }

    public function testSelect(): void
    {
        $field = Field::select('kind', ['report' => 'Report', 1 => 'One']);

        self::assertSame('report', $field->type->normalize('report', $field));
        self::assertSame('1', $field->type->normalize(1, $field));
        self::assertNull($field->type->normalize('', $field));
        self::assertError('validation.field.option', [], $field, 'other');
        self::assertError('validation.field.option', [], $field, ['report']);
        self::assertSame(['report' => 'Report', 1 => 'One'], $field->options);
        self::assertSame('report', $field->type->read('report', $field));
        self::assertSame('VARCHAR(191)', $field->type->columnType($field));
    }

    public function testSelectNeedsStringOptions(): void
    {
        try {
            Field::select('kind', []);
            self::fail('Empty options were accepted');
        } catch (ConfigException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ConfigException::class);
        Field::select('kind', ['a' => 1]);
    }

    /**
     * @return iterable<string, array{Field, mixed}>
     */
    public static function storedValues(): iterable
    {
        yield 'text' => [Field::text('a'), ' x '];
        yield 'textarea' => [Field::textarea('a'), "a\r\nb"];
        yield 'number' => [Field::number('a'), '2.50'];
        yield 'boolean' => [Field::boolean('a'), 'on'];
        yield 'date' => [Field::date('a'), '2026-10-01'];
        yield 'datetime' => [Field::datetime('a'), '2026-10-01T10:00'];
        yield 'select' => [Field::select('a', ['x' => 'X']), 'x'];
    }

    #[DataProvider('storedValues')]
    public function testNormalizingAStoredValueChangesNothing(Field $field, mixed $input): void
    {
        $stored = $field->type->normalize($input, $field);

        self::assertSame($stored, $field->type->normalize($stored, $field));
    }

    /**
     * @param array<string, int|float|string> $params
     */
    private static function assertError(string $key, array $params, Field $field, mixed $input): void
    {
        try {
            $field->type->normalize($input, $field);
        } catch (FieldError $e) {
            self::assertSame($key, $e->key);
            self::assertSame($params, $e->params);

            return;
        }
        self::fail(sprintf('Expected %s for %s', $key, var_export($input, true)));
    }
}
