<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Theme\Check;

use AtarCms\Tests\Support\SkeletonSite;
use AtarCms\Theme\Check\Finding;
use AtarCms\Theme\Check\Report;
use AtarCms\Theme\Check\Severity;
use AtarCms\Theme\Check\ThemeChecker;
use PHPUnit\Framework\TestCase;

/**
 * The static checks of theme:check against the skeleton site's content model (routed collections home, contacts,
 * news with an archive, articles, pages; taxonomies sections and tags; locales en, ru, he) on a small valid theme
 * written to a temporary directory, changed one file at a time.
 */
final class ThemeCheckerTest extends TestCase
{
    use SkeletonSite;

    private const array THEME = [
        'theme.php' => "<?php\n\nreturn [\n    'menus' => ['main' => 'site.menus.main'],\n    'images' => ['card' => ['width' => 400, 'height' => 300, 'fit' => 'crop']],\n];\n",
        'layouts/default.php' => <<<'PHP'
            <!doctype html>
            <html<?= $this->langAttrs($this->locale()) ?>>
            <head><title><?= $this->e($title ?? $this->t('site.name')) ?></title>
            <link rel="stylesheet" href="<?= $this->e($this->asset('css/site.css')) ?>"></head>
            <body><nav aria-label="<?= $this->e($this->t('site.menus.main')) ?>"><?php foreach ($this->menu('main') as $link): ?><a href="<?= $this->e($link->url) ?>"<?= $link->current ? ' aria-current="page"' : '' ?>><?= $this->e($link->label) ?></a><?php endforeach ?></nav>
            <main id="main"><?= $content ?></main></body></html>

            PHP,
        'show.php' => "<?php \$this->layout('layouts/default', ['title' => \$entry?->title]); ?>\n<h1><?= \$this->e(\$entry?->title) ?></h1>\n",
        'index.php' => "<?php \$this->layout('layouts/default'); ?>\n<h1><?= \$this->e(\$this->label(\$collection->label)) ?></h1>\n",
        'term.php' => "<?php \$this->layout('layouts/default'); ?>\n<h1><?= \$this->e(\$term->name) ?></h1>\n",
        'search.php' => "<?php \$this->layout('layouts/default'); ?>\n<h1><?= \$this->e(\$query) ?></h1>\n",
        'errors/404.php' => "<?php \$this->layout('layouts/default'); ?>\n<h1>404</h1>\n",
        'errors/error.php' => "<?php \$this->layout('layouts/default'); ?>\n<h1><?= \$this->e((string) \$status) ?></h1>\n",
        'lang/en.php' => "<?php\n\nreturn ['site' => ['name' => 'Probe', 'presets' => ['privacy' => 'Privacy policy', 'accessibility' => 'Accessibility statement'], 'menus' => ['main' => 'Main menu'], 'items' => '{count, plural, one {# item} other {# items}}']];\n",
        'lang/ru.php' => "<?php\n\nreturn ['site' => ['name' => 'Проба', 'presets' => ['privacy' => 'Политика конфиденциальности', 'accessibility' => 'Заявление о доступности'], 'menus' => ['main' => 'Главное меню'], 'items' => '{count, plural, one {# пункт} few {# пункта} many {# пунктов} other {# пункта}}']];\n",
        'lang/he.php' => "<?php\n\nreturn ['site' => ['name' => 'בדיקה', 'presets' => ['privacy' => 'מדיניות פרטיות', 'accessibility' => 'הצהרת נגישות'], 'menus' => ['main' => 'תפריט ראשי'], 'items' => '{count, plural, one {פריט אחד} other {# פריטים}}']];\n",
        'assets/css/site.css' => "body { margin-inline-start: 0; }\n",
    ];

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
        parent::tearDown();
    }

    public function testTheSkeletonThemeHasNoFindings(): void
    {
        $report = $this->check(self::SKELETON . '/themes/default');

        self::assertSame([], self::lines($report));
    }

    public function testASmallValidThemeHasNoFindings(): void
    {
        self::assertSame([], self::lines($this->checkTheme([])));
    }

    public function testTheConfigIsCheckedLikeTheBoot(): void
    {
        $report = $this->checkTheme(['theme.php' => "<?php\n\nreturn ['menu' => []];\n"]);
        self::assertSame(['ERROR [config] themes/probe/theme.php Unknown key "menu" in theme.php; known keys: menus, images, editor, share_image, direction'], self::lines($report, Severity::Error));

        $report = $this->checkTheme(['theme.php' => "<?php\n\nreturn ['menus' => ['main' => 'site.menus.main'], 'images' => ['card' => ['width' => 400]], 'direction' => 'ltr'];\n"]);
        self::assertSame(['ERROR [config] themes/probe/theme.php The theme is laid out left to right only ("direction" => "ltr"), but the site has right-to-left languages: he. Make the CSS logical (margin-inline-start…) and remove "direction".'], self::lines($report, Severity::Error));
    }

    public function testEveryPageTypeOfTheContentModelNeedsATemplate(): void
    {
        $report = $this->checkTheme([
            'show.php' => null,
            'term.php' => null,
            'search.php' => null,
            'errors/404.php' => null,
            'errors/error.php' => null,
            'collections/news/show.php' => "<?php \$this->layout('layouts/default'); ?>\n<h1>News</h1>\n",
            'collections/events/index.php' => "<h1>Events</h1>\n",
        ]);

        self::assertSame([
            'ERROR [templates] themes/probe The entries of "home" need collections/home/show.php or show.php',
            'ERROR [templates] themes/probe The entries of "contacts" need collections/contacts/show.php or show.php',
            'ERROR [templates] themes/probe The entries of "articles" need collections/articles/show.php or show.php',
            'ERROR [templates] themes/probe The entries of "pages" need collections/pages/show.php or show.php',
            'ERROR [templates] themes/probe The terms of "sections" need taxonomies/sections/show.php or term.php',
            'ERROR [templates] themes/probe The terms of "tags" need taxonomies/tags/show.php or term.php',
            'WARNING [templates] themes/probe No errors/404.php or errors/error.php: error pages are the plain core page',
            'WARNING [templates] themes/probe No search.php: /search answers 404',
            'WARNING [templates] themes/probe/collections/events "events" is not a collection of config/collections.php',
        ], self::lines($report));
    }

    public function testTemplatesKeepTheContentSecurityPolicy(): void
    {
        $report = $this->checkTheme(['partials/bad.php' => <<<'PHP'
            <?php declare(strict_types=1); ?>
            <div style="color: red">x</div>
            <style>p { color: red; }</style>
            <script>alert(1)</script>
            <script nonce="<?= $this->e($this->nonce()) ?>">document.documentElement.classList.add('js');</script>
            <script type="module" src="<?= $this->e($this->asset('css/site.css')) ?>"></script>
            <button onclick="go()">Go</button>
            <a href="javascript:go()">Go</a>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">
            <img src="//cdn.example.com/a.png" alt="">
            <a href="https://example.com/">a link is fine</a>

            PHP]);

        self::assertSame([
            'WARNING [sources] themes/probe/partials/bad.php:1 Templates are plain PHP without declare(strict_types=1)',
            'ERROR [sources] themes/probe/partials/bad.php:2 A style attribute breaks the CSP (style-src \'self\'): use a class in the stylesheet',
            'ERROR [sources] themes/probe/partials/bad.php:3 A <style> element breaks the CSP (style-src \'self\'): move the rules to the stylesheet',
            'ERROR [sources] themes/probe/partials/bad.php:4 An inline <script> without the nonce breaks the CSP: move it to assets/js/ or add nonce="<?= $this->e($this->nonce()) ?>"',
            'ERROR [sources] themes/probe/partials/bad.php:7 An event handler attribute (onclick) breaks the CSP: attach the listener in a script file',
            'ERROR [sources] themes/probe/partials/bad.php:8 A javascript: URL breaks the CSP',
            'ERROR [sources] themes/probe/partials/bad.php:9 A file loaded from another host (https://fonts.googleapis.com/css2?family=Inter) breaks the CSP: vendor it into assets/',
            'ERROR [sources] themes/probe/partials/bad.php:10 A file loaded from another host (//cdn.example.com/a.png) breaks the CSP: vendor it into assets/',
        ], self::lines($report));
    }

    /**
     * Tag rules read the markup with the PHP blocks blanked: a match never runs from one tag across PHP into the next.
     */
    public function testPhpBetweenTagsIsNotPartOfATag(): void
    {
        $report = $this->checkTheme(['partials/mixed.php' => <<<'PHP'
            <?php if ($open): ?>
            <img src="<?= $this->e($this->asset('css/site.css')) ?>" alt="">
            <?php endif ?>
            <a href="https://example.com/">a link</a>
            <script nonce="<?= $this->e($this->nonce()) ?>">document.documentElement.classList.add('js');</script>
            <?php foreach ([1, 2] as $i): ?><p data-x="<?= $this->e($i > 1 ? 'a' : 'b') ?>">x</p><?php endforeach ?>
            <script src="https://cdn.example.com/lib.js"></script>

            PHP]);

        self::assertSame([
            'ERROR [sources] themes/probe/partials/mixed.php:7 A file loaded from another host (https://cdn.example.com/lib.js) breaks the CSP: vendor it into assets/',
        ], self::lines($report));
    }

    public function testLiteralNamesMustExist(): void
    {
        $report = $this->checkTheme(['partials/names.php' => <<<'PHP'
            <?php foreach ($this->menu('sidebar') as $link): ?><?php endforeach ?>
            <?= $this->img($media, 'hero', ['alt' => '']) ?><?= $this->img($media, 'card') ?>
            <a href="<?= $this->e($this->url('content.blog.index')) ?>"><?= $this->e($this->url('content.news.index')) ?></a>
            <link rel="stylesheet" href="<?= $this->e($this->asset('css/missing.css')) ?>">
            <?= $this->e($this->t('site.missing')) ?><?= $this->e($this->t('site.name')) ?><?= $this->e($this->t('admin.users.title')) ?><?= $this->e($this->t('site.menus.' . $x)) ?>
            <h2><?= $entry->title ?></h2><?= $entry->field('body')->html ?><?= $content ?>

            PHP]);

        self::assertSame([
            'ERROR [sources] themes/probe/partials/names.php:1 Unknown menu location "sidebar": theme.php "menus" has main',
            'ERROR [sources] themes/probe/partials/names.php:2 Unknown image size "hero": theme.php "images" has card',
            'ERROR [sources] themes/probe/partials/names.php:3 Unknown route "content.blog.index"',
            'ERROR [sources] themes/probe/partials/names.php:4 Missing asset assets/css/missing.css',
            'ERROR [sources] themes/probe/partials/names.php:5 Missing translation "site.missing" in lang/en.php (the default language) and the core catalog',
            'WARNING [sources] themes/probe/partials/names.php:6 Printed without escaping: <?= $entry->title ?> (use $this->e())',
        ], self::lines($report));
    }

    public function testCatalogsCoverEveryLanguageWithTheSameKeys(): void
    {
        $report = $this->checkTheme([
            'lang/ru.php' => null,
            'lang/he.php' => "<?php\n\nreturn ['site' => ['name' => 'בדיקה', 'extra' => 'x', 'items' => '{count, plural, one {x}']];\n",
        ]);

        self::assertSame([
            'ERROR [catalogs] themes/probe/lang/ru.php Missing: the site has the language ru',
            'ERROR [catalogs] themes/probe/lang/he.php Keys missing compared to lang/en.php: site.presets.privacy, site.presets.accessibility, site.menus.main',
            'ERROR [catalogs] themes/probe/lang/he.php Keys not in lang/en.php: site.extra',
            'ERROR [catalogs] themes/probe/lang/he.php Invalid ICU message "site.items"',
            'ERROR [catalogs] themes/probe/lang/he.php Missing "site.presets.privacy", the title of the preset page "privacy" of "pages": the page would be titled with the key',
            'ERROR [catalogs] themes/probe/lang/he.php Missing "site.presets.accessibility", the title of the preset page "accessibility" of "pages": the page would be titled with the key',
        ], self::lines($report));
    }

    public function testStylesheetsUseLogicalSidesAndLocalFiles(): void
    {
        $css = <<<'CSS'
            /* margin-left: 0 in a comment is fine */
            .a { margin-left: 1rem; }
            .b { background: url(../img/missing.png); }
            @import url("https://fonts.googleapis.com/css2?family=Inter");
            .c { transform: scaleX(-1); }
            .d:dir(rtl) { transform: scaleX(-1); }
            .e { background: url(data:image/png;base64,AAAA) no-repeat; }

            CSS;
        $report = $this->checkTheme([
            'assets/css/site.css' => $css,
            'assets/vendor/lib/lib.css' => ".x { float: left; }\n",
            'assets/vendor/lib/LICENSE' => "MIT\n",
        ]);

        self::assertSame([
            'ERROR [css] themes/probe/assets/css/site.css:2 margin-left: 1rem names a physical side: use the logical property (margin-inline-start, inset-inline-end, text-align: start…)',
            'ERROR [css] themes/probe/assets/css/site.css:3 Missing file ../img/missing.png',
            'ERROR [css] themes/probe/assets/css/site.css:4 A file loaded from another host (https://fonts.googleapis.com/css2?family=Inter) breaks the CSP: vendor it into assets/',
            'WARNING [css] themes/probe/assets/css/site.css:5 A mirroring transform outside a :dir(rtl) rule',
        ], self::lines($report));
    }

    public function testALeftToRightThemeSkipsTheLogicalSidesRule(): void
    {
        $report = $this->checkTheme([
            'theme.php' => "<?php\n\nreturn ['menus' => ['main' => 'site.menus.main'], 'images' => ['card' => ['width' => 400]], 'direction' => 'ltr'];\n",
            'assets/css/site.css' => ".a { margin-left: 1rem; }\n",
            'lang/he.php' => null,
        ], ['locales.locales' => ['en', 'ru']]);

        self::assertSame(['INFO [css] themes/probe "direction" => "ltr": physical sides are allowed'], self::lines($report));
    }

    public function testScriptsLoadNothingFromTheNetworkAndVendoredFilesKeepTheirLicence(): void
    {
        $report = $this->checkTheme([
            'assets/js/site.js' => "// https://example.com in a comment is fine\nimport x from 'https://cdn.example.com/x.js';\nfetch('https://api.example.com/');\nimport y from '../vendor/lib/lib.js';\nwindow.open('https://example.com/share');\n",
            'assets/js/xhr.js' => "const r = new XMLHttpRequest();\nr.open('GET', 'https://api.example.com/x');\n",
            'assets/vendor/lib/lib.js' => "export default 1;\n",
        ]);

        self::assertSame([
            'ERROR [scripts] themes/probe/assets/js/site.js:2 A file loaded from another host (https://cdn.example.com/x.js) breaks the CSP: vendor it into assets/',
            'ERROR [scripts] themes/probe/assets/js/site.js:3 A request to another host (https://api.example.com/) breaks the CSP (connect-src \'self\')',
            'ERROR [scripts] themes/probe/assets/js/xhr.js:2 A request to another host (https://api.example.com/x) breaks the CSP (connect-src \'self\')',
            'WARNING [vendor] themes/probe/assets/vendor/lib No licence file (LICENSE, COPYING, OFL.txt…) next to the vendored files',
        ], self::lines($report));
    }

    /**
     * @param array<string, string|null> $changes file => contents (null deletes the file of the base theme)
     * @param array<string, mixed> $overrides
     */
    private function checkTheme(array $changes, array $overrides = []): Report
    {
        $directory = $this->tempDir() . '/themes/probe';
        foreach (array_replace(self::THEME, $changes) as $file => $contents) {
            if ($contents !== null) {
                $this->writeFile('themes/probe/' . $file, $contents);
            }
        }

        // The probe theme draws no forms: the skeleton's booking form is ThemeCheckerFormsTest's.
        return $this->check($directory, ['forms' => [], ...$overrides]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function check(string $directory, array $overrides = []): Report
    {
        return $this->bootSkeleton($overrides)->container()->service(ThemeChecker::class)->check($directory);
    }

    /**
     * @return list<string>
     */
    private static function lines(Report $report, ?Severity $severity = null): array
    {
        return array_map(static fn(Finding $f): string => $f->format(), $report->findings($severity));
    }
}
