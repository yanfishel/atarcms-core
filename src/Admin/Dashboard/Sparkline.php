<?php

declare(strict_types=1);

namespace AtarCms\Admin\Dashboard;

/**
 * A KPI tile's line of daily counts in an SVG viewBox 100 × HEIGHT that stretches to its box: the line from the
 * first day to the last (right to left in a right-to-left page) and the area under it.
 */
final readonly class Sparkline
{
    public const int WIDTH = 100;

    public const int HEIGHT = 30;

    private const int FLOOR = 28;

    private const int TOP = 2;

    /**
     * @param list<int> $values oldest first
     */
    public function __construct(private array $values) {}

    public function total(): int
    {
        return array_sum($this->values);
    }

    public function line(bool $rtl): string
    {
        $path = '';
        foreach ($this->points($rtl) as $index => [$x, $y]) {
            $path .= ($index === 0 ? 'M' : 'L') . self::number($x) . ' ' . self::number($y);
        }

        return $path;
    }

    public function area(bool $rtl): string
    {
        $points = $this->points($rtl);
        $last = $points[count($points) - 1];

        return $this->line($rtl) . 'L' . self::number($last[0]) . ' ' . self::HEIGHT . 'L' . self::number($points[0][0]) . ' ' . self::HEIGHT . 'Z';
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function points(bool $rtl): array
    {
        $values = count($this->values) >= 2 ? $this->values : array_fill(0, 2, $this->values[0] ?? 0);
        $max = max($values);
        $last = count($values) - 1;
        $points = [];
        foreach ($values as $index => $value) {
            $x = $index / $last * self::WIDTH;
            $points[] = [
                $rtl ? self::WIDTH - $x : $x,
                $max > 0 ? self::FLOOR - $value / $max * (self::FLOOR - self::TOP) : self::FLOOR,
            ];
        }

        return $points;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
