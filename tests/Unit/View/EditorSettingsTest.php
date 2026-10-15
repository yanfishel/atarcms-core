<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use AtarCms\Kernel\ConfigException;
use AtarCms\View\EditorSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The palette, fonts, font sizes and highlighters of theme.php's "editor" key (the css, styles and templates keys
 * are covered by ThemeConfigTest).
 */
final class EditorSettingsTest extends TestCase
{
    public function testWithoutTheKeysTheEditorKeepsItsOwnDefaults(): void
    {
        $settings = EditorSettings::fromArray([], '');

        self::assertSame([null, null, null, null], [$settings->colors, $settings->fonts, $settings->fontSizes, $settings->markers]);
        self::assertSame([null, null, null, null], [new EditorSettings()->colors, new EditorSettings()->fonts, new EditorSettings()->fontSizes, new EditorSettings()->markers]);
    }

    public function testAnEmptyListTurnsAFeatureOff(): void
    {
        $settings = EditorSettings::fromArray(['colors' => [], 'fonts' => [], 'fontSizes' => [], 'markers' => []], '');

        self::assertSame([[], [], [], []], [$settings->colors, $settings->fonts, $settings->fontSizes, $settings->markers]);
    }

    public function testParsesThePaletteFontsSizesAndMarkers(): void
    {
        $settings = EditorSettings::fromArray([
            'colors' => [
                ['label' => 'site.editor.colors.accent', 'color' => '#3a7bd5'],
                ['color' => 'hsl(0, 0%, 30%)', 'label' => 'Grey'],
            ],
            'fonts' => [
                ['label' => 'site.editor.fonts.serif', 'family' => 'Georgia, "Times New Roman", serif'],
                ['label' => 'Mono', 'family' => 'ui-monospace,Consolas,monospace'],
            ],
            'fontSizes' => [
                'small' => ['label' => 'site.editor.sizes.small', 'class' => 'text-small'],
                'huge' => ['label' => 'Huge', 'class' => 'size-huge'],
            ],
            'markers' => [
                'yellow' => ['label' => 'Yellow', 'class' => 'marker-yellow', 'color' => '#fdfd77', 'type' => 'marker'],
                'red' => ['label' => 'Red pen', 'class' => 'pen-red', 'color' => 'red', 'type' => 'pen'],
            ],
        ], '');

        self::assertSame([
            ['label' => 'site.editor.colors.accent', 'color' => '#3a7bd5'],
            ['label' => 'Grey', 'color' => 'hsl(0,0%,30%)'],
        ], $settings->colors);
        self::assertSame([
            ['label' => 'site.editor.fonts.serif', 'family' => "Georgia, 'Times New Roman', serif"],
            ['label' => 'Mono', 'family' => 'ui-monospace, Consolas, monospace'],
        ], $settings->fonts, 'families as the editor writes them');
        self::assertSame([
            'small' => ['label' => 'site.editor.sizes.small', 'class' => 'text-small'],
            'huge' => ['label' => 'Huge', 'class' => 'size-huge'],
        ], $settings->fontSizes);
        self::assertSame([
            'yellow' => ['label' => 'Yellow', 'class' => 'marker-yellow', 'color' => '#fdfd77', 'type' => 'marker'],
            'red' => ['label' => 'Red pen', 'class' => 'pen-red', 'color' => 'red', 'type' => 'pen'],
        ], $settings->markers);
    }

