<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin\Dashboard;

use AtarCms\Admin\Dashboard\BarChart;
use PHPUnit\Framework\TestCase;

final class BarChartTest extends TestCase
{
    public function testTheScaleIsANiceNumberAboveTheLargestValue(): void
    {
        self::assertSame(4, new BarChart([[0, 0]])->max(), 'an empty chart still has a scale');
        self::assertSame(4, new BarChart([[1, 0], [3, 2]])->max());
        self::assertSame(10, new BarChart([[7, 1]])->max());
        self::assertSame(20, new BarChart([[0, 12]])->max());
        self::assertSame(50, new BarChart([[41, 0]])->max());
        self::assertSame(100, new BarChart([[80, 0]])->max());
        self::assertSame(200, new BarChart([[101, 0]])->max());
    }

    public function testTheMiddleTickIsAlwaysAWholeNumber(): void
    {
        self::assertSame(10, new BarChart([[5, 0]])->max(), 'five would halve to 2.5');
        self::assertSame(5, new BarChart([[5, 0]])->mid());
        self::assertSame(2, new BarChart([[0, 0]])->mid());
        foreach ([1, 3, 4, 5, 6, 9, 11, 21, 41, 99, 401, 4001] as $largest) {
            $chart = new BarChart([[$largest, 0]]);
            self::assertSame(0, $chart->max() % 2, (string) $largest);
            self::assertSame($chart->max() / 2, $chart->mid(), (string) $largest);
        }
    }

    public function testBarsStandOnTheBaselineInTheirColumns(): void
    {
        $chart = new BarChart([[2, 0], [4, 1]]);

        self::assertSame(20, $chart->width(), 'ten units per column');
        self::assertSame(100, BarChart::HEIGHT);
        $bars = $chart->bars(false);
        self::assertCount(3, $bars, 'a zero draws nothing: the baseline shows it');
        [$first, $second, $third] = $bars;
        self::assertSame([0, 2.9, 50.0, 1.9, 50.0], [$first['series'], $first['x'], $first['y'], $first['width'], $first['height']]);
        self::assertSame([0, 12.9, 0.0, 1.9, 100.0], [$second['series'], $second['x'], $second['y'], $second['width'], $second['height']]);
        self::assertSame([1, 15.2, 75.0, 1.9, 25.0], [$third['series'], $third['x'], $third['y'], $third['width'], $third['height']]);
    }

    public function testATinyValueStaysVisible(): void
    {
        $bars = new BarChart([[1, 0], [500, 0]])->bars(false);

        self::assertSame(2.0, $bars[0]['height']);
        self::assertSame(98.0, $bars[0]['y']);
    }

    public function testRightToLeftRunsTheColumnsAndTheSeriesTheOtherWay(): void
    {
        $chart = new BarChart([[2, 1], [4, 0]]);

        $bars = $chart->bars(true);
        self::assertSame([0, 15.2], [$bars[0]['series'], $bars[0]['x']], 'the first day is on the right, its first series on its right');
        self::assertSame([1, 12.9], [$bars[1]['series'], $bars[1]['x']]);
        self::assertSame([0, 5.2], [$bars[2]['series'], $bars[2]['x']]);
    }

    public function testFewColumnsGetNarrowerBars(): void
    {
        $week = new BarChart(array_fill(0, 7, [1, 1]))->bars(false);
        $month = new BarChart(array_fill(0, 30, [1, 1]))->bars(false);

        self::assertLessThan($month[0]['width'], $week[0]['width']);
        self::assertSame([], new BarChart([])->bars(false));
    }
}
