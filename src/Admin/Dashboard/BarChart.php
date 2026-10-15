<?php

declare(strict_types=1);

namespace AtarCms\Admin\Dashboard;

/**
 * The geometry of a bar chart of two series in columns (the dashboard's edits and publications per day), in the
 * units of an SVG viewBox ten units wide per column and HEIGHT high that stretches to its box. A right-to-left page
 * gets the columns, and the two bars of each, the other way round, so time runs from right to left.
 */
final readonly class BarChart
{
    public const int HEIGHT = 100;

    public const int COLUMN = 10;

    /** The smallest scale, so a chart of ones and twos does not fill its height. */
    private const int MIN_MAX = 4;

    /** A bar never shrinks below this height (a one beside hundreds stays visible). */
    private const float MIN_BAR = 2.0;

    /** The space between the two bars of a column. */
    private const float GAP = 0.4;

    /**
     * @param list<array{0: int, 1: int}> $columns the two values of each column, oldest first
     */
    public function __construct(private array $columns) {}

    public function width(): int
    {
        return count($this->columns) * self::COLUMN;
    }

    /**
     * The top of the scale: the smallest of 1, 2, 4 and 5 times a power of ten that holds the largest value and is
     * even, so the middle tick (mid()) is a whole number (5 itself is skipped: 4, then 10).
     */
    public function max(): int
    {
        $largest = max(self::MIN_MAX, ...array_map(static fn(array $column): int => max($column), $this->columns ?: [[0, 0]]));
        for ($power = 1; ; $power *= 10) {
            foreach ([1, 2, 4, 5] as $step) {
                if ($step * $power >= $largest && ($step * $power) % 2 === 0) {
                    return $step * $power;
                }
            }
        }
    }

    /**
     * The middle tick: half the scale, always a whole number.
     */
    public function mid(): int
    {
        return intdiv($this->max(), 2);
    }

    /**
     * @return list<array{series: int, column: int, x: float, y: float, width: float, height: float}> one per value above zero
     */
    public function bars(bool $rtl): array
    {
        $count = count($this->columns);
        $pair = self::COLUMN * ($count <= 7 ? 0.42 : ($count <= 13 ? 0.56 : 0.72));
        $width = round(($pair - self::GAP) / 2, 2);
        $start = (self::COLUMN - $pair) / 2;
        $max = $this->max();
        $bars = [];
        foreach ($this->columns as $index => $values) {
            $left = ($rtl ? $count - 1 - $index : $index) * self::COLUMN;
            foreach ($values as $series => $value) {
                if ($value <= 0) {
                    continue;
                }
                $slot = $rtl ? 1 - $series : $series;
                $height = round(max(self::MIN_BAR, $value / $max * self::HEIGHT), 2);
                $bars[] = [
                    'series' => $series,
                    'column' => $index,
                    'x' => round($left + $start + $slot * ($width + self::GAP), 2),
                    'y' => round(self::HEIGHT - $height, 2),
                    'width' => $width,
                    'height' => $height,
                ];
            }
        }

        return $bars;
    }
}
