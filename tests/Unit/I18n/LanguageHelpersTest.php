<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\I18n\AcceptLanguage;
use AtarCms\I18n\InterfaceLanguages;
use AtarCms\I18n\LanguageNames;
use AtarCms\I18n\TextDirection;
use AtarCms\View\EditorLanguages;
use PHPUnit\Framework\TestCase;

/**
 * The small language helpers: the direction of a locale's script, a language named in itself, the interface
 * languages (the core catalogs) and the browser's Accept-Language among them.
 */
final class LanguageHelpersTest extends TestCase
{
    public function testRightToLeftLanguagesAreFoundByTheirPrimarySubtag(): void
    {
        foreach (['he', 'ar', 'fa', 'ur', 'yi', 'he-il', 'ar-eg'] as $locale) {
            self::assertSame('rtl', TextDirection::of($locale), $locale);
        }
        foreach (['en', 'ru', 'pt-br', 'de', 'zh', 'hebrew', ''] as $locale) {
            self::assertSame('ltr', TextDirection::of($locale), $locale);
        }
    }

    public function testTheEditorLanguagesAgreeOnTheirDirection(): void
    {
        foreach (EditorLanguages::ALL as $code => [, $direction]) {
            self::assertSame($direction, TextDirection::of($code), $code);
        }
    }

    public function testALanguageIsNamedInItself(): void
    {
        self::assertSame('English', LanguageNames::endonym('en'));
        self::assertSame('Русский', LanguageNames::endonym('ru'));
        self::assertSame('עברית', LanguageNames::endonym('he'));
        self::assertSame('Français', LanguageNames::endonym('fr'));
        self::assertSame('Nederlands', LanguageNames::endonym('nl'), 'outside the editor list: ICU');
        self::assertSame('XX', LanguageNames::endonym('xx'), 'unknown: the code');
    }

    public function testTheInterfaceLanguagesAreTheCoreCatalogs(): void
    {
        $files = array_map(static fn(string $file): string => basename($file, '.php'), glob(dirname(__DIR__, 3) . '/resources/lang/*.php') ?: []);
        sort($files);
        $languages = InterfaceLanguages::ALL;
        sort($languages);

        self::assertSame($files, $languages);
        self::assertTrue(InterfaceLanguages::isSupported('ru'));
        self::assertTrue(InterfaceLanguages::isSupported('he'));
        self::assertTrue(InterfaceLanguages::isSupported('de'));
        self::assertTrue(InterfaceLanguages::isSupported('fr'));
        self::assertSame(['en', 'ru', 'he', 'de', 'fr'], InterfaceLanguages::ALL);
        self::assertFalse(InterfaceLanguages::isSupported('ar'), 'Arabic waits for a native speaker\'s review');
    }

    public function testTheLegacyHebrewCodeIsHebrew(): void
    {
        self::assertSame('he', AcceptLanguage::negotiate('iw-IL, en;q=0.5', ['en', 'ru', 'he']));
        self::assertSame('he', AcceptLanguage::negotiate('iw', ['en', 'he']));
        self::assertSame('en', AcceptLanguage::negotiate('iw, en;q=0.5', ['en', 'ru']));
    }

    public function testTheBrowserLanguageIsNegotiatedByQualityAndPrimarySubtag(): void
    {
        $available = ['en', 'ru'];

        self::assertSame('ru', AcceptLanguage::negotiate('ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7', $available));
        self::assertSame('en', AcceptLanguage::negotiate('de-DE, en;q=0.5, ru;q=0.4', $available));
        self::assertSame('ru', AcceptLanguage::negotiate('en;q=0.2, RU;q=0.9', $available), 'case-insensitive, by quality');
        self::assertSame('en', AcceptLanguage::negotiate('ru;q=0, en;q=0.1', $available), 'q=0 means not acceptable');
        self::assertSame('ru', AcceptLanguage::negotiate('ru-UA', $available));
        self::assertSame('en', AcceptLanguage::negotiate('ru;q=0.5, en', $available), 'no q is 1');
        self::assertNull(AcceptLanguage::negotiate('de, fr', $available));
        self::assertNull(AcceptLanguage::negotiate('', $available));
        self::assertNull(AcceptLanguage::negotiate('*', $available));
        self::assertNull(AcceptLanguage::negotiate(';;;, q=1, ru;q=abc', $available), 'junk is skipped');
        self::assertSame('ru', AcceptLanguage::negotiate('junk!!, ru', $available));
    }
}
