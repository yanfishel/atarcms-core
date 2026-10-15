<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Settings;

use AtarCms\Content\ValidationErrors;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Settings\SettingsValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsValuesTest extends TestCase
{
    public function testSingleLineTextIsCollapsedAndLimited(): void
    {
        self::assertSame('Fishing club', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::line("  Fishing \n\t club ", 20, 'f', $e)));
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::line("  \n ", 20, 'f', $e)));
        self::assertSame(['validation.field.too_long', ['max' => 3]], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::line('abcd', 3, 'f', $e)));
    }

    public function testMultiLineTextKeepsItsLines(): void
    {
        self::assertSame(
            "Main street 1\nOffice 2\n\nBy the river",
            self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::multiline("  Main street 1 \r\nOffice\x07 2\r\n\r\n\r\n\r\nBy the river \n ", 500, 'f', $e)),
        );
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::multiline(" \r\n \n", 500, 'f', $e)));
        self::assertSame(['validation.field.too_long', ['max' => 5]], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::multiline("abc\ndef", 5, 'f', $e)));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function phones(): iterable
    {
        yield 'international' => ['+7 (900) 123-45-67', '+79001234567'];
        yield 'a Russian trunk prefix' => ['8 900 123-45-67', '+79001234567'];
        yield 'international with plus' => ['+44 20 7946 0958', '+442079460958'];
        yield 'empty' => ['  ', null];
    }

    #[DataProvider('phones')]
    public function testPhonesAreStoredAsPlusAndDigits(string $input, ?string $expected): void
    {
        self::assertSame($expected, self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::phone($input, 'f', $e)));
    }

    public function testOtherPhonesAreRefused(): void
    {
        foreach (['call me', '+7 900 12', '12345678901234567', '+7 900 123 45 67 ext 2', '7+900', '44 20 7946 0958', '7 900 123-45-67', '8 900 123-45-6', '8 900 123-45-678'] as $phone) {
            self::assertSame(['validation.settings.phone', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::phone($phone, 'f', $e)), $phone);
        }
    }

    public function testTelegramUsernamesLoseTheirAt(): void
    {
        self::assertSame('fishing_club', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::telegram(' @fishing_club ', 'f', $e)));
        self::assertSame('club5', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::telegram('club5', 'f', $e)));
        foreach (['abcd', str_repeat('a', 33), 'fishing-club', 'https://t.me/club', '@@club5'] as $name) {
            self::assertSame(['validation.settings.telegram', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::telegram($name, 'f', $e)), $name);
        }
    }

    public function testSocialLinksAreAbsoluteHttpsUrls(): void
    {
        self::assertSame('https://vk.com/club', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::httpsUrl(' https://vk.com/club ', 500, 'f', $e)));
        foreach (['http://vk.com/club', 'vk.com/club', 'javascript:alert(1)', 'https://', 'https://vk.com/a b'] as $url) {
            self::assertSame(['validation.settings.url', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::httpsUrl($url, 500, 'f', $e)), $url);
        }
        self::assertSame(['validation.field.too_long', ['max' => 30]], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::httpsUrl('https://vk.com/' . str_repeat('a', 30), 30, 'f', $e)));
    }

    public function testAPastedVerificationTagIsReducedToItsContent(): void
    {
        self::assertSame('a1B2-c_3', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::verification('<meta name="google-site-verification" content="a1B2-c_3" />', 'f', $e)));
        self::assertSame('0123abcd', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::verification(" <meta name='yandex-verification' content='0123abcd'> ", 'f', $e)));
        self::assertSame('0123abcd', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::verification(' 0123abcd ', 'f', $e)));
        foreach (['abc def', '<meta name="x">', str_repeat('a', 101), 'a"><script>'] as $code) {
            self::assertSame(['validation.settings.verification', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::verification($code, 'f', $e)), $code);
        }
    }

    public function testThemeColoursAreLowerCaseHex(): void
    {
        self::assertSame('#1a2b3c', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::color(' #1A2B3C ', 'f', $e)));
        foreach (['1a2b3c', '#fff', 'red', '#1a2b3g'] as $color) {
            self::assertSame(['validation.settings.theme_color', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::color($color, 'f', $e)), $color);
        }
    }

    public function testInvalidUtf8IsRefusedNotDropped(): void
    {
        $broken = "Club \xC3\x28";
        self::assertSame(['validation.field.invalid', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::line($broken, 100, 'f', $e)));
        self::assertSame(['validation.field.invalid', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::multiline($broken, 500, 'f', $e)));
    }

    public function testATitlePatternNeedsTheTitle(): void
    {
        self::assertSame('{title} | {site}', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::titlePattern(' {title} | {site} ', 'f', $e)));
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::titlePattern('', 'f', $e)));
        self::assertSame(['validation.settings.title_pattern', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::titlePattern('{site}', 'f', $e)));
    }

    public function testAMetricaCounterIsItsNumber(): void
    {
        self::assertSame('12345678', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::metrica(' 12345678 ', 'f', $e)));
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::metrica('  ', 'f', $e)));
        foreach (['ym12345678', '0123', '1234567890123', '12 345', '-1'] as $value) {
            self::assertSame(['validation.settings.metrica', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::metrica($value, 'f', $e)), $value);
        }
    }

    public function testAGoogleAnalyticsIdIsAMeasurementId(): void
    {
        self::assertSame('G-TEST123456', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::googleAnalytics(' g-test123456 ', 'f', $e)));
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::googleAnalytics('', 'f', $e)));
        foreach (['UA-12345-1', 'G-', 'G-ABC', 'GTM-ABC1234', 'G-ABC 1234', 'G-' . str_repeat('A', 21)] as $value) {
            self::assertSame(['validation.settings.google_analytics', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::googleAnalytics($value, 'f', $e)), $value);
        }
    }

    public function testAMetaPixelIsItsNumber(): void
    {
        self::assertSame('123456789012345', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::metaPixel(' 123456789012345 ', 'f', $e)));
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::metaPixel('', 'f', $e)));
        foreach (['fb123', '0123456', str_repeat('1', 21), '123 456', '-1'] as $value) {
            self::assertSame(['validation.settings.meta_pixel', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::metaPixel($value, 'f', $e)), $value);
        }
    }

    public function testAGoogleAdsIdIsAConversionId(): void
    {
        self::assertSame('AW-123456789', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::googleAds(' aw-123456789 ', 'f', $e)));
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::googleAds('', 'f', $e)));
        foreach (['123456789', 'AW-', 'AW-123', 'AW-12345678A', 'G-TEST123456', 'GTM-ABC1234', 'AW-' . str_repeat('1', 21)] as $value) {
            self::assertSame(['validation.settings.google_ads', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::googleAds($value, 'f', $e)), $value);
        }
    }

    public function testAVkPixelIsItsNumber(): void
    {
        self::assertSame('3456789', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::vkPixel(' 3456789 ', 'f', $e)));
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::vkPixel(' ', 'f', $e)));
        foreach (['VK-3456789', '0345', '1234567890123', '345 678'] as $value) {
            self::assertSame(['validation.settings.vk_pixel', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::vkPixel($value, 'f', $e)), $value);
        }
    }

    public function testATikTokPixelIsItsCode(): void
    {
        self::assertSame('C4ABCDEFGH1234567890', self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::tiktokPixel(' c4abcdefgh1234567890 ', 'f', $e)));
        self::assertNull(self::accept(static fn(ValidationErrors $e): ?string => SettingsValues::tiktokPixel('', 'f', $e)));
        foreach (['C4ABC', 'C4ABCDEFGH-1234567890', 'C4ABCDEFGH 1234567890', str_repeat('A', 31)] as $value) {
            self::assertSame(['validation.settings.tiktok_pixel', []], self::refuse(static fn(ValidationErrors $e): ?string => SettingsValues::tiktokPixel($value, 'f', $e)), $value);
        }
    }

    /**
     * @param callable(ValidationErrors): ?string $normalize
     */
    private static function accept(callable $normalize): ?string
    {
        $errors = new ValidationErrors();
        $value = $normalize($errors);
        self::assertTrue($errors->isEmpty(), 'no error expected');

        return $value;
    }

    /**
     * @param callable(ValidationErrors): ?string $normalize
     *
     * @return array{string, array<string, int|float|string>}
     */
    private static function refuse(callable $normalize): array
    {
        $errors = new ValidationErrors();
        $normalize($errors);
        try {
            $errors->throwIfAny();
        } catch (ValidationFailed $e) {
            return [$e->errors['f'] ?? '', $e->params['f'] ?? []];
        }
        self::fail('Expected an error');
    }
}
