<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Settings;

use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The site colour: the preset nearest to the theme colour (OKLab distance to each preset's logo colour), green
 * without a usable theme colour.
 */
final class AdminAccentTest extends TestCase
{
    public function testEveryPresetIsNearestToItself(): void
    {
        foreach (AdminAccent::cases() as $accent) {
            self::assertSame($accent, AdminAccent::nearest($accent->logoColor()), $accent->value);
            self::assertSame($accent, AdminAccent::nearest(strtoupper($accent->logoColor())), $accent->value . ' in upper case');
        }
    }

    #[DataProvider('unusable')]
    public function testAnUnusableColourIsGreen(?string $color): void
    {
        self::assertSame(AdminAccent::Green, AdminAccent::nearest($color));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusable(): iterable
    {
        yield 'none' => [null];
        yield 'empty' => [''];
        yield 'short' => ['#abc'];
        yield 'a name' => ['red'];
        yield 'not hex' => ['#gggggg'];
        yield 'without #' => ['2563b8'];
    }

    #[DataProvider('colours')]
    public function testRealColoursLandWhereAPersonExpects(string $color, AdminAccent $expected): void
    {
        self::assertSame($expected, AdminAccent::nearest($color), $color);
    }

    /**
     * @return iterable<string, array{string, AdminAccent}>
     */
    public static function colours(): iterable
    {
        yield 'the demo\'s dark teal' => ['#1e5e55', AdminAccent::Teal];
        yield 'a strong blue' => ['#2a60c0', AdminAccent::Blue];
        yield 'a bright red' => ['#d62839', AdminAccent::Cherry];
        yield 'an orange' => ['#d9692e', AdminAccent::Terracotta];
        yield 'a purple' => ['#8e44ad', AdminAccent::Violet];
        yield 'a grass green' => ['#3d9a50', AdminAccent::Green];
        yield 'white' => ['#ffffff', AdminAccent::Graphite];
        yield 'black' => ['#000000', AdminAccent::Graphite];
        yield 'a grey' => ['#777777', AdminAccent::Graphite];
    }

    public function testTheSettingsSiteColourFollowsTheThemeColour(): void
    {
        self::assertSame(AdminAccent::Green, (new Settings())->accent());
        self::assertSame(AdminAccent::Blue, (new Settings(themeColor: '#2a60c0'))->accent());
    }
}
