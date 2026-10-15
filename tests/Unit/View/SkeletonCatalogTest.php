<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use IntlException;
use MessageFormatter;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton theme's catalogs: the admin shows their collection, section, field and editor labels in the interface
 * language, so the theme speaks every interface language with the same keys and valid ICU patterns.
 */
final class SkeletonCatalogTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../../../skeleton/themes/default/lang';

    private const array LOCALES = ['en', 'ru', 'he', 'de', 'fr'];

    public function testEveryLanguageHasTheSameKeys(): void
    {
        $en = array_keys(self::catalog('en'));
        sort($en);
        foreach (self::LOCALES as $locale) {
            $keys = array_keys(self::catalog($locale));
            sort($keys);

            self::assertSame($en, $keys, $locale);
        }
    }

    public function testEveryPatternIsValidIcu(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (self::catalog($locale) as $key => $pattern) {
                if (str_contains($pattern, '{')) {
                    // The constructor throws on an invalid pattern (MessageFormatter::create() would return null).
                    try {
                        new MessageFormatter($locale, $pattern);
                        $error = null;
                    } catch (IntlException $e) {
                        $error = $e->getMessage();
                    }
                    self::assertNull($error, $locale . ': ' . $key);
                }
            }
        }
    }

    /**
     * Every content language of the skeleton site has a theme catalog; the theme speaks more languages than the
     * skeleton's demo content is written in (German and French have no demo content).
     */
    public function testEveryContentLocaleOfTheSkeletonSiteHasACatalog(): void
    {
        $config = require self::DIRECTORY . '/../../../config/locales.php';
        self::assertIsArray($config);
        self::assertIsArray($config['locales']);

        self::assertSame(['en', 'ru', 'he'], $config['locales']);
        self::assertSame([], array_diff($config['locales'], self::LOCALES));
    }

    /** A Hebrew prefix letter is never glued to an argument or a plural's number (CoreCatalogTest has the same rule). */
    public function testHebrewPrefixLettersNeverTouchAnArgument(): void
    {
        foreach (self::catalog('he') as $key => $pattern) {
            self::assertDoesNotMatchRegularExpression('/\p{Hebrew}[{#]/u', $pattern, $key);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(string $locale): array
    {
        $file = self::DIRECTORY . '/' . $locale . '.php';
        self::assertFileExists($file);
        $messages = require $file;
        self::assertIsArray($messages);

        return self::flatten($messages, '');
    }

    /**
     * @param array<array-key, mixed> $messages
     *
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, $prefix . $key . '.');
            } else {
                self::assertIsString($value);
                $flat[$prefix . $key] = $value;
            }
        }

        return $flat;
    }
}
