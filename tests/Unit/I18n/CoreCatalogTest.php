<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\Auth\LoginFailure;
use AtarCms\Tests\Support\Cast;
use FilesystemIterator;
use IntlException;
use MessageFormatter;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CoreCatalogTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** The core catalogs (the interface languages, InterfaceLanguages::ALL). */
    private const array LOCALES = ['en', 'ru', 'he', 'de', 'fr'];

    /** Key prefixes the code completes at run time ('admin.status.' . $status): their keys count as used. */
    private const array DYNAMIC = [
        'admin.status.', 'admin.theme.', 'admin.form.tab.', 'admin.users.status.', 'admin.entries.status.',
        'admin.tree.move.', 'admin.map.tab.', 'admin.error.explain.', 'admin.audit.action.', 'admin.audit.subject.', 'auth.failure.', 'http.status.', 'admin.accent.', 'admin.settings.toolbar_position.', 'admin.settings.field.social_',
    ];

    public function testEveryCatalogHasTheSameKeys(): void
    {
        $en = array_keys(self::catalog('en'));
        sort($en);
        foreach (self::LOCALES as $locale) {
            $keys = array_keys(self::catalog($locale));
            sort($keys);

            self::assertSame($en, $keys, $locale);
        }
    }

    /**
     * Hebrew whole numbers take "one", "two" and "other" (CLDR 42+): a Russian "few" or "many" left in a Hebrew
     * message would never be chosen, and every plural needs its "other".
     */
    /**
     * A Hebrew prefix letter (ב, ו, ל, מ, ה, ש, כ) is never glued to an argument or a plural's number ("מ5" reads
     * wrong): it goes into each plural branch, with a hyphen before a digit ("מרשומה אחת", "מ-# רשומות").
     */
    public function testHebrewPrefixLettersNeverTouchAnArgument(): void
    {
        foreach (self::catalog('he') as $key => $pattern) {
            self::assertDoesNotMatchRegularExpression('/\p{Hebrew}[{#]/u', $pattern, $key);
        }
    }

    public function testHebrewPluralsUseHebrewCategories(): void
    {
        $plurals = 0;
        foreach (self::catalog('he') as $key => $pattern) {
            if (!str_contains($pattern, 'plural,')) {
                continue;
            }
            ++$plurals;
            self::assertDoesNotMatchRegularExpression('/\b(zero|few|many) \{/', $pattern, $key);
            self::assertSame(substr_count($pattern, 'plural,'), substr_count($pattern, 'other {'), $key);
        }
        self::assertGreaterThan(20, $plurals);
    }

    /**
     * German and French whole numbers take "one" and "other" (French also "many", only for exact millions, which ICU
     * falls back from to "other" with the same form): a Russian "few" or a Hebrew "two" would never be chosen.
     */
    public function testGermanAndFrenchPluralsUseTheirCategories(): void
    {
        foreach (['de', 'fr'] as $locale) {
            $plurals = 0;
            foreach (self::catalog($locale) as $key => $pattern) {
                if (!str_contains($pattern, 'plural,')) {
                    continue;
                }
                ++$plurals;
                self::assertDoesNotMatchRegularExpression('/\b(zero|two|few|many) \{/', $pattern, $locale . ': ' . $key);
                self::assertSame(substr_count($pattern, 'plural,'), substr_count($pattern, 'other {'), $locale . ': ' . $key);
            }
            self::assertGreaterThan(20, $plurals, $locale);
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
                    self::assertNull($error, sprintf('%s: %s', $locale, $key));
                }
            }
        }
    }

    public function testEveryKeyTheCoreUsesExists(): void
    {
        $catalog = self::catalog('en');

        foreach (self::usedKeys() as $key) {
            self::assertArrayHasKey($key, $catalog, $key);
        }
    }

    /**
     * Every key of the core catalogs is used: quoted in full in src or the admin templates, or under a DYNAMIC prefix.
     * A quoted route name of the same spelling also counts (that is how admin.terms.edit outlived its last use).
     */
    public function testEveryKeyIsUsed(): void
    {
        $code = '';
        foreach ([...self::files(self::ROOT . '/src'), ...self::files(self::ROOT . '/resources/admin/templates')] as $file) {
            $code .= (string) file_get_contents($file);
        }

        foreach (array_keys(self::catalog('en')) as $key) {
            foreach (self::DYNAMIC as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    continue 2;
                }
            }
            self::assertTrue(str_contains($code, "'" . $key . "'"), sprintf('%s is used nowhere: remove it from both catalogs', $key));
        }
    }

    public function testTheAdminSpeaksPlainLanguage(): void
    {
        $jargon = [
            'en' => '/\b(slugs?|taxonom\w*|canonical|audit|meta (title|description)|terms?|subterms?|collections?|media library|revision\w*)\b/i',
            'ru' => '/(slug|слаг|таксоном|каноническ|аудит|meta |термин|коллекц|медиатек|ревиз|тег)/iu',
            'he' => '/(slug|סלאג|טקסונומ|קנוני|אודיט|מטא|רוויזי|ספריית מדיה|קולקצי)/iu',
            // "Tag" is also the German "day": only the plural "Tags" (days are "Tage") and "Tag-" compounds are the jargon.
            'de' => '/(?i:slug|taxonom|kanonisch|canonical|audit|meta|revision|mediathek|medienbibliothek|kollektion|\bterms?\b)|\bTags\b|\bTag-/u',
            'fr' => '/(slug|taxonom|canonique|audit|méta|meta|révision|médiathèque|bibliothèque de médias|collection|\btags?\b|\btermes?\b)/iu',
        ];
        foreach ($jargon as $locale => $pattern) {
            foreach (self::catalog($locale) as $key => $message) {
                // ICU argument names ("{taxonomy}") are not shown to anyone.
                $text = (string) preg_replace('/\{[a-z_]+(?=[,}])/', '{', $message);
                // Meta, the company of the Meta Pixel (Plan 41), is a name, not the jargon of meta titles and tags.
                $text = (string) preg_replace('/\bMeta-Pixel|\bpixel Meta\b/u', 'pixel', $text);
                self::assertDoesNotMatchRegularExpression($pattern, $text, sprintf('%s: %s', $locale, $key));
            }
        }
    }

    public function testTheInterfaceKeysOfTheAdminShellAndFormsExist(): void
    {
        $catalog = self::catalog('en');
        $keys = [
            'admin.menu.group.content', 'admin.menu.group.structure', 'admin.menu.group.files', 'admin.menu.group.modules',
            'admin.menu.group.settings', 'admin.form.tab.content', 'admin.form.tab.media', 'admin.form.tab.settings',
            'admin.form.tab.seo', 'admin.form.tab.social', 'admin.theme.light', 'admin.theme.dark', 'admin.theme.system',
            'admin.status.published', 'admin.status.draft', 'admin.status.scheduled', 'admin.empty.title',
            'admin.form.saved_at', 'admin.form.not_saved', 'admin.dashboard.quick.add', 'admin.pagination.page_of',
            'admin.layout.profile',
        ];

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $catalog, $key);
        }
    }

    public function testTheDefaultRoleLabelsAreKeysOfEveryCatalog(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalog = self::catalog($locale);
            foreach (\AtarCms\Auth\Roles::DEFAULTS as $role => $definition) {
                self::assertSame('admin.roles.' . $role, $definition['label'], $role);
                self::assertArrayHasKey($definition['label'], $catalog, $locale . ': ' . $role);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function usedKeys(): array
    {
        $keys = [];
        foreach (self::files(self::ROOT . '/resources/admin/templates') as $file) {
            preg_match_all("/->t\\('([a-z0-9_]+(?:\\.[a-z0-9_]+)*)'/", (string) file_get_contents($file), $matches);
            $keys = [...$keys, ...$matches[1]];
        }
        foreach (self::files(self::ROOT . '/src') as $file) {
            preg_match_all("/'((?:validation|admin\\.menu)\\.[a-z0-9_.]+)'/", (string) file_get_contents($file), $matches);
            $keys = [...$keys, ...$matches[1]];
        }
        foreach (LoginFailure::cases() as $failure) {
            $keys[] = $failure->translationKey();
        }
        foreach ([400, 403, 404, 405, 419, 422, 429, 500, 503] as $status) {
            $keys[] = 'http.status.' . $status;
        }
        foreach (['active', 'disabled'] as $status) {
            $keys[] = 'admin.users.status.' . $status;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(string $locale): array
    {
        $messages = require self::ROOT . '/resources/lang/' . $locale . '.php';
        self::assertIsArray($messages, $locale);

        return self::flatten($messages, '');
    }

    /**
     * @param array<mixed> $messages
     *
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            if (is_array($value)) {
                $flat = array_replace($flat, self::flatten($value, $prefix . $key . '.'));
            } else {
                $flat[$prefix . $key] = Cast::string($value);
            }
        }

        return $flat;
    }
}
