<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Database;

use AtarCms\Database\Row;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RowTest extends TestCase
{
    public function testReadsTypedValues(): void
    {
        $row = ['id' => 5, 'big' => '42', 'name' => 'Ann', 'none' => null, 'at' => '2026-09-28 12:30:00', 'meta' => '{"a":1}'];

        self::assertSame(5, Row::int($row, 'id'));
        self::assertSame(42, Row::int($row, 'big'));
        self::assertNull(Row::nullableInt($row, 'none'));
        self::assertSame('Ann', Row::string($row, 'name'));
        self::assertNull(Row::nullableString($row, 'none'));
        self::assertSame('2026-09-28T12:30:00+00:00', Row::dateTime($row, 'at')->format(DATE_ATOM));
        self::assertNull(Row::nullableDateTime($row, 'none'));
        self::assertSame(['a' => 1], Row::json($row, 'meta'));
    }

    public function testRejectsMismatchedTypes(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Column "name" must be int');

        Row::int(['name' => 'Ann'], 'name');
    }

    public function testRejectsMissingColumns(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Row::string([], 'email');
    }
}
