<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Settings\AdminAccent;
use AtarCms\Tests\Support\Regex;
use PHPUnit\Framework\TestCase;

/**
 * The admin colour presets in the token section of admin.css: each preset but green (the base palette) sets the six
 * accent tokens in the light palette and in both dark palettes (kept identical), its light accent is the logo's
 * colour, the swatches show each preset's accent of the current palette, and every preset keeps button and link text
 * at WCAG AA.
 */
final class AccentPresetsTest extends TestCase
{
    private const string CSS = __DIR__ . '/../../../resources/admin/assets/admin.css';

    private const array TOKENS = ['--accent', '--accent-hover', '--accent-strong', '--accent-soft', '--on-accent', '--focus-ring'];

    private const float AA = 4.5;

    public function testEveryPresetHasItsTokensInEveryPalette(): void
    {
        self::assertSame(AdminAccent::Green->logoColor(), self::block(':root')['--accent'], 'green is the base palette');
        foreach (self::presets() as $accent) {
            $light = self::block(sprintf(':root[data-accent="%s"]', $accent->value));
            $dark = self::block(sprintf(':root[data-theme="dark"][data-accent="%s"]', $accent->value));
            $device = self::block(sprintf('    :root:not([data-theme="light"])[data-accent="%s"]', $accent->value));

            self::assertSame(self::TOKENS, array_keys($light), $accent->value);
            self::assertSame(self::TOKENS, array_keys($dark), $accent->value);
            self::assertSame($dark, $device, $accent->value . ': both dark palettes agree');
            self::assertSame($accent->logoColor(), $light['--accent'], $accent->value . ': the logo has the light accent');
            self::assertSame($light['--accent-hover'], $light['--accent-strong'], $accent->value);
            self::assertSame($dark['--accent'], $dark['--accent-strong'], $accent->value);
            self::assertSame('#ffffff', $light['--on-accent'], $accent->value);
            self::assertSame(self::block(':root[data-theme="dark"]')['--on-accent'], $dark['--on-accent'], $accent->value);
            self::assertSame(self::ring($light['--accent']), $light['--focus-ring'], $accent->value);
            self::assertSame(self::ring($dark['--accent']), $dark['--focus-ring'], $accent->value);
        }
    }

    public function testTheSwatchesShowEachPresetInTheCurrentPalette(): void
    {
        $light = self::block(':root');
        $dark = self::block(':root[data-theme="dark"]');
        $device = self::block('    :root:not([data-theme="light"])');
        foreach (AdminAccent::cases() as $accent) {
            $token = '--swatch-' . $accent->value;
            self::assertSame($accent->logoColor(), $light[$token] ?? null, $token);
            $darkAccent = $accent === AdminAccent::Green
                ? $dark['--accent']
                : self::block(sprintf(':root[data-theme="dark"][data-accent="%s"]', $accent->value))['--accent'];
            self::assertSame($darkAccent, $dark[$token] ?? null, $token);
            self::assertSame($darkAccent, $device[$token] ?? null, $token);
            self::assertStringContainsString(sprintf('.admin-accent-swatch-%s { background: var(%s); }', $accent->value, $token), (string) file_get_contents(self::CSS));
        }
    }

    public function testEveryPresetKeepsItsTextReadable(): void
    {
        $base = ['light' => self::block(':root'), 'dark' => self::block(':root[data-theme="dark"]')];
        foreach (AdminAccent::cases() as $accent) {
            foreach ($base as $mode => $palette) {
                if ($accent !== AdminAccent::Green) {
                    $selector = $mode === 'light' ? ':root[data-accent="%s"]' : ':root[data-theme="dark"][data-accent="%s"]';
                    $palette = [...$palette, ...self::block(sprintf($selector, $accent->value))];
                }
                $label = $accent->value . ' (' . $mode . ')';
                self::assertGreaterThanOrEqual(self::AA, self::contrast($palette['--on-accent'], $palette['--accent']), $label . ': button text');
                self::assertGreaterThanOrEqual(self::AA, self::contrast($palette['--on-accent'], $palette['--accent-hover']), $label . ': button text on hover');
                self::assertGreaterThanOrEqual(self::AA, self::contrast($palette['--accent-strong'], $palette['--surface']), $label . ': links');
                self::assertGreaterThanOrEqual(self::AA, self::contrast($palette['--accent-strong'], $palette['--accent-soft']), $label . ': current menu item');
            }
        }
    }

    /**
     * @return list<AdminAccent> every preset but the base palette's
     */
    private static function presets(): array
    {
        return array_values(array_filter(AdminAccent::cases(), static fn(AdminAccent $accent): bool => $accent !== AdminAccent::DEFAULT));
    }

    /**
     * The declarations of the token section's block whose line starts with $selector (indented inside @media).
     *
     * @return array<string, string>
     */
    private static function block(string $selector): array
    {
        $css = (string) file_get_contents(self::CSS);
        $tokens = substr($css, 0, (int) strpos($css, '/* ---------- Base ---------- */'));
        $match = Regex::match('/^' . preg_quote($selector . ' {', '/') . '\n(.*?)^\s*\}/ms', $tokens, $selector);
        preg_match_all('/^\s*(--[a-z0-9-]+): ([^;]+);$/m', $match[1], $declarations, PREG_SET_ORDER);
        $block = [];
        foreach ($declarations as [, $name, $value]) {
            $block[$name] = $value;
        }

        return $block;
    }

    private static function ring(string $hex): string
    {
        return sprintf('rgb(%s / 40%%)', implode(' ', self::rgb($hex)));
    }

    /**
     * @return list<int>
     */
    private static function rgb(string $hex): array
    {
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);

        return array_map(static fn(string $pair): int => (int) hexdec($pair), str_split(substr($hex, 1), 2));
    }

    private static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $linear = array_map(static fn(int $value): float => ($c = $value / 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, self::rgb($hex));

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };
        [$light, $dark] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }
}
