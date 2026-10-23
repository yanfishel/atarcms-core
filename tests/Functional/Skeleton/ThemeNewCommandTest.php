<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Cli\Output;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;

/**
 * bin/atar theme:new on a copy of the skeleton site: the scaffold is a working theme that passes theme:check.
 */
final class ThemeNewCommandTest extends SkeletonTestCase
{
    public function testTheScaffoldPassesTheCheckWithAndWithoutContent(): void
    {
        $site = $this->site();

        [$code, $out] = $this->console($site, ['theme:new', 'fresh']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Created themes/fresh from the scaffold.', $out);
        foreach (['theme.php', 'layouts/default.php', 'show.php', 'index.php', 'term.php', 'search.php', 'form.php', 'partials/form-fields.php', 'errors/404.php', 'errors/error.php', 'assets/css/site.css', 'lang/en.php', 'lang/ru.php', 'lang/he.php'] as $file) {
            self::assertFileExists($this->tempDir() . '/site/themes/fresh/' . $file);
        }

        // The skeleton site's booking form (config/forms.php) is the site's: a new theme writes its texts.
        $booking = 'WARNING [catalogs] themes/fresh/lang/en.php Missing texts of the form "booking": forms.booking.title, ';
        [$code, $out] = $this->console($site, ['theme:check', 'fresh']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString($booking, $out);
        self::assertStringEndsWith("theme:check fresh: 0 errors, 1 warnings\n", $out, 'an empty site');

        $this->seedDemo();
        [$code, $out] = $this->console($site, ['theme:check', 'fresh']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString($booking, $out);
        self::assertStringEndsWith("theme:check fresh: 0 errors, 1 warnings\n", $out, 'the demo content');
        self::assertStringContainsString("\n200 /he/contacts\n", $out);
    }

    public function testACopyOfAThemeIsByteExact(): void
    {
        $site = $this->site();
        $this->writeFile('site/themes/base/assets/img/logo.png', "\x89PNG\r\n\x1a\nbinary\r\n");
        $this->writeFile('site/themes/base/show.php', "<h1>x</h1>\r\n");

        [$code, $out] = $this->console($site, ['theme:new', 'copy', '--from=base']);

        self::assertSame(0, $code, $out);
        self::assertSame("\x89PNG\r\n\x1a\nbinary\r\n", file_get_contents($this->tempDir() . '/site/themes/copy/assets/img/logo.png'));
        self::assertSame("<h1>x</h1>\r\n", file_get_contents($this->tempDir() . '/site/themes/copy/show.php'));
    }

    public function testRefusesBadNamesAndExistingThemes(): void
    {
        $site = $this->site();
        $this->writeFile('site/themes/taken/show.php', "<h1>x</h1>\n");

        self::assertStringContainsString('Invalid theme name "My Theme"', $this->console($site, ['theme:new', 'My Theme'])[1]);
        self::assertStringContainsString('themes/taken already exists', $this->console($site, ['theme:new', 'taken'])[1]);
        self::assertStringContainsString('No theme directory themes/nope', $this->console($site, ['theme:new', 'other', '--from=nope'])[1]);
        self::assertSame(1, $this->console($site, ['theme:new', 'taken'])[0]);
        self::assertStringContainsString('Invalid theme name', $this->console($site, ['theme:new', "fresh\n"])[1], 'no trailing newline');
        self::assertFileDoesNotExist($this->tempDir() . "/site/themes/fresh\n");
    }

    public function testCopiesAnyThemeTheCheckAccepts(): void
    {
        $site = $this->site();
        $this->writeFile('site/themes/Old_Theme/show.php', "<h1>x</h1>\n");

        [$code, $out] = $this->console($site, ['theme:new', 'copy', '--from=Old_Theme']);

        self::assertSame(0, $code, $out);
        self::assertFileExists($this->tempDir() . '/site/themes/copy/show.php');
        self::assertSame(2, $this->console($site, ['theme:check', "copy\n", '--no-render'])[0]);
    }

    public function testALanguageTheScaffoldDoesNotSpeakGetsTheEnglishCatalog(): void
    {
        $site = $this->site("<?php\n\nreturn ['default' => 'en', 'locales' => ['en', 'it']];\n");

        [$code, $out] = $this->console($site, ['theme:new', 'fresh']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Translate the English texts of lang/it.php.', $out);
        self::assertFileEquals($this->tempDir() . '/site/themes/fresh/lang/en.php', $this->tempDir() . '/site/themes/fresh/lang/it.php');
        self::assertFileDoesNotExist($this->tempDir() . '/site/themes/fresh/lang/ru.php');
    }

    /**
     * The scaffold speaks the five languages of the skeleton theme (en, ru, he, de, fr), each catalog with the same
     * keys and valid ICU patterns.
     */
    public function testTheScaffoldSpeaksGermanAndFrench(): void
    {
        $site = $this->site("<?php\n\nreturn ['default' => 'en', 'locales' => ['en', 'de', 'fr']];\n");

        [$code, $out] = $this->console($site, ['theme:new', 'fresh']);

        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('Translate the English texts', $out);
        $en = self::flatten(require $this->tempDir() . '/site/themes/fresh/lang/en.php');
        foreach (['de' => 'Hauptmenü', 'fr' => 'Menu principal'] as $locale => $mainMenu) {
            $catalog = self::flatten(require $this->tempDir() . '/site/themes/fresh/lang/' . $locale . '.php');
            self::assertSame(array_keys($en), array_keys($catalog), $locale);
            self::assertSame($mainMenu, $catalog['site.menus.main'], $locale);
            foreach ($catalog as $key => $pattern) {
                self::assertNotNull(msgfmt_create($locale, $pattern), $locale . ': ' . $key);
            }
        }
        foreach (['ru', 'he'] as $locale) {
            self::assertSame(array_keys($en), array_keys(self::flatten(require __DIR__ . '/../../../resources/themes/scaffold/lang/' . $locale . '.php')), $locale);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function flatten(mixed $messages, string $prefix = ''): array
    {
        self::assertIsArray($messages);
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

    /**
     * A copy of the skeleton site's config in a temporary directory (its own themes/), on the test database.
     */
    private function site(?string $locales = null): Application
    {
        foreach (glob(self::SITE . '/config/*.php') ?: [] as $file) {
            $this->writeFile('site/config/' . basename($file), (string) file_get_contents($file));
        }
        if ($locales !== null) {
            $this->writeFile('site/config/locales.php', $locales);
        }

        return Application::boot(new Paths($this->tempDir() . '/site', $this->tempDir() . '/storage'));
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private function console(Application $app, array $argv): array
    {
        $stdout = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $stderr = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $code = $app->console()->run($argv, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr)];
    }
}