    public function testColoursAreStoredCompactlyAsTheEditorReadsThemBack(): void
    {
        $settings = EditorSettings::fromArray([
            'colors' => [['label' => 'Grey', 'color' => 'rgb(1, 2, 3)']],
            'markers' => ['grey' => ['label' => 'Grey', 'class' => 'marker-grey', 'color' => 'hsl(0, 0%, 30%)', 'type' => 'marker']],
        ], '');

        self::assertSame([['label' => 'Grey', 'color' => 'rgb(1,2,3)']], $settings->colors);
        self::assertSame('hsl(0,0%,30%)', $settings->markers['grey']['color'] ?? null);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalid(): iterable
    {
        $colour = ['label' => 'Accent', 'color' => '#3a7bd5'];
        $font = ['label' => 'Serif', 'family' => 'Georgia, serif'];
        $size = ['label' => 'Small', 'class' => 'text-small'];
        $marker = ['label' => 'Yellow', 'class' => 'marker-yellow', 'color' => '#fdfd77', 'type' => 'marker'];

        yield 'colors not a list' => [['colors' => ['accent' => $colour]], '"editor.colors" must be a list of at most 30 colours'];
        yield 'colors too many' => [['colors' => array_fill(0, 31, $colour)], '"editor.colors" must be a list of at most 30 colours'];
        yield 'colour not an array' => [['colors' => ['#fff']], 'The editor colour 1 in theme.php must be an array with the keys label, color'];
        yield 'colour unknown key' => [['colors' => [[...$colour, 'name' => 'x']]], 'Unknown key "name" in the editor colour 1 in theme.php; known keys: label, color'];
        yield 'colour without label' => [['colors' => [['color' => '#fff']]], 'The editor colour 1 in theme.php needs a label'];
        yield 'colour invalid' => [['colors' => [$colour, [...$colour, 'color' => 'url(x)']]], 'The editor colour 2 in theme.php: "color" must be a CSS colour'];
        yield 'colour missing' => [['colors' => [['label' => 'x']]], '"color" must be a CSS colour'];
        yield 'fonts not a list' => [['fonts' => 'Georgia'], '"editor.fonts" must be a list of at most 16 fonts'];
        yield 'fonts too many' => [['fonts' => array_map(static fn(int $i): array => ['label' => 'F', 'family' => 'f' . $i], range(1, 17))], 'at most 16 fonts'];
        yield 'font unknown key' => [['fonts' => [[...$font, 'weight' => 400]]], 'Unknown key "weight" in the editor font 1'];
        yield 'font without label' => [['fonts' => [['family' => 'serif']]], 'The editor font 1 in theme.php needs a label'];
        yield 'font family invalid' => [['fonts' => [[...$font, 'family' => 'Georgia; color: red']]], 'The editor font 1 in theme.php: "family" must list 1 to 8 font names'];
        yield 'font family not a string' => [['fonts' => [[...$font, 'family' => ['serif']]]], '"family" must list 1 to 8 font names'];
        yield 'font family twice' => [['fonts' => [$font, [...$font, 'family' => '"Georgia",serif']]], 'The editor font 2 in theme.php repeats the family "Georgia, serif"'];
        yield 'fontSizes not an array' => [['fontSizes' => 'big'], '"editor.fontSizes" must be an array of at most 8 size name => settings'];
        yield 'fontSizes too many' => [['fontSizes' => array_combine(array_map(static fn(int $i): string => 's' . $i, range(1, 9)), array_map(static fn(int $i): array => ['label' => 'S', 'class' => 'c' . $i], range(1, 9)))], 'at most 8'];
        yield 'font size name' => [['fontSizes' => ['Small' => $size]], 'Invalid editor font size name "Small"'];
        yield 'font size name default' => [['fontSizes' => ['default' => $size]], 'The editor font size name "default" in theme.php is reserved by the editor'];
        yield 'font size unknown key' => [['fontSizes' => ['small' => [...$size, 'size' => '12px']]], 'Unknown key "size" in the editor font size "small"'];
        yield 'font size class' => [['fontSizes' => ['small' => [...$size, 'class' => 'text small']]], 'The editor font size "small" in theme.php: "class" must be a class name'];
        yield 'font size class twice' => [['fontSizes' => ['small' => $size, 'tiny' => $size]], 'The editor font size "tiny" in theme.php repeats the class "text-small"'];
        yield 'font size without label' => [['fontSizes' => ['small' => ['class' => 'x']]], 'The editor font size "small" in theme.php needs a label'];
        yield 'markers not an array' => [['markers' => 'yellow'], '"editor.markers" must be an array of at most 12 marker name => settings'];
        yield 'markers too many' => [['markers' => array_combine(array_map(static fn(int $i): string => 'm' . $i, range(1, 13)), array_map(static fn(int $i): array => ['label' => 'M', 'class' => 'c' . $i, 'color' => 'red', 'type' => 'pen'], range(1, 13)))], 'at most 12'];
        yield 'marker name' => [['markers' => ['yellow-1' => $marker]], 'Invalid editor marker name "yellow-1"'];
        yield 'marker unknown key' => [['markers' => ['yellow' => [...$marker, 'style' => 'x']]], 'Unknown key "style" in the editor marker "yellow"'];
        yield 'marker class' => [['markers' => ['yellow' => [...$marker, 'class' => '1yellow']]], 'The editor marker "yellow" in theme.php: "class" must be a class name'];
        yield 'marker class twice' => [['markers' => ['yellow' => $marker, 'green' => $marker]], 'The editor marker "green" in theme.php repeats the class "marker-yellow"'];
        yield 'marker colour' => [['markers' => ['yellow' => [...$marker, 'color' => 'var(--x)']]], 'The editor marker "yellow" in theme.php: "color" must be a CSS colour'];
        yield 'marker type' => [['markers' => ['yellow' => [...$marker, 'type' => 'brush']]], 'The editor marker "yellow" in theme.php: "type" must be marker or pen'];
        yield 'marker without label' => [['markers' => ['yellow' => ['class' => 'x', 'color' => 'red', 'type' => 'pen']]], 'The editor marker "yellow" in theme.php needs a label'];
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('invalid')]
    public function testRejectsInvalidSettings(array $config, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        EditorSettings::fromArray($config, '');
    }
}
