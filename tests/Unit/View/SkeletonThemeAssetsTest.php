<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use AtarCms\Tests\Support\Regex;
use AtarCms\Theme\Check\LogicalCss;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The skeleton's default theme: one stylesheet for both directions, readable colours in both schemes, templates that
 * keep the Content Security Policy, translated texts, and its vendored font and icons byte-exact.
 */
final class SkeletonThemeAssetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private const string THEME = self::ROOT . '/skeleton/themes/default';


    /** The rules that mirror something in right-to-left pages. */
    private const array FLIPPED = ['.hero-slider-arrow .icon:dir(rtl)', '.pagination .icon:dir(rtl)'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function stylesheets(): iterable
    {
        yield 'site.css' => ['site.css'];
        yield 'editor.css' => ['editor.css'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stylesheets')]
    public function testTheStylesheetsUseLogicalSides(string $file): void
    {
        $physical = [];
        $flipped = [];
        foreach (LogicalCss::rules((string) file_get_contents(self::THEME . '/assets/css/' . $file)) as [$selector, $body]) {
            foreach (LogicalCss::declarations($body) as [$property, $value]) {
                if (LogicalCss::isPhysical($property, $value) && preg_match(LogicalCss::EDITOR_SIDES, $selector) !== 1) {
                    $physical[] = $selector . ' { ' . $property . ': ' . $value . ' }';
                }
                if (LogicalCss::isFlip($property, $value)) {
                    $flipped[] = $selector;
                }
                if (($property === 'transform' && preg_match('/translateX\(\s*(?!0[\s,)])/', $value) === 1) || $property === 'translate') {
                    $physical[] = $selector . ' moves sideways';
                }
            }
        }

        self::assertSame([], $physical, 'use logical properties');
        self::assertSame($file === 'site.css' ? self::FLIPPED : [], $flipped);
    }

    public function testBothColourSchemesAreReadable(): void
    {
        $css = (string) file_get_contents(self::THEME . '/assets/css/site.css');
        $light = Regex::match('/^:root \{(.*?)^\}/ms', $css);
        $dark = Regex::match('/@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{(.*?)\}/s', $css);
        $chosen = Regex::match('/^:root\[data-theme="dark"\] \{(.*?)^\}/ms', $css);
        self::assertSame(
            preg_replace('/\s+/', ' ', trim($dark[1])),
            preg_replace('/\s+/', ' ', trim($chosen[1])),
            'the dark theme chosen in the menu is the system dark theme',
        );
        $tokens = static function (string $block): array {
            preg_match_all('/--([a-z-]+):\s*(#[0-9a-f]{6})\b/', $block, $matches);

            return array_combine($matches[1], $matches[2]);
        };
        $lightTokens = $tokens($light[1]);
        $darkTokens = [...$lightTokens, ...$tokens($dark[1])];

        foreach (['paper', 'surface', 'ink', 'slate', 'pine', 'pine-soft', 'mist', 'on-pine', 'danger'] as $name) {
            self::assertArrayHasKey($name, $tokens($dark[1]), 'the dark scheme sets --' . $name);
        }
        foreach (['light' => $lightTokens, 'dark' => $darkTokens] as $scheme => $colours) {
            foreach ([['ink', 'paper'], ['ink', 'surface'], ['slate', 'paper'], ['slate', 'surface'], ['pine', 'paper'], ['pine', 'surface'], ['on-pine', 'pine'], ['pine-strong', 'pine-soft'], ['danger', 'surface'], ['footer-ink', 'footer'], ['footer-muted', 'footer']] as [$text, $background]) {
                self::assertGreaterThanOrEqual(4.5, self::contrast($colours[$text], $colours[$background]), sprintf('%s: --%s on --%s', $scheme, $text, $background));
            }
        }
    }

    public function testTemplatesKeepTheContentSecurityPolicyAndTheirTextsExist(): void
    {
        $catalog = require self::THEME . '/lang/en.php';
        self::assertIsArray($catalog);
        $checked = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::THEME, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/lang/') || $file->getFilename() === 'theme.php') {
                continue;
            }
            $template = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $template, $file->getFilename() . ': no style attributes');
            self::assertStringNotContainsStringIgnoringCase('<style', $template, $file->getFilename());
            preg_match_all('/<script\b(?:->|\?>|[^>])*>/i', $template, $scripts);
            if ($file->getFilename() === 'default.php') {
                // The layout: the nonce'd theme bootstrap and the theme's own module files, nothing else.
                self::assertSame([
                    '<script nonce="<?= $this->e($this->nonce()) ?>">',
                    '<script type="module" src="<?= $this->e($this->asset(\'js/theme.js\')) ?>">',
                    '<script type="module" src="<?= $this->e($this->asset(\'js/tooltip.js\')) ?>">',
                    '<script type="module" src="<?= $this->e($this->asset(\'js/a11y.js\')) ?>">',
                    '<script type="module" src="<?= $this->e($this->asset(\'js/slider.js\')) ?>">',
                    '<script type="module" src="<?= $this->e($this->asset(\'js/gallery.js\')) ?>">',
                ], $scripts[0]);
            } elseif ($file->getFilename() === 'form-dialog.php') {
                // The form dialog loads its module itself: a module runs once per URL however often it is included.
                self::assertSame(['<script type="module" src="<?= $this->e($this->asset(\'js/forms.js\')) ?>">'], $scripts[0]);
            } else {
                self::assertSame([], $scripts[0], $file->getFilename());
            }
            preg_match_all("/->t\\('(site\\.[a-z0-9_.]+)'/", $template, $keys);
            foreach (array_filter($keys[1], static fn(string $key): bool => !str_ends_with($key, '.')) as $key) {
                $value = $catalog;
                foreach (explode('.', $key) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                self::assertIsString($value, $file->getFilename() . ': ' . $key);
                ++$checked;
            }
        }
        self::assertGreaterThan(40, $checked);
    }

    public function testTheFontsAndIconsAreByteExact(): void
    {
        $attributes = (string) file_get_contents(self::ROOT . '/.gitattributes');
        self::assertStringContainsString("\n/skeleton/themes/default/assets/fonts/** -text\n", $attributes);
        self::assertStringContainsString("\n/skeleton/themes/default/assets/icons.svg -text\n", $attributes);
        $hashes = [
            'fonts/OFL.txt' => '46a250495a963b0b84f2736c0185d0e3af8df9d23427bd425b92a8ece020283c',
            'fonts/rubik-cyrillic-ext-wght-normal.woff2' => '35e30483bb79be6fd8de0fec4e038fda9e637fc7337f6ff61418c7d08077878b',
            'fonts/rubik-cyrillic-wght-normal.woff2' => 'bdb2b33ab916e9e7b42edb61f8aea5f5dd83ad773d6ee4e9b55665daf8954ce8',
            'fonts/rubik-hebrew-wght-normal.woff2' => '197b6dbe5c6bec7276c59b02d70a16ee6acd44322fc2f8d0226c07f8d57976c2',
            'fonts/rubik-latin-ext-wght-normal.woff2' => '09472f217ad1ebb61a7669cf532f9f2f80685e07652ee862fde9a79665aef479',
            'fonts/rubik-latin-wght-normal.woff2' => '691cd1d9b4c0cdf31a1dcf04259c86f92c85e69f6622abab7964d81e36890691',
        ];
        foreach ($hashes as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', self::THEME . '/assets/' . $file), $file);
        }
        self::assertSame(array_merge(['OFL.txt'], array_map(static fn(string $f): string => basename($f), array_slice(array_keys($hashes), 1))), array_values(array_diff(scandir(self::THEME . '/assets/fonts') ?: [], ['.', '..'])));

        // The icons are symbols of the admin's vendored Lucide sprite (the same bytes), plus phone, clock, mail, the
        // accessibility toolbar's accessibility, minus and rotate-ccw, the hero slider's pause and play, and the chat
        // button's message-circle.
        $admin = (string) file_get_contents(self::ROOT . '/resources/admin/assets/icons.svg');
        $theme = (string) file_get_contents(self::THEME . '/assets/icons.svg');
        preg_match_all('~<symbol id="([a-z-]+)".*?</symbol>~', $theme, $symbols, PREG_SET_ORDER);
        self::assertCount(31, $symbols);
        self::assertStringContainsString('<symbol id="accessibility" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="16" cy="4" r="1"/><path d="m18 19 1-7-6 1"/><path d="m5 8 3-3 5.5 3-2.36 3.5"/><path d="M4.24 14.5a5 5 0 0 0 6.88 6"/><path d="M13.76 17.5a5 5 0 0 0-6.88-6"/></symbol>', $theme);
        foreach ($symbols as [$symbol, $id]) {
            if (!in_array($id, ['phone', 'clock', 'mail', 'accessibility', 'minus', 'rotate-ccw', 'pause', 'play', 'message-circle'], true)) {
                self::assertStringContainsString($symbol, $admin, $id);
            }
        }
        self::assertStringStartsWith('Lucide 1.49.0 icons', (string) file_get_contents(self::THEME . '/assets/icons.LICENSE'));
    }

    /**
     * The brand icons of social networks and messengers: Simple Icons 16.34.0 (CC0) paths as filled symbols, byte-exact,
     * one per network the settings know (a neutral globe where Simple Icons has none).
     */
    public function testTheBrandIconsAreByteExactAndCoverEveryNetwork(): void
    {
        $attributes = (string) file_get_contents(self::ROOT . '/.gitattributes');
        self::assertStringContainsString("\n/skeleton/themes/default/assets/brands.svg -text\n", $attributes);
        self::assertStringContainsString("\n/skeleton/themes/default/assets/brands.LICENSE -text\n", $attributes);
        self::assertSame('b9bd113b2052483eae72e71488ef9f5d9c12b6c79434745b1efaf176b6058c6f', hash_file('sha256', self::THEME . '/assets/brands.svg'));
        $license = (string) file_get_contents(self::THEME . '/assets/brands.LICENSE');
        self::assertStringContainsString('Simple Icons 16.34.0', $license);
        self::assertStringContainsString('CC0 1.0 Universal', $license);

        $sprite = (string) file_get_contents(self::THEME . '/assets/brands.svg');
        preg_match_all('~<symbol id="([a-z]+)"~', $sprite, $ids);
        $partial = (string) file_get_contents(self::THEME . '/partials/brand-icon.php');
        $map = Regex::match("~\\\$symbol = (\\[.*?\\])\\[\\\$network\\]~", $partial);
        $aliases = eval('return ' . $map[1] . ';');
        self::assertIsArray($aliases);
        foreach ([...\AtarCms\Settings\Settings::SOCIAL_NETWORKS, ...\AtarCms\Settings\Settings::MESSENGERS] as $network) {
            self::assertContains($aliases[$network] ?? $network, $ids[1], $network . ' has an icon');
        }
        self::assertStringNotContainsString('<title>', $sprite);
        self::assertStringNotContainsString('style', $sprite);
    }

    /**
     * The gallery lightbox: PhotoSwipe 5.4.4 (MIT) from the npm package's dist/, byte-exact, its version in the file
     * names (an ESM import cannot carry asset()'s ?v= hash).
     */
    public function testTheLightboxIsVendoredByteExact(): void
    {
        self::assertStringContainsString("\n/skeleton/themes/default/assets/vendor/** -text\n", (string) file_get_contents(self::ROOT . '/.gitattributes'));
        $hashes = [
            'photoswipe-5.4.4.css' => 'b0eaaae0b9de064d25e2f18517b9011a526d9b2142376e1c0febd096cebab9f3',
            'photoswipe-5.4.4.esm.min.js' => '542069771beb34dc461cdb9374da8af643c591ebf65d8ec36331dd9a0681ebd4',
            'photoswipe-lightbox-5.4.4.esm.min.js' => 'b82c385604f90cc77082d8e1bd4f5ef7c9d3da62d95dcc3ff1691a4eb0dddd12',
            'photoswipe.LICENSE' => '2c223af0b21d32240087ee189eb70fd315907b6ade8b561e24265baccd6a98e7',
        ];
        self::assertSame(array_keys($hashes), array_values(array_diff(scandir(self::THEME . '/assets/vendor') ?: [], ['.', '..'])));
        foreach ($hashes as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', self::THEME . '/assets/vendor/' . $file), $file);
        }
        self::assertStringStartsWith('The MIT License (MIT)', (string) file_get_contents(self::THEME . '/assets/vendor/photoswipe.LICENSE'));
    }

    /**
     * The theme's own scripts write no markup and no styles and load nothing from the network; the gallery script
     * imports only the vendored lightbox.
     */
    public function testTheThemeScriptsStayWithinThePolicy(): void
    {
        foreach (['theme.js', 'gallery.js', 'tooltip.js', 'a11y.js', 'slider.js', 'forms.js'] as $file) {
            $script = (string) file_get_contents(self::THEME . '/assets/js/' . $file);
            self::assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML|\.style\b|setAttribute|https?:\/\//', $script, $file);
        }
        $gallery = (string) file_get_contents(self::THEME . '/assets/js/gallery.js');
        preg_match_all("~(?:from |import\\()'([^']+)'~", $gallery, $imports);
        self::assertSame(['../vendor/photoswipe-lightbox-5.4.4.esm.min.js', '../vendor/photoswipe-5.4.4.esm.min.js'], $imports[1]);
        $theme = (string) file_get_contents(self::THEME . '/assets/js/theme.js');
        self::assertStringContainsString("const KEY = 'atar-theme';", $theme);
        self::assertStringContainsString("localStorage.getItem('atar-theme')", (string) file_get_contents(self::THEME . '/layouts/default.php'), 'the bootstrap reads the same key');
    }

    /**
     * The form dialog posts only to its own form's action, asks for JSON, writes the server's texts as text, marks
     * fields through ARIA properties and opens a native modal (Esc and the focus trap come with it).
     */
    public function testTheFormDialogScriptPostsOnlyToItsForm(): void
    {
        $script = (string) file_get_contents(self::THEME . '/assets/js/forms.js');

        preg_match_all('/fetch\(([^,)]+)/', $script, $fetches);
        self::assertSame(['form.action'], $fetches[1]);
        self::assertStringContainsString("headers: { Accept: 'application/json' }", $script);
        self::assertStringContainsString('dialog.showModal()', $script);
        self::assertStringContainsString("element.ariaInvalid = 'true'", $script);
        self::assertStringContainsString('paragraph.textContent = text', $script);
        self::assertStringContainsString('token.value = answer.token', $script, 'a refused form takes the fresh token');
        self::assertMatchesRegularExpression('/form\.reset\(\);\s*\/\/[^\n]*\n\s*if \(!dialog\.open\) \{\s*return;/', $script, 'a send that ends after closing leaves no thanks for the next opening');
        self::assertStringNotContainsString('eval(', $script);
    }

    /**
     * The hero slider never moves by itself under prefers-reduced-motion or the toolbar's "Stop animations" (it
     * watches both), turns its arrow keys with the page direction, and only the first slide shows without JavaScript;
     * its fade is no sideways move, so a right-to-left page needs nothing but the mirrored arrow icons.
     */
    public function testTheSliderKeepsStillAndFollowsTheDirection(): void
    {
        $script = (string) file_get_contents(self::THEME . '/assets/js/slider.js');
        $css = (string) file_get_contents(self::THEME . '/assets/css/site.css');

        self::assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)')", $script);
        self::assertStringContainsString("root.classList.contains('a11y-still')", $script);
        self::assertStringContainsString("new MutationObserver(", $script, 'the toolbar switches a11y-still while the page is open');
        self::assertStringContainsString("attributeFilter: ['class']", $script);
        self::assertStringContainsString("getComputedStyle(slider).direction === 'rtl'", $script);
        self::assertStringContainsString('const INTERVAL = 6000;', $script);
        self::assertMatchesRegularExpression('/function shouldRun\(\) \{\s*return !paused && !held && !still\(\) && !document\.hidden;/', $script, 'autoplay only when nothing holds it');
        self::assertStringContainsString("addEventListener('focusin'", $script, 'focus within pauses');
        self::assertStringContainsString("addEventListener('pointerenter'", $script, 'hover pauses');
        self::assertStringContainsString(':root:not(.js) .hero-slide + .hero-slide { display: none; }', $css);
        self::assertStringContainsString('.hero-slider-arrow .icon:dir(rtl) { transform: scaleX(-1); }', $css);
        self::assertStringContainsString('.hero-slider-pause[hidden] { display: none; }', $css, 'the inline-grid rule must not beat the hidden attribute when the slider keeps still');
        self::assertStringContainsString('pause.ariaLabel = label;', $script, 'the icon-only Pause renames itself when pressed');
        self::assertStringContainsString('pause.title = label;', $script, 'and its tooltip follows');
    }

    /**
     * A slide's video plays only while the slide is shown and motion is allowed (not still, the tab visible, not
     * paused); the script plays it (no autoplay attribute anywhere) and swallows a refused play(), which leaves the
     * poster. Without the script, or still, the poster (the slide's picture) shows.
     */
    public function testTheSliderPlaysVideosOnlyWhenMotionIsAllowed(): void
    {
        $script = (string) file_get_contents(self::THEME . '/assets/js/slider.js');
        $partial = (string) file_get_contents(self::THEME . '/partials/hero-slider.php');
        $css = (string) file_get_contents(self::THEME . '/assets/css/site.css');

        self::assertMatchesRegularExpression('/const videosMove = \(\) => !paused && !still\(\) && !document\.hidden;/', $script, 'the conditions of playback');
        self::assertStringContainsString('if (videosMove() && number === current) {', $script, 'only the shown slide plays');
        self::assertStringContainsString('video.play()?.catch(() => {});', $script, 'a refused play() (autoplay policy) leaves the poster');
        self::assertStringContainsString('video.pause();', $script);
        self::assertStringContainsString('video.muted = true;', $script, 'browsers play only muted videos by themselves');
        self::assertStringNotContainsString('autoplay', $script);
        self::assertStringNotContainsString('autoplay', $partial);
        self::assertStringContainsString('<video class="hero-slide-video" muted loop playsinline preload="metadata"', $partial);
        self::assertStringContainsString('.hero-slide-video { display: block; inline-size: 100%; block-size: clamp(20rem, 52vw, 36rem); object-fit: cover; }', $css, 'the video fills the slide like the picture');
    }

    /**
     * The accessibility toolbar: a11y.js and the stylesheet know the same a11y-* classes, the bootstrap applies the
     * stored ones (same key) before the first paint, the reading guide moves through a constructable stylesheet, and the
     * colour filters stay off <html> and <body> (fixed elements keep their place).
     */
    /**
     * The cookie banner (Plan 40) keeps the core script's contract (partials/consent.php: a hidden [data-consent]
     * region with the two buttons; the footer's [data-consent-open]) and stays out of the way: hidden until the script
     * shows it, the footer button only with JavaScript, lifted above the toolbar's button in a shared bottom corner.
     */
    public function testTheCookieBannerKeepsTheConsentContract(): void
    {
        $partial = (string) file_get_contents(self::THEME . '/partials/consent.php');
        $footer = (string) file_get_contents(self::THEME . '/partials/footer.php');
        $css = (string) file_get_contents(self::THEME . '/assets/css/site.css');

        self::assertStringContainsString('<section class="consent" id="consent" aria-labelledby="consent-title" tabindex="-1" hidden data-consent>', $partial);
        self::assertStringContainsString(' data-consent-accept>', $partial);
        self::assertStringContainsString(' data-consent-decline>', $partial);
        self::assertStringNotContainsString('role="dialog"', $partial, 'a region, never a modal');
        // With advertising pixels (Plan 41): "Choose" discloses the categories' panel, a switch each and "Save choice".
        self::assertStringContainsString(' aria-expanded="false" aria-controls="consent-choice" data-consent-choose>', $partial);
        self::assertStringContainsString('<div class="consent-choice" id="consent-choice" hidden data-consent-panel>', $partial);
        self::assertStringContainsString('<input type="checkbox" role="switch" ', $partial);
        self::assertStringContainsString(' data-consent-category="<?= $this->e($category) ?>">', $partial);
        self::assertStringContainsString(' data-consent-save>', $partial);
        self::assertStringContainsString('.consent-choice[hidden] { display: none; }', $css);
        self::assertStringContainsString('aria-controls="consent" data-consent-open>', $footer);
        foreach ([
            '.consent[hidden] { display: none; }',
            '.footer-consent, .footer-legal-js { display: none; }',
            '.js .footer-consent { display: list-item; }',
            '.js .footer-legal-js { display: flex; }',
            '.js body:has(.a11y[data-a11y-position="bottom-start"]) .consent {',
            '.js body:has(.a11y[data-a11y-position^="bottom"]) .consent {',
        ] as $rule) {
            self::assertStringContainsString($rule, $css, $rule);
        }
        // The banner lies over everything (the toolbar, the chat button, the admin bar, the header's menus); only the
        // skip link, shown on keyboard focus, may lie over it.
        preg_match_all('~([^{}]+)\{[^{}]*?\bz-index:\s*(\d+)~', (string) preg_replace('~/\*.*?\*/~s', '', $css), $layers, PREG_SET_ORDER);
        $banner = 0;
        $others = [];
        foreach ($layers as [, $selector, $z]) {
            $selector = trim($selector);
            if ($selector === '.consent') {
                $banner = (int) $z;
            } elseif ($selector !== '.skip-link') {
                $others[$selector] = (int) $z;
            }
        }
        if ($others === []) {
            self::fail('no other layer has a z-index');
        }
        self::assertGreaterThan(max($others), $banner, 'the cookie banner over every other layer');
        self::assertMatchesRegularExpression('~@media \(prefers-reduced-motion: reduce\) \{\s*\*, \*::before, \*::after \{ transition: none !important; animation: none !important;~', $css, 'the fade stops under reduced motion');
        self::assertStringContainsString(':root.a11y-still *, :root.a11y-still *::before, :root.a11y-still *::after { transition: none !important; animation: none !important;', $css, 'and under "Stop animations"');
    }

    public function testTheAccessibilityToolbarScriptAndStylesAgree(): void
    {
        $script = (string) file_get_contents(self::THEME . '/assets/js/a11y.js');
        $css = (string) file_get_contents(self::THEME . '/assets/css/site.css');
        $layout = (string) file_get_contents(self::THEME . '/layouts/default.php');
        $classes = ['a11y-text-1', 'a11y-text-2', 'a11y-text-3', 'a11y-spacing', 'a11y-font', 'a11y-contrast', 'a11y-grayscale', 'a11y-invert', 'a11y-links', 'a11y-focus', 'a11y-cursor', 'a11y-still', 'a11y-guide', 'a11y-no-images'];

        self::assertStringContainsString("const KEY = 'atar-a11y';", $script);
        self::assertStringContainsString("localStorage.getItem('atar-a11y')", $layout, 'the bootstrap reads the same key');
        foreach ($classes as $class) {
            self::assertMatchesRegularExpression('/:root\.' . preg_quote($class, '/') . '\b/', $css, $class . ' has its rules');
        }
        preg_match_all("~data-a11y-option=\"([a-z-]+)\"|'([a-z-]+)'~", (string) file_get_contents(self::THEME . '/partials/a11y.php'), $options);
        foreach (['spacing', 'font', 'contrast', 'grayscale', 'invert', 'links', 'focus', 'cursor', 'still', 'guide', 'no-images'] as $option) {
            self::assertStringContainsString("'" . $option . "'", $script, $option);
            self::assertContains($option, $options[2], 'the partial offers ' . $option);
        }
        // The bootstrap applies only the known classes, the same list as the script's.
        $pattern = Regex::match('~/\^a11y-\(([a-z0-9|\[\]-]+)\)\$/~', $layout, 'the bootstrap names the classes');
        $bootstrap = [];
        foreach (explode('|', $pattern[1]) as $name) {
            $bootstrap = [...$bootstrap, ...($name === 'text-[123]' ? ['text-1', 'text-2', 'text-3'] : [$name])];
        }
        self::assertEqualsCanonicalizing(array_map(static fn(string $class): string => substr($class, 5), $classes), $bootstrap);
        $list = Regex::match("~const OPTIONS = \\[([^\\]]+)\\];~", $script);
        self::assertSame(['spacing', 'font', 'contrast', 'grayscale', 'invert', 'links', 'focus', 'cursor', 'still', 'guide', 'no-images'], array_map(static fn(string $item): string => trim($item, " '"), explode(',', $list[1])));
        // High contrast keeps the focus ring readable: a dark --sun on white, a bright one on black.
        $contrast = Regex::match('/^:root\.a11y-contrast \{(.*?)^\}/ms', $css);
        $contrastDark = Regex::match('/^:root\.a11y-contrast\[data-theme="dark"\] \{(.*?)^\}/ms', $css);
        foreach (['sun', 'danger-soft', 'overlay'] as $token) {
            self::assertStringContainsString('--' . $token . ':', $contrast[1], $token);
            self::assertStringContainsString('--' . $token . ':', $contrastDark[1], $token);
        }
        $sun = Regex::match('/--sun:\s*(#[0-9a-f]{6})/', $contrast[1]);
        self::assertGreaterThanOrEqual(4.5, self::contrast($sun[1], '#ffffff'), 'the focus ring on white');
        $sunDark = Regex::match('/--sun:\s*(#[0-9a-f]{6})/', $contrastDark[1]);
        self::assertGreaterThanOrEqual(4.5, self::contrast($sunDark[1], '#000000'), 'the focus ring on black');
        // The four corners of the button (the setting's logical values): top ones open the panel downwards.
        foreach (['[data-a11y-position^="top"] .a11y-trigger', '[data-a11y-position^="top"] .a11y-panel', '[data-a11y-position$="start"] .a11y-trigger', '[data-a11y-position$="start"] .a11y-panel'] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
        self::assertStringContainsString('new CSSStyleSheet()', $script);
        self::assertStringContainsString('adoptedStyleSheets', $script);
        self::assertStringContainsString('replaceSync', $script);
        foreach (LogicalCss::rules($css) as [$selector, $body]) {
            foreach (LogicalCss::declarations($body) as [$property]) {
                if ($property === 'filter') {
                    self::assertDoesNotMatchRegularExpression('/(^|[\s,>])(html|body|:root)(\.[a-z0-9-]+|:[a-z-]+\([^)]*\))*\s*($|,)/', $selector, 'a filter never on <html> or <body>');
                }
            }
        }
    }

    private static function contrast(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (string $pair): float {
                $c = hexdec($pair) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        $a = $luminance($foreground);
        $b = $luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
