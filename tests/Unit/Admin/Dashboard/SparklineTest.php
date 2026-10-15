<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin\Dashboard;

use AtarCms\Admin\Dashboard\Sparkline;
use PHPUnit\Framework\TestCase;

final class SparklineTest extends TestCase
{
    public function testTheLineRunsFromTheFirstDayToTheLast(): void
    {
        $line = new Sparkline([0, 2, 4]);

        self::assertSame('M0 28L50 15L100 2', $line->line(false));
        self::assertSame('M0 28L50 15L100 2L100 30L0 30Z', $line->area(false));
        self::assertSame(6, $line->total());
    }

    public function testRightToLeftStartsOnTheRight(): void
    {
        self::assertSame('M100 28L50 15L0 2', new Sparkline([0, 2, 4])->line(true));
    }

    public function testNoDataIsAFlatLineOnTheFloor(): void
    {
        self::assertSame('M0 28L100 28', new Sparkline([0, 0])->line(false));
        self::assertSame('M0 28L100 28', new Sparkline([])->line(false));
        self::assertSame('M0 2L100 2', new Sparkline([3])->line(false), 'a single value is a flat line too');
        self::assertSame(0, new Sparkline([])->total());
    }
}
