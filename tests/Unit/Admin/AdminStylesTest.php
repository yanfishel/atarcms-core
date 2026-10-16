<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Tests\Support\Regex;
use AtarCms\Theme\Check\LogicalCss;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Keeps the admin templates, scripts and admin.css in sync: every admin-* class they use has a rule, and every
 * icon they name is a symbol of the sprite.
 */
final class AdminStylesTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    private const string TEMPLATES = __DIR__ . '/../../../resources/admin/templates';

    /** PHP classes that print admin-* classes into the pages themselves. */
    private const array SOURCES = [__DIR__ . '/../../../src/Admin/AdminIcon.php'];

    /**
     * Class prefixes that templates complete at run time ('admin-badge-' . $status), with every suffix they get.
     */
    private const array DYNAMIC = [
        'admin-accent-swatch-' => ['green', 'teal', 'blue', 'indigo', 'violet', 'cherry', 'terracotta', 'graphite'],
        'admin-chip-' => ['draft', 'published', 'scheduled', 'active', 'disabled'],
        'admin-toast-' => ['success', 'error', 'info'],
        'admin-depth-' => ['0', '1', '2', '3', '4', '5'],
        'admin-role-' => ['admin', 'editor', 'author'],
        'admin-tone-' => ['1', '2', '3', '4', '5', '6', '7', '8'],
        'admin-chart-bar-' => ['edits', 'publications'],
        'admin-dash-key-' => ['edits', 'publications', 'published', 'scheduled', 'draft'],
        'admin-dash-seg-' => ['published', 'scheduled', 'draft'],
    ];

    /**
     * The rules that hide something whatever the "js" class says, each with the reason the page still works without
     * JavaScript (testEveryRuleThatHidesSomethingIsGatedOrListed).
     */
    private const array HIDDEN_WITHOUT_JS = [
        '[hidden]' => 'the hidden attribute',
        '.admin-sidebar-toggle' => 'shown by ".js .admin-sidebar-toggle"',
        '.admin-brand-name, .admin-nav-heading' => 'the rail, which only shell.js or a stored choice turns on',
        '.admin-topbar .admin-burger' => 'shown by ".js .admin-topbar .admin-burger"',
        '.admin-sidebar .admin-drawer-close' => 'shown on phones by ".js .admin-sidebar .admin-drawer-close" (the drawer\'s own close button; no drawer without JavaScript)',
        '.admin-backdrop' => 'shown on phones while not [hidden]',
        '.admin-theme-menu' => 'shown by ".js .admin-theme-menu"',
        '.admin-menu-trigger::-webkit-details-marker' => 'the marker of <details>',
        '.admin-theme-option > .admin-icon:last-child' => 'the check mark of a theme choice, shown on the checked one (the theme menu itself is JS only)',
        ':root:not(.js) .admin-toasts:empty' => 'an empty toast stack (with JavaScript the stack is pinned and takes no room)',
        '.admin-toast-close' => 'shown by ".js .admin-toast-close"',
        '.admin-breadcrumbs' => 'phones: the page header names the screen; the drawer, or the plain sidebar without JavaScript, leads back',
        '.admin-youtube-play' => 'shown by ".js .admin-youtube-play" (the video plays in place by script; without JavaScript the card links to YouTube)',
        '.admin-youtube-remove' => 'shown by ".js .admin-youtube-remove" (without JavaScript the input is emptied by hand)',
        '.admin-table thead' => 'phones: each cell shows its data-label instead',
        '.admin-media-dropdown-trigger::-webkit-details-marker' => 'the marker of <details>',
        '.admin-toggle input:checked ~ .admin-toggle-off, .admin-toggle input:not(:checked) ~ .admin-toggle-on' => 'the On or Off word of a switch: CSS shows the one of its state',
        '.admin-slides-row' => 'shown by ".js .admin-slides-row" (the compact rows of slides.js; without JavaScript each slide is its fieldset)',
        '.admin-tags' => 'shown by ".js .admin-tags" (the tag box of tags.js; without JavaScript the comma-separated input)',
        '.admin-media-remove, .admin-media-choose' => 'shown by ".admin-media-enabled", which media.js adds',
        '.admin-media-order-help' => 'shown by ".admin-media-enabled:has(.admin-media-chosen > li)" (the "drag to reorder" line of a gallery, once it holds a photo media.js can move)',
        '.admin-tree-handle' => 'shown by ".js .admin-tree-handle" (from the first paint, so the row does not shift when tree.js runs)',
        '.admin-tree-toggle' => 'shown by ".js .admin-tree-toggle" (the text button of the accordion; without JavaScript every list is open)',
        '.admin-chart-axis-minor' => 'every second day label of the dashboard chart on a narrow card (the hidden table keeps every day)',
        '.admin-tab-list' => 'shown by ".js .admin-tab-list"',
        '.admin-color-presets' => 'shown by ".js .admin-color-presets" (the theme colour presets fill the colour input by script; without JavaScript the plain picker)',
    ];

    public function testEveryAdminClassOfTheTemplatesAndScriptsHasARule(): void
    {
        $declared = self::declared();

        foreach (self::used() as $class => $file) {
            self::assertContains($class, $declared, sprintf('%s (%s) has no rule in admin.css', $class, $file));
        }
    }

    public function testEveryRuleOfAdminCssHasAUser(): void
    {
        $used = self::used();

        foreach (self::declared() as $class) {
            self::assertArrayHasKey($class, $used, sprintf('.%s in admin.css is used by no template, script or AdminIcon', $class));
        }
    }

    /**
     * Without JavaScript the drawer never opens, the tab lists never switch and toasts never close: the rules that
     * move the sidebar off screen, hide the panel headings or pin the toasts apply only under the "js" class. A curated list, not every JS-dependent hide: the reverse-direction test and review
     * cover the rest.
     */
    public function testRulesThatNeedJavaScriptAreGatedByTheJsClass(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        foreach (['transform: translateX(-100%)', 'transform: translateX(100%)', 'position: fixed; top: calc(var(--topbar-height) + var(--space-3)); inset-inline-end: var(--space-4)'] as $needle) {
            preg_match_all('/^\s*([^{}\n]+)\{[^}]*' . preg_quote($needle, '/') . '/m', $css, $rules);
            self::assertNotEmpty($rules[1], $needle);
            foreach ($rules[1] as $selector) {
                self::assertStringStartsWith('.js ', trim($selector), $needle);
            }
        }
        self::assertStringContainsString('.js .admin-tab-heading { display: none; }', $css);
        self::assertStringContainsString(".admin-tab-list { display: none; }\n.js .admin-tab-list { display: flex;", $css);
        self::assertStringContainsString(".admin-topbar .admin-burger { display: none; }", $css);
        self::assertStringContainsString('.js .admin-topbar .admin-burger { display: inline-flex; }', $css);
        self::assertStringContainsString(".admin-theme-menu { display: none; }\n.js .admin-theme-menu { display: block; }", $css, 'the theme switch works only with JavaScript');
    }

    /**
     * Every rule that hides an element (display: none, visibility: hidden, the drawer's off-screen transform) applies
     * only with JavaScript (its selector starts with the "js" class or names a class a script adds) or is listed in
     * HIDDEN_WITHOUT_JS with its reason, so a new hiding rule fails until someone decides how the page works without
     * JavaScript.
     */
    public function testEveryRuleThatHidesSomethingIsGatedOrListed(): void
    {
        $scripts = '';
        foreach (glob(self::ASSETS . '/*.js') ?: [] as $script) {
            $scripts .= (string) file_get_contents($script);
        }
        preg_match_all("/classList\\.(?:add|toggle)\\('(admin-[a-z0-9-]+)'/", $scripts, $added);
        self::assertContains('admin-media-enabled', $added[1]);
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(self::ASSETS . '/admin.css'));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

        $listed = [];
        foreach ($rules as [, $selector, $body]) {
            $selector = trim($selector);
            if (preg_match('/display: none|visibility: hidden|translateX\(-100%\)/', $body) !== 1) {
                continue;
            }
            if (!self::gated($selector, array_values(array_unique($added[1])))) {
                self::assertArrayHasKey($selector, self::HIDDEN_WITHOUT_JS, sprintf('"%s" hides something without JavaScript: gate it with .js or list it with its reason', $selector));
                $listed[] = $selector;
            }
        }
        self::assertSame(array_keys(self::HIDDEN_WITHOUT_JS), $listed, 'every listed rule still exists, in stylesheet order');
    }

    /**
     * The gate of testEveryRuleThatHidesSomethingIsGatedOrListed() reads every selector of a list (not only the
     * first) and a script's class only as a whole class name.
     */
    public function testTheHidingGateReadsEverySelectorAndWholeClassNames(): void
    {
        $added = ['admin-media-enabled'];

        self::assertTrue(self::gated('.js .admin-a', $added));
        self::assertTrue(self::gated('html.js .admin-a, :root.js .admin-b', $added));
        self::assertTrue(self::gated('.admin-media-enabled .admin-a', $added));
        self::assertTrue(self::gated('.js .admin-a:is(.admin-b, .admin-c), .admin-media-enabled > .admin-d', $added), 'commas inside parentheses do not split');
        self::assertFalse(self::gated('.js .admin-a, .admin-b', $added), 'the second selector hides without JavaScript');
        self::assertFalse(self::gated('.admin-media-enabled-later .admin-a', $added), 'a longer class name is another class');
        self::assertFalse(self::gated('.admin-a', $added));
        self::assertFalse(self::gated(':root:not(.js) .admin-a', $added));
    }

    /**
     * Whether every selector of $selectors applies only with JavaScript: it starts with the "js" class or names one of
     * the classes scripts add ($added) as a whole class name.
     *
     * @param list<string> $added
     */
    private static function gated(string $selectors, array $added): bool
    {
        $parts = preg_split('/,(?![^()]*\))/', $selectors) ?: [];
        foreach ($parts as $selector) {
            $selector = trim($selector);
            $gated = preg_match('/^(?:html|:root)?\.js[ .:]/', $selector) === 1;
            foreach ($added as $class) {
                $gated = $gated || preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $selector) === 1;
            }
            if (!$gated) {
                return false;
            }
        }

        return $parts !== [];
    }

    /**
     * One stylesheet serves both directions (a Hebrew interface is right to left): sides are logical
     * (margin-inline-start, inset-inline-end, text-align: start, border-start-end-radius…), never left or right, and
     * shorthands give both inline sides the same value. A horizontal move (translateX, translate) is allowed only in
     * the rules listed here, each with its right-to-left twin.
     */
    public function testTheStylesheetHasNoPhysicalHorizontalProperties(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(self::ASSETS . '/admin.css'));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

        $physical = [];
        $moved = [];
        $flipped = [];
        $selectors = [];
        foreach ($rules as [, $selector, $body]) {
            $selector = trim($selector);
            $selectors[] = $selector;
            foreach (explode(';', $body) as $declaration) {
                if (!str_contains($declaration, ':')) {
                    continue;
                }
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                if (LogicalCss::isPhysical($property, $value)) {
                    $physical[] = $selector . ' { ' . $property . ': ' . $value . ' }';
                }
                if (($property === 'transform' && preg_match('/translate(?:X|3d)?\(\s*(?!0[\s,)])/', $value) === 1)
                    || ($property === 'translate' && preg_match('/^0(?:\s|$)/', $value) !== 1)) {
                    $moved[] = $selector;
                }
                if (LogicalCss::isFlip($property, $value)) {
                    $flipped[] = $selector;
                }
            }
        }

        self::assertSame([], $physical, 'use logical properties');
        self::assertSame(self::FLIPPED, $flipped, 'only the listed rules mirror something');
        $allowed = [...array_keys(self::MOVED), ...array_values(self::MOVED)];
        foreach ($moved as $selector) {
            self::assertContains($selector, $allowed, sprintf('"%s" moves sideways: list it with its :dir(rtl) twin', $selector));
        }
        foreach (self::MOVED as $selector => $twin) {
            self::assertContains($selector, $moved, $selector);
            self::assertContains($twin, $selectors, $twin);
        }
    }

    /**
     * Names and titles of content (entries, sections, menu items, files, folders, breadcrumbs, page titles) may be in
     * another language than the interface: each takes its direction from its own text, so "Hello!" stays "Hello!" in a
     * Hebrew interface and "שלום!" stays whole in an English one, without markup in the templates.
     */
    public function testNamesTakeTheirDirectionFromTheirOwnText(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString(
            '.admin-page-header h1, .admin-breadcrumbs li > :is(a, span), .admin-row-link, .admin-row-title, .admin-panel-text > a, .admin-tree-label, .admin-media-name, .admin-media-folder > span, .admin-media-trail li > :is(a, span) { unicode-bidi: plaintext; }',
            $css,
        );
    }

    /**
     * The guard itself: each of these would lay a right-to-left page out wrong, each of the logical ones would not.
     */
    public function testTheGuardKnowsEveryFormOfAPhysicalSide(): void
    {
        foreach ([
            ['margin-left', '0'], ['padding-right', '1px !important'], ['border-left-color', 'red'], ['right', '0'],
            ['text-align', 'left !important'], ['float', 'right'], ['margin', '0 1px 0 2px !important'],
            ['border-width', '1px 2px 1px 0'], ['border-color', 'red blue red green'], ['border-style', 'solid none solid dashed'],
            ['justify-content', 'left'], ['justify-self', 'right !important'], ['justify-items', 'left'],
            ['border-radius', '0 4px 4px 0'], ['border-radius', '4px 0'], ['background-position', 'right top'],
        ] as [$property, $value]) {
            self::assertTrue(LogicalCss::isPhysical($property, $value), $property . ': ' . $value);
        }
        foreach ([
            ['margin-inline-start', '0'], ['margin', '0 1px 0 1px !important'], ['border-width', '1px 2px'],
            ['justify-content', 'flex-end'], ['text-align', 'start'], ['border-radius', '4px 4px 0 0'], ['inset', '0'],
        ] as [$property, $value]) {
            self::assertFalse(LogicalCss::isPhysical($property, $value), $property . ': ' . $value);
        }
        foreach (['scaleX(-1)', 'scale(-1, 1)', 'matrix(-1, 0, 0, 1, 0, 0)', 'rotateY(180deg)'] as $transform) {
            self::assertTrue(LogicalCss::isFlip('transform', $transform), $transform);
        }
        self::assertTrue(LogicalCss::isFlip('scale', '-1 1'));
        self::assertFalse(LogicalCss::isFlip('transform', 'rotate(90deg)'));
        self::assertFalse(LogicalCss::isFlip('scale', '1.05'));
    }

    /** The rules that flip something horizontally (a mirror), allowed only for right-to-left pages. */
    private const array FLIPPED = ['.admin-icon-mirror:dir(rtl)'];


    /** The rules that move something sideways, each with its right-to-left twin (the move reversed). */
    private const array MOVED = [
        '.admin-sidebar-toggle' => '.admin-sidebar-toggle:dir(rtl)',
        '.js .admin-sidebar' => '.js .admin-sidebar:dir(rtl)',
        '.admin-toggle input:checked::before' => '.admin-toggle input:checked:dir(rtl)::before',
    ];



    /**
     * The list filters sit in one row at the control height; the login card's alert uses the card's gap; a boolean
     * field is an .admin-check whose label is bold; a gallery item without a preview keeps its name clear of the
     * "Main" marker and the remove button.
     */
    /**
     * A locked button looks disabled also under the pointer: the red link button's hover (danger tint) must not win.
     */
    public function testALockedButtonStaysMutedOnHover(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');
        $hover = '.admin-button-link.admin-locked:hover, .admin-button-link.admin-button-danger.admin-locked:hover { background: transparent; color: var(--muted); filter: none; }';

        self::assertStringContainsString($hover, $css);
        self::assertGreaterThan((int) strpos($css, '.admin-button-link.admin-button-danger:hover {'), (int) strpos($css, $hover), 'after the danger hover');
    }

    public function testSmallLayoutFixes(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');
        $boolean = (string) file_get_contents(self::TEMPLATES . '/fields/boolean.php');

        self::assertStringContainsString('.admin-filters input:not([type="checkbox"], [type="radio"]), .admin-filters select { height: var(--control-height); }', $css);
        self::assertStringContainsString('.admin-login .admin-alert { margin: 0; }', $css);
        self::assertStringContainsString('<label class="admin-check admin-check-field">', $boolean);
        self::assertStringContainsString('.admin-check-field { font-weight: 600; }', $css);
        self::assertStringNotContainsString('admin-checkbox', $css);
        self::assertStringContainsString('.admin-media-chosen li:not(:has(> .admin-media-thumb)):not(:has(> .admin-media-video)) { padding-inline-end: calc(32px + var(--space-3)); }', $css, 'the name-only chip clears the remove button; a thumbnail or a player needs no room');
    }

    /**
     * The top bar is on every page (the dashboard names itself there); its menus open downwards over the page.
     */
    public function testTheTopBarIsAlwaysShownAndItsMenusOpenDownwardsOverThePage(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringNotContainsString('.admin-topbar:not(', $css, 'no rule hides the top bar');
        self::assertMatchesRegularExpression('/^\.admin-menu-panel \{[^}]*position: absolute; top: 100%; inset-inline-end: 0; z-index: \d+;/m', $css);
        self::assertStringNotContainsString('bottom: 100%', $css, 'nothing opens upwards any more');
    }

    /**
     * Error toasts stay until closed: pinned under the top bar, they never cover its menus.
     */
    public function testToastsSitBelowTheTopBar(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertMatchesRegularExpression('/^    --topbar-height: \d+px;$/m', $css);
        self::assertMatchesRegularExpression('/^\.admin-topbar \{[^}]*min-height: var\(--topbar-height\);/m', $css);
        self::assertStringContainsString('.js .admin-toasts { position: fixed; top: calc(var(--topbar-height) + var(--space-3)); inset-inline-end: var(--space-4);', $css);
    }

    public function testHelpAndFieldErrorTextIsSmall(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString('.admin-help { color: var(--muted); font-size: .8125rem; }', $css);
        self::assertMatchesRegularExpression('/^\.admin-field-error \{[^}]*font-size: \.8125rem;/m', $css);
    }

    /**
     * The sticky action bar floats above the bottom edge as a card, rounded on every corner, on phones too.
     */
    public function testTheActionBarFloatsAsARoundedCard(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        preg_match_all('/^\s*\.admin-action-bar \{([^}]*)\}/m', $css, $rules);
        self::assertCount(2, $rules[1], 'the base rule and the phone rule');
        self::assertStringContainsString('position: sticky; bottom: var(--space-3);', $rules[1][0]);
        self::assertStringContainsString('margin-bottom: var(--space-3);', $rules[1][0]);
        foreach ($rules[1] as $rule) {
            self::assertMatchesRegularExpression('/border-radius: var\(--radius-card\);/', $rule);
        }
        self::assertStringNotContainsString('border-radius: 0', $rules[1][1]);
    }

    /**
     * Below 480 px the secondary actions are icons and the buttons keep one row under the status line, Save included.
     */
    public function testOnAPhoneTheActionsKeepOneRow(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        $phone = Regex::match('/@media \(max-width: 479px\) \{(.*?)\n\}/s', $css);
        self::assertMatchesRegularExpression('/^\s*\.admin-action-bar-buttons \{[^}]*flex-wrap: nowrap;/m', $phone[1]);
        self::assertMatchesRegularExpression('/^\s*\.admin-action-compact \{[^}]*width: var\(--control-height\);/m', $phone[1]);
    }

    /**
     * The fishart logo is white on transparent: inverted to dark on the light palette, as is on the dark ones.
     */
    public function testTheSidebarLogoFollowsThePalette(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');
        $tokens = substr($css, 0, (int) strpos($css, '/* ---------- Base ---------- */'));

        self::assertSame(3, preg_match_all('/--logo-filter: ([^;]+);/', $tokens, $filters), 'light, dark and the device-dark palette');
        self::assertStringContainsString('invert(1)', $filters[1][0]);
        self::assertStringNotContainsString('invert', $filters[1][1]);
        self::assertSame($filters[1][1], $filters[1][2], 'both dark palettes agree');
        self::assertMatchesRegularExpression('/^\.admin-fishart \{[^}]*filter: var\(--logo-filter\);/m', $css);
    }

    /**
     * The top-bar triggers are icon-only squares of the touch-target size.
     */
    public function testTheTopBarTriggersAreIconOnly(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringNotContainsString('admin-menu-text', $css);
        self::assertMatchesRegularExpression('/^\.admin-menu-trigger \{[^}]*justify-content: center; width: var\(--control-height\); height: var\(--control-height\);/m', $css);
    }

    /**
     * The page footer: no background (it just ends the page), the quiet links at the start, the year and the fishart
     * mark pushed to the end. The guest page keeps its card centred in the leftover room.
     */
    public function testThePageFooterIsQuietAndEndsThePage(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        $footer = Regex::match('/^\.admin-footer \{([^}]*)\}/m', $css);
        self::assertStringContainsString('margin-block-start: auto;', $footer[1], 'the footer sits at the bottom even under a short page');
        self::assertStringNotContainsString('background', $footer[1], 'no background');
        self::assertStringNotContainsString('border', $footer[1], 'no separating line either');
        self::assertMatchesRegularExpression('/^\.admin-footer-brand \{[^}]*margin-inline-start: auto;/m', $css, 'the links start the row, the mark ends it');
        self::assertMatchesRegularExpression('/^\.admin-guest \{[^}]*flex-direction: column;[^}]*min-height: 100vh;/m', $css, 'the guest page is a column, so its footer reaches the bottom');
        self::assertMatchesRegularExpression('/^\.admin-guest-main \{[^}]*margin: auto;/m', $css, 'the guest card stays centred in the room above the footer');
    }

    public function testEveryIconTheTemplatesNameIsInTheSprite(): void
    {
        $symbols = self::symbols();

        self::assertContains('circle', $symbols, 'the default icon of menu items and fields');
        foreach (self::iconNames() as $name => $file) {
            self::assertContains($name, $symbols, sprintf('icon "%s" (%s) is not in icons.svg', $name, $file));
        }
    }

    /**
     * The row link's ::after overlay covers the row, so a click anywhere opens the entry; the row's own links and
     * buttons must sit above it or they could never be clicked.
     */
    public function testTheRowLinkOverlayLeavesTheControlsOfTheRowClickable(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString('.admin-table-row { position: relative; }', $css);
        self::assertStringContainsString('.admin-row-link::after { content: ""; position: absolute; inset: 0; }', $css);
        self::assertStringContainsString('.admin-table-row a:not(.admin-row-link), .admin-table-row button, .admin-table-row input, .admin-table-row select, .admin-table-row summary, .admin-table-row label { position: relative; z-index: 1; }', $css);
    }

    public function testTheTokensAreTheOnlyPlaceWithLiteralColours(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');
        $start = strpos($css, '/* ---------- Base ---------- */');

        self::assertNotFalse($start);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', substr($css, $start));
    }

    /**
     * @return array<string, string> class => the first file that uses it
     */
    private static function used(): array
    {
        $files = [...self::files(self::TEMPLATES, 'php'), ...(glob(self::ASSETS . '/*.js') ?: []), ...self::SOURCES];
        $used = [];
        foreach ($files as $file) {
            preg_match_all('/admin-[a-z0-9-]+/', (string) file_get_contents($file), $matches);
            foreach ($matches[0] as $token) {
                if (str_ends_with($token, '-')) {
                    self::assertArrayHasKey($token, self::DYNAMIC, sprintf('%s: list the dynamic prefix "%s" in AdminStylesTest::DYNAMIC', basename($file), $token));
                    foreach (self::DYNAMIC[$token] as $suffix) {
                        $used[$token . $suffix] ??= basename($file);
                    }
                } else {
                    $used[$token] ??= basename($file);
                }
            }
        }
        ksort($used);

        return $used;
    }

    /**
     * @return list<string> the classes admin.css has a selector for
     */
    private static function declared(): array
    {
        preg_match_all('/\.(admin-[a-z0-9-]+)/', (string) file_get_contents(self::ASSETS . '/admin.css'), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, string> icon name => the first file that names it
     */
    private static function iconNames(): array
    {
        $names = [];
        foreach (self::files(self::TEMPLATES, 'php') as $file) {
            preg_match_all("/->icon\\('([^']*)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $name) {
                $names[$name] ??= basename($file);
            }
        }

        return $names;
    }

    /**
     * @return list<string> the symbol ids of icons.svg
     */
    private static function symbols(): array
    {
        preg_match_all('/<symbol id="([^"]+)"/', (string) file_get_contents(self::ASSETS . '/icons.svg'), $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory, string $extension): array
    {
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The dashboard's "Drafts and scheduled" tile reads like the others: each number over its caption, on the lines
     * of the neighbouring tiles' numbers and notes, while the markup keeps the term before its value (dt, dd).
     */
    public function testTheDashboardPairShowsTheNumberOverItsCaption(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString('.admin-dash-pair-item { display: flex; flex-direction: column-reverse; justify-content: flex-end; gap: var(--space-1); }', $css);
        self::assertStringContainsString('.admin-dash-tile-value, .admin-dash-pair dd {', $css, 'the same type as the other tiles\' numbers');
        self::assertMatchesRegularExpression('/\.admin-dash-tile \{[^}]*gap: var\(--space-1\)/', $css, 'the same gap between number and caption');
    }

    /**
     * The chart's end labels stay inside the plot in both directions (the first and the last label are pushed
     * inwards from their edge, which flex alignment does even when they overflow their column), and the plot keeps
     * the bars off the card's edge. The latest messages' rows follow the interface direction: only the visitor's
     * texts are isolated (bdi in the markup), never a whole grid item (plaintext would align it by its own text).
     */
    public function testTheDashboardChartEdgesAndMessageRowsWorkRightToLeft(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString('.admin-chart-axis li { display: flex; flex: 1 1 0; justify-content: center; min-inline-size: 0; white-space: nowrap; }', $css);
        self::assertStringContainsString('.admin-chart-axis li:first-child { justify-content: flex-start; }', $css);
        self::assertStringContainsString('.admin-chart-axis li:last-child { justify-content: flex-end; }', $css);
        self::assertStringContainsString('.admin-chart-svg, .admin-chart-hits { position: absolute; inset-block: 0; inset-inline: var(--chart-inset); }', $css);
        preg_match_all('/\.admin-dash-message[a-z-]* \{[^}]*\}/', $css, $rules);
        self::assertNotEmpty($rules[0]);
        foreach ($rules[0] as $rule) {
            self::assertStringNotContainsString('plaintext', $rule);
        }
        self::assertMatchesRegularExpression('/\.admin-dash-event-item \{(?![^}]*plaintext)[^}]*\}/', $css, 'a translated sentence whose names the translator isolates');
    }

    public function testCompactControlsKeepTheTouchTargetHeight(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        foreach (['.admin-sidebar-toggle', '.admin-toast-close', '.admin-button-small', '.admin-check', '.admin-menu-trigger', '.admin-menu-item'] as $selector) {
            $rule = Regex::match('/^' . preg_quote($selector, '/') . '[^{]*\{([^}]*)\}/m', $css, $selector);
            self::assertStringNotContainsString('32px', $rule[1], $selector);
            self::assertMatchesRegularExpression('/(min-)?height: var\(--control-height\)/', $rule[1], $selector);
        }
        foreach (['.admin-sidebar-toggle', '.admin-toast-close', '.admin-menu-trigger'] as $selector) {
            $rule = Regex::match('/^' . preg_quote($selector, '/') . '[^{]*\{([^}]*)\}/m', $css, $selector);
            self::assertStringContainsString('width: var(--control-height)', $rule[1], $selector);
        }
    }

    /**
     * A documented small target (docs/admin-ui.md, "Rules the tests enforce"): the media widget's Choose is a compact
     * button (32 px, a smaller font and icon) under the chosen items, so it never crowds the field's label.
     */
    public function testTheMediaChooseButtonIsCompact(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        foreach ([
            '.admin-button-compact { gap: var(--space-1); min-height: var(--control-height-compact); padding: 0 var(--space-3); font-size: .8125rem; }',
            '.admin-button-compact .admin-icon { width: 1rem; height: 1rem; }',
            '.admin-media-field { display: grid; gap: var(--space-2); }',
            '.admin-media-choose { justify-self: start; }',
        ] as $rule) {
            self::assertStringContainsString($rule, $css, $rule);
        }
        self::assertGreaterThan(
            strpos($css, '.admin-button-small {'),
            strpos($css, '.admin-button-compact {'),
            'the compact size comes after the base button rules it overrides',
        );
    }

    /**
     * A documented small target (docs/admin-ui.md, "Rules the tests enforce"): the translation offer's actions are one
     * row of compact buttons that wraps. Translate (shown by translate.js) is the primary; beside it Copy is a link-style
     * button, and alone (no JavaScript, no on-device translator) Copy is the primary, by CSS only.
     */
    public function testTheTranslationOfferIsOneRowWithOnePrimaryAction(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        foreach ([
            '.admin-toast-form { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin: 0; }',
            '.admin-toast-form:has(> [data-machine-offer]:not([hidden])) > .admin-toast-copy { background: transparent; border-color: transparent; color: var(--accent-strong); }',
            '.admin-toast-form:has(> [data-machine-offer]:not([hidden])) > .admin-toast-copy:hover, .admin-toast-form:has(> [data-machine-offer]:not([hidden])) > .admin-toast-copy:focus-visible { background: var(--accent-soft); border-color: transparent; color: var(--accent-strong); }',
        ] as $rule) {
            self::assertStringContainsString($rule, $css, $rule);
        }
        self::assertGreaterThan(strpos($css, '.admin-button:hover {'), strpos($css, '.admin-toast-form:has('), 'the link style comes after the button rules it overrides');
    }

    /**
     * A documented small target (docs/admin-ui.md, "Rules the tests enforce"): the × of a tag pill is a 24 px circle
     * (a token), like the translations switch's segments; the 32 px pill around it is what the eye aims at.
     */
    public function testTheTagPillRemoveButtonIsTheTinyException(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        $remove = Regex::match('/^\.admin-tags-remove \{([^}]*)\}/m', $css);
        self::assertStringContainsString('width: var(--control-height-tiny);', $remove[1]);
        self::assertStringContainsString('height: var(--control-height-tiny);', $remove[1]);
        $pill = Regex::match('/^\.admin-tags-pill \{([^}]*)\}/m', $css);
        self::assertStringContainsString('min-height: var(--control-height-compact);', $pill[1]);
        $box = Regex::match('/^\.admin-tags-box \{([^}]*)\}/m', $css);
        self::assertStringContainsString('min-height: var(--control-height);', $box[1], 'the box keeps the control height');
    }

    /**
     * The menu location switch is the one compact control: 32 px items (a token, not a literal) in a smaller font, with
     * the focus ring drawn inside, and it wraps onto more rows when a site has many languages.
     */
    public function testTheLanguageSwitchIsCompactAndWraps(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertMatchesRegularExpression('/^    --control-height-compact: 32px;$/m', $css);
        $item = Regex::match('/^\.admin-switch > a, \.admin-switch > span \{([^}]*)\}/m', $css);
        self::assertStringContainsString('min-height: var(--control-height-compact);', $item[1]);
        self::assertStringContainsString('min-width: var(--control-height-compact);', $item[1]);
        self::assertStringContainsString('font-size: .8125rem;', $item[1]);
        self::assertStringContainsString('padding: 0 var(--space-2);', $item[1]);
        self::assertMatchesRegularExpression('/^\.admin-switch \{[^}]*flex-wrap: wrap;[^}]*max-width: 100%;/m', $css);
        self::assertMatchesRegularExpression('/^\.admin-switch > a:focus-visible \{ outline-offset: -2px; \}$/m', $css);
    }

    /**
     * The rail toggle straddles the sidebar's edge at the bottom, beside the centred logo: a small circle drawn inside a 40 px button, on the <aside>
     * itself (the nav is the scroll container and would clip it), under the drawer rules hidden on phones.
     */
    public function testTheSidebarToggleSitsOnTheSidebarEdge(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        $sidebar = Regex::match('/^\.admin-sidebar \{([^}]*)\}/m', $css);
        self::assertStringNotContainsString('overflow', $sidebar[1], 'the toggle hangs outside the sidebar');
        $toggle = Regex::match('/^\.admin-sidebar-toggle \{([^}]*)\}/m', $css);
        foreach (['position: absolute;', 'bottom: var(--space-4);', 'inset-inline-end: 0;', 'transform: translateX(50%);', 'border-radius: 50%;'] as $declaration) {
            self::assertStringContainsString($declaration, $toggle[1], $declaration);
        }
        $circle = Regex::match('/^\.admin-sidebar-toggle::before \{([^}]*)\}/m', $css, 'the visible circle is smaller than the hit area');
        foreach (['width: 32px;', 'height: 32px;', 'border-radius: 50%;', 'border: 1px solid var(--border-strong);', 'background: var(--raised);', 'box-shadow: var(--shadow-card);'] as $declaration) {
            self::assertStringContainsString($declaration, $circle[1], $declaration);
        }
        self::assertMatchesRegularExpression('/^\.admin-sidebar \{[^}]*padding: var\(--space-4\) var\(--space-3\);/m', $css, 'no reserved band under the nav');
        self::assertDoesNotMatchRegularExpression('/^\.admin-nav \{[^}]*padding-block-end/m', $css, 'no reserved room inside the scroll either');
        self::assertMatchesRegularExpression('/@media \(max-width: 767px\) \{[^@]*\.js \.admin-sidebar-toggle \{ display: none; \}/', $css, 'the burger does the job on phones');
    }

    /**
     * The picker dialog's own toast stack sits inside the dialog, at the bottom of its scroll area, instead of the page
     * stack's fixed top-right spot (which would cover the dialog's close button and search field).
     */
    public function testThePickerToastsStayInsideTheDialogAboveItsFooter(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        $stack = Regex::match('/^\.js \.admin-media-dialog \.admin-toasts \{([^}]*)\}/m', $css);
        foreach (['position: sticky;', 'top: auto;', 'inset-inline-end: auto;', 'bottom: var(--space-3);', 'width: auto;'] as $declaration) {
            self::assertStringContainsString($declaration, $stack[1], $declaration);
        }
    }

    public function testEmptyNestedTreeListsDrawNoGuideLines(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringNotContainsString('.admin-tree:empty', $css, 'the nested list holds a newline, so :empty never matches');
        self::assertStringContainsString('.admin-tree .admin-tree:not(:has(> li))', $css);
    }

    /**
     * An empty nested list (the drop target that lets a drag give an item children) takes no room, at rest and during
     * a drag alike: a row with one and a pinned row without one are spaced alike, and nothing moves when a drag starts
     * (a shift would put another row under the pointer). SortableJS accepts a drop into such a zero-height list when
     * the pointer comes within its emptyInsertThreshold.
     */
    public function testEmptyNestedTreeListsTakeNoRoomEvenDuringADrag(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');
        $tree = (string) file_get_contents(self::ASSETS . '/tree.js');

        self::assertMatchesRegularExpression('/^\.admin-tree \.admin-tree \{ margin-block: var\(--space-2\) 0; margin-inline: var\(--space-4\) 0; /m', $css, 'no min-height of its own; the first child sits as far below its parent as rows sit apart');
        self::assertStringContainsString('.admin-tree .admin-tree:not(:has(> li)) { margin-top: 0; border-inline-start-color: transparent; }', $css);
        self::assertStringNotContainsString('admin-tree-dragging', $css, 'no layout change during a drag');
        self::assertStringNotContainsString('admin-tree-dragging', $tree);
        self::assertDoesNotMatchRegularExpression('/\.admin-tree(?::not\(:has\(> li\)\))? \{[^}]*min-height/', $css, 'no tree list gets a height');
        self::assertStringContainsString('emptyInsertThreshold: EMPTY_INSERT_THRESHOLD,', $tree);
        self::assertStringContainsString('const EMPTY_INSERT_THRESHOLD = 16;', $tree);
    }

    /**
     * Tree rows are one compact line (a user decision of Plan 13): the handle and the row actions are 32 px (the
     * compact token), the icons 1rem, the meta text stays on the label's line and is cut with an ellipsis. The row
     * keeps the same padding on both sides (Plan 13b: the space before the handle equals the space after the last
     * button); the moves are secondary and smaller (24 px, the tiny token, with smaller icons); Delete is red.
     */
    public function testTreeRowsAreOneCompactLine(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        foreach ([
            '.admin-tree { display: grid; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }',
            '.admin-tree-row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); padding: var(--space-1) var(--space-2);',
            '.admin-tree-row .admin-icon { width: 1rem; height: 1rem; }',
            '.admin-tree-handle { display: none; align-items: center; justify-content: center; min-width: var(--control-height-compact); min-height: var(--control-height-compact); margin-inline: calc(var(--space-2) * -1);',
            '.admin-tree-text { display: flex; flex: 1; align-items: baseline; gap: var(--space-2); min-width: 10rem; }',
            '.admin-tree-meta { overflow: hidden; min-width: 0; font-size: .8125rem; text-overflow: ellipsis; white-space: nowrap; }',
            '.admin-tree-moves .admin-icon { width: .875rem; height: .875rem; }',
            '.admin-content .admin-segmented button { display: inline-grid; place-items: center; min-width: var(--control-height-tiny); min-height: var(--control-height-tiny);',
            '.admin-content .admin-row-action { display: inline-grid; place-items: center; width: var(--control-height-compact); height: var(--control-height-compact);',
            '.admin-content .admin-row-action-danger { color: var(--danger); }',
        ] as $rule) {
            self::assertStringContainsString($rule, $css, $rule);
        }
        self::assertStringNotContainsString('.admin-tree-add', $css, 'the add buttons sit in the page header');
        self::assertStringContainsString('.admin-locale-flag-large { inline-size: 22px; block-size: 22px; }', $css);
        self::assertStringContainsString(
            '.admin-content-locale > .admin-menu-trigger:has(> .admin-locale-badge) { gap: var(--space-1); width: auto; padding: 0 var(--space-2); }',
            $css,
            'only the flagless trigger (the languages icon and its pill) grows; the flag stays a 40px square',
        );
        self::assertStringNotContainsString('.admin-theme-menu > .admin-menu-trigger', $css, 'the theme trigger is a plain icon button');
        self::assertDoesNotMatchRegularExpression('/\.admin-content-locale > \.admin-menu-trigger[ ,{]/', $css, 'the flag trigger is a plain icon button');
        self::assertMatchesRegularExpression('/^    --control-height-tiny: 24px;$/m', $css);
        self::assertDoesNotMatchRegularExpression('/\.admin-tree-row[^{]*\{[^}]*padding-inline(?:-start|-end)?: 0/', $css, 'no side of a row loses its padding');
        self::assertLessThan(
            strpos($css, '.admin-content .admin-row-action:disabled {'),
            strpos($css, '.admin-content .admin-row-action-danger { color: var(--danger); }'),
            'a disabled Delete is grey: its rule comes later (and is more specific)',
        );
    }

    /**
     * The tree's accordion (Plan 13b): with JavaScript a nested list with items is hidden unless its item is open,
     * except while it holds the dragged item (SortableJS's ghost), so a drop into a collapsed item's empty list stays
     * visible. The toggle is a muted text button (the number of children and a chevron) in the row's controls, right
     * before the moves, in the flexible room to their left (the moves and the actions keep their columns); it is not
     * drawn on an item without children and its chevron turns to point down on an open one. The row of an item with
     * children is a toggle too (tree.js): a pointer over it, its border a little darker. Without JavaScript every list
     * shows and the toggle is not drawn. The handle and the toggle show under the root's "js" class (set before the
     * first paint), not under a class tree.js adds when it runs after the first paint, so nothing moves on a cold load.
     */
    public function testTheTreeIsAnAccordionOnlyWithJavaScript(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        foreach ([
            '.admin-tree-toggle { display: none; flex: none; align-items: center; gap: 2px; min-height: var(--control-height-tiny); margin-inline-start: auto; padding: 0 var(--space-1); border: 0; border-radius: var(--radius-control); background: transparent; color: var(--muted); font-size: .8125rem; font-weight: 600; white-space: nowrap; cursor: pointer; }',
            '.admin-tree-toggle:hover { color: var(--accent-strong); }',
            '.js .admin-tree-handle { display: inline-flex; }',
            '.js .admin-tree-toggle { display: inline-flex; }',
            '.admin-tree-row .admin-tree-toggle .admin-icon { width: .875rem; height: .875rem; }',
            '.admin-tree-open > .admin-tree-row .admin-tree-toggle .admin-icon { transform: rotate(90deg); }',
            '.js .admin-tree-item:not(:has(> .admin-tree > li)) > .admin-tree-row .admin-tree-toggle { display: none; }',
            '.js .admin-tree-item:has(> .admin-tree > li) > .admin-tree-row { cursor: pointer; }',
            '.js .admin-tree-item:has(> .admin-tree > li) > .admin-tree-row:hover { border-color: var(--border-strong); }',
            '.js .admin-tree-item:not(.admin-tree-open) > .admin-tree:has(> li):not(:has(> .sortable-ghost)) { display: none; }',
        ] as $rule) {
            self::assertStringContainsString($rule, $css, $rule);
        }
        self::assertStringNotContainsString('admin-tree-enabled', $css, 'nothing of a row waits for tree.js');
        self::assertStringNotContainsString('.admin-tree-toggle { position', $css, 'no tab under the row');
        self::assertStringNotContainsString('.admin-tree-row { position: relative;', $css);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 767px\) \{\s*\.admin-tree \.admin-tree \{ margin-inline-start: var\(--space-2\); padding-inline-start: var\(--space-2\); \}\s*\}/',
            $css,
            'on a phone a level indents less, so the row controls stay in one line of columns a few levels deep',
        );
    }

    /**
     * The entry form's two columns stack by the width of the form itself, not of the viewport: the sidebar (full or
     * a rail) changes the room the form gets. Every grid level below it may shrink, so a wide child (the editor's
     * toolbar) can neither widen a column nor push the page past the viewport.
     */
    public function testTheEntryFormStacksByItsOwnWidthAndItsColumnsCanShrink(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertMatchesRegularExpression('/^\.admin-entry-form \{[^}]*container: entry-form \/ inline-size;/m', $css);
        self::assertMatchesRegularExpression('/@container entry-form \(max-width: \d+px\) \{\n    \.admin-form-layout \{ grid-template-columns: minmax\(0, 1fr\); \}/', $css);
        self::assertDoesNotMatchRegularExpression('/@media[^{]*\{\n(    [^\n]*\n)*?    \.admin-form-layout/', $css, 'no viewport query stacks the form');
        self::assertStringContainsString('.admin-form-layout-wide { grid-template-columns: minmax(0, 1fr); }', $css, 'a form without a side column is one column');
        foreach (['.admin-form-column', '.admin-tab-panel', '.admin-field'] as $selector) {
            $rule = Regex::match('/^' . preg_quote($selector, '/') . ' \{([^}]*)\}/m', $css, $selector);
            self::assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $rule[1], $selector);
            self::assertStringContainsString('min-width: 0;', $rule[1], $selector);
        }
        foreach (['.admin-main', '.admin-content'] as $selector) {
            self::assertMatchesRegularExpression('/^' . preg_quote($selector, '/') . ' \{[^}]*min-width: 0;/m', $css, $selector);
        }
        self::assertStringNotContainsString('overflow-x: hidden', $css, 'overflow is fixed, not hidden');
    }

    /**
     * vendor/ckeditor5-48.5.2.css is an extra stylesheet, loaded after admin.css: every override is prefixed with
     * :root to win. The editor's colours come from the tokens (its --ck-color-* variables, declared on .ck), and the
     * video previews of editor.js are sized here, without inline styles.
     */
    public function testTheEditorOverridesOutrankTheVendorStylesheetAndFollowTheTokens(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');
        $editor = substr($css, (int) strpos($css, '/* ---------- Editor'));

        self::assertStringNotContainsStringIgnoringCase('trix', $css);
        self::assertNotSame($css, $editor, 'the Editor section exists');
        preg_match_all('/^\s*([^{}\n@\/-][^{}\n]*)\{/m', $editor, $selectors);
        self::assertNotEmpty($selectors[1]);
        foreach ($selectors[1] as $list) {
            foreach (explode(',', $list) as $selector) {
                self::assertStringStartsWith(':root', trim($selector), $selector);
            }
        }
        $variables = Regex::match('/^:root \.ck \{([^}]*)\}/m', $editor);
        preg_match_all('/^    (--ck-[a-z0-9-]+): ([^;]+);$/m', $variables[1], $declarations, PREG_SET_ORDER);
        self::assertGreaterThan(20, count($declarations));
        foreach ($declarations as [, $name, $value]) {
            self::assertMatchesRegularExpression('/^var\(--[a-z0-9-]+\)$/', $value, $name . ' comes from a token');
        }
        self::assertMatchesRegularExpression('/^:root \.ck\.ck-editor__main > \.ck-editor__editable \{[^}]*min-height: 12rem;/m', $editor);
        self::assertStringContainsString(':root .admin-editor-video { display: block; width: 100%; height: auto; aspect-ratio: 16 / 9; border: 0; }', $editor);
        self::assertStringNotContainsString('ck-progress-bar', $css, 'the upload progress bar works');
        self::assertStringContainsString(':root .admin-editor-minimal .ck.ck-editor__main > .ck-editor__editable { min-height: 5rem; }', $editor, 'a minimal field is short');
        self::assertStringContainsString(':root .admin-field-tall .ck.ck-editor__main > .ck-editor__editable { min-height: 28rem; }', $editor, 'a tall field is a page long');
        self::assertStringContainsString(':root .admin-field-rich-text > .ck.ck-word-count { grid-column: 2; }', $editor, 'the word count beside the help');
        self::assertStringContainsString('.admin-field-rich-text > .admin-field-help { grid-column: 1; }', $css);
        self::assertStringContainsString(':root .ck.ck-dropdown.ck-heading-dropdown .ck-dropdown__button .ck-button__label { width: 6em; }', $editor, 'a narrower heading list keeps the toolbar to two rows');
        self::assertStringContainsString(':root .ck.ck-dropdown.ck-style-dropdown > .ck-dropdown__button > .ck-button__label { width: 5em; }', $editor);
        self::assertMatchesRegularExpression('/^:root \.ck\.ck-word-count \{[^}]*color: var\(--muted\);/m', $editor, 'the word count has a rule');
        self::assertStringContainsString(':root .ck-fullscreen__main-wrapper .ck-fullscreen__editable .ck.ck-editor__editable:not(.ck-editor__nested-editable) { background: var(--raised); color: var(--text); }', $editor, 'fullscreen follows the palette');
    }

    /**
     * The language and theme panels are as wide as their items; only the account panel keeps a minimum width.
     */
    public function testTheTopBarPanelsSizeToTheirContent(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertMatchesRegularExpression('/^\.admin-menu-panel \{[^}]*width: max-content; min-width: 0;/m', $css);
        self::assertMatchesRegularExpression('/^\.admin-menu-item \{[^}]*white-space: nowrap;/m', $css);
        // The check mark of the current language sits after its name; the other items keep its room at the end.
        self::assertMatchesRegularExpression('/^\.admin-menu-item \{[^}]*justify-content: space-between;[^}]*padding-block: 0; padding-inline: var\(--space-3\) calc\(var\(--space-3\) \+ 1\.25em \+ var\(--space-2\)\);/m', $css);
        self::assertMatchesRegularExpression('/^\.admin-menu-item\[aria-current="true"\] \{ padding-inline-end: var\(--space-3\); \}$/m', $css);
        self::assertMatchesRegularExpression('/^\.admin-menu-panel-account \{ gap: var\(--space-1\); min-width: 14rem; \}$/m', $css);
        // The account menu's actions are items with their icon first; Sign out is set off by a line.
        self::assertMatchesRegularExpression('/^\.admin-menu-item-icon \{ justify-content: flex-start; padding-inline-end: var\(--space-3\);/m', $css);
        self::assertMatchesRegularExpression('/^\.admin-menu-separator \{[^}]*border-top: 1px solid var\(--border\);/m', $css);
        self::assertStringNotContainsString('admin-menu-heading', $css, 'the panels have no headings');
    }

    /**
     * On a phone the top bar keeps its buttons full size: the burger and the menu triggers never shrink, and the
     * breadcrumbs give way (the page header names the screen; the drawer, or the plain sidebar without JavaScript,
     * leads everywhere).
     */
    public function testThePhoneTopBarKeepsItsButtonsFullSizeAndDropsTheBreadcrumbs(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString('.admin-burger { flex: none; min-inline-size: var(--control-height); min-block-size: var(--control-height); }', $css, 'the burger never shrinks');
        self::assertMatchesRegularExpression('/^\.admin-menu-trigger \{ display: flex; flex: none;[^}]*width: var\(--control-height\); height: var\(--control-height\);/m', $css, 'nor do the inbox and the menu triggers');
        $phone = Regex::match('/^\/\* Phones: [^\n]*\*\/\n@media \(max-width: 767px\) \{(.*?)\n\}/ms', $css);
        self::assertMatchesRegularExpression('/^\s*\.admin-breadcrumbs \{ display: none; \}$/m', $phone[1], 'the breadcrumbs give way on phones');
        self::assertStringNotContainsString('.admin-breadcrumbs { display: none; }', (string) preg_replace('/@media \(max-width: 767px\) \{.*?\n\}/s', '', $css), 'only on phones');
    }

    /**
     * The theme menu's choices are the language menu's items (admin-menu-item): the checked one takes the current
     * language's accent label and shows the check mark at the end; the radio is hidden but focusable, its focus ring
     * drawn on the row.
     */
    public function testTheThemeChoicesLookLikeTheLanguageItems(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString('.admin-menu-item[aria-current="true"], .admin-menu-item:has(> input:checked) { color: var(--accent-strong); font-weight: 600; }', $css, 'one current state for both menus');
        self::assertMatchesRegularExpression('/^\.admin-theme-option \{[^}]*justify-content: flex-start; padding-inline-end: var\(--space-3\);/m', $css, 'the check mark keeps its own room');
        self::assertMatchesRegularExpression('/^\.admin-theme-option > input \{[^}]*position: absolute;[^}]*clip-path: inset\(50%\);/m', $css, 'no radio circle, still focusable');
        self::assertStringContainsString('.admin-theme-option > .admin-icon:last-child { margin-inline-start: auto; visibility: hidden; color: inherit; }', $css, 'the check mark at the end');
        self::assertStringContainsString('.admin-theme-option:has(> input:checked) > .admin-icon:last-child { visibility: visible; }', $css);
        self::assertStringContainsString('.admin-theme-option:has(> input:focus-visible) { outline: 2px solid var(--accent); outline-offset: -2px; }', $css, 'the focus ring on the row');
        self::assertStringNotContainsString('accent-color', (string) preg_replace('/^(?!\.admin-theme).*$/m', '', $css), 'no styled radio circles');
    }

    public function testTheMenuFormShowsOnlyTheChosenTarget(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        foreach (['entry', 'term', 'url'] as $type) {
            self::assertStringContainsString('.js .admin-fieldset:has(input[name="target_type"][value="' . $type . '"]:checked) [data-target]:not([data-target="' . $type . '"]) { display: none; }', $css, $type);
        }
    }
}
