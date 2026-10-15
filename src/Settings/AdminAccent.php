<?php

declare(strict_types=1);

namespace AtarCms\Settings;

/**
 * The admin's accent colour presets: each user's own admin colour (users.accent, chosen on the Profile) and the site
 * colour (nearest(), the preset closest to the site's theme colour) wherever no user's choice applies. The palettes
 * live in the token section of admin.css (`<html data-accent="…">`); here each preset only names its logo colour, the
 * light accent of the AtarCMS logo files (resources/admin/assets/logo/atar-{preset}*.{svg,png}) that the admin brand,
 * the default favicons and the share images use.
 */
enum AdminAccent: string
{
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Cherry = 'cherry';
    case Terracotta = 'terracotta';
    case Graphite = 'graphite';

    public const self DEFAULT = self::Green;

    /**
     * How much lightness counts in nearest(): the presets share one lightness, so a light or dark theme colour must
     * still find its hue (a dark teal is teal, white is graphite).
     */
    private const float LIGHTNESS_WEIGHT = 0.1;

    /**
     * The preset nearest to $color ("#rrggbb", any case): the smallest Euclidean distance in OKLab to the presets'
     * logo colours, lightness weighted down (LIGHTNESS_WEIGHT), ties in the order of the cases. Without a "#rrggbb" colour it is the default (green).
     */
    public static function nearest(?string $color): self
    {
        if ($color === null || preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            return self::DEFAULT;
        }
        $target = self::oklab($color);
        $best = self::DEFAULT;
        $distance = INF;
        foreach (self::cases() as $accent) {
            $lab = self::oklab($accent->logoColor());
            $d = (self::LIGHTNESS_WEIGHT * ($lab[0] - $target[0])) ** 2 + ($lab[1] - $target[1]) ** 2 + ($lab[2] - $target[2]) ** 2;
            if ($d < $distance) {
                $best = $accent;
                $distance = $d;
            }
        }

        return $best;
    }

    public function logoColor(): string
    {
        return match ($this) {
            self::Green => '#2a7d5c',
            self::Teal => '#1f7a80',
            self::Blue => '#2563b8',
            self::Indigo => '#4c4fb8',
            self::Violet => '#7a45a8',
            self::Cherry => '#b0304f',
            self::Terracotta => '#b4532a',
            self::Graphite => '#4a4f57',
        };
    }

    /**
     * "#rrggbb" in OKLab (Björn Ottosson's matrices, from linear sRGB).
     *
     * @return array{float, float, float}
     */
    private static function oklab(string $color): array
    {
        $linear = array_map(static function (string $hex): float {
            $c = hexdec($hex) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($color, 1), 2));
        [$r, $g, $b] = $linear;
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }
}
