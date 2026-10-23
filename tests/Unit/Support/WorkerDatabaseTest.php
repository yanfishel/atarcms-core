<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Support;

use AtarCms\Tests\Support\WorkerDatabase;
use PHPUnit\Framework\TestCase;

final class WorkerDatabaseTest extends TestCase
{
    public function testEveryWorkerHasItsOwnTestDatabase(): void
    {
        self::assertSame('atar_3_test', WorkerDatabase::name('atar_test', 3));
        self::assertSame('atar_12_test', WorkerDatabase::name('atar_test', 12));
        self::assertSame('site_1_test', WorkerDatabase::name('site', 1), 'still a name the reset guard accepts');
    }

    public function testASeparateProcessKeepsItsWorkersDatabase(): void
    {
        self::assertSame('atar_3_test', WorkerDatabase::name('atar_3_test', 3));
        self::assertSame('atar_11_1_test', WorkerDatabase::name('atar_11_test', 1));
    }
}
