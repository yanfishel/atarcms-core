<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class ShellAssetsTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    public function testTheShellModuleNeverInjectsMarkupStylesOrUrls(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'import '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $shell, $forbidden);
        }
    }

    public function testTheShellStoresTheThemeAndTheSidebarUnderTheKeysTheLayoutReads(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');
        $layout = (string) file_get_contents(__DIR__ . '/../../../resources/admin/templates/layouts/admin.php');

        foreach (["'admin.theme'", "'admin.sidebar'"] as $key) {
            self::assertStringContainsString($key, $shell);
            self::assertStringContainsString(str_replace("'", '"', $key), $layout);
        }
        self::assertStringContainsString('[data-theme-choice]', $shell);
        self::assertStringContainsString('data-theme-choice', $layout);
    }

    /**
     * The top-bar menus are <details> elements that open without JavaScript; shell.js only adds closing them with
     * Escape or a click outside, keeps one open at a time, and shows the stored theme on the theme trigger.
     */
    public function testTheShellClosesTheTopBarMenusAndShowsTheStoredThemeOnItsTrigger(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');
        $layout = (string) file_get_contents(__DIR__ . '/../../../resources/admin/templates/layouts/admin.php');

        foreach (['data-menu', 'data-theme-icon', 'data-theme-trigger'] as $hook) {
            self::assertStringContainsString('[' . $hook . ']', $shell, $hook);
            self::assertStringContainsString($hook, $layout, $hook);
        }
        self::assertStringContainsString("addEventListener('toggle'", $shell, 'opening one menu closes the others');
        self::assertStringContainsString(".open = false", $shell);
        self::assertStringContainsString("event.key === 'Escape'", $shell);
        self::assertStringContainsString('dataset.themeTitle', $shell, 'the trigger name starts with the title the server printed');
        self::assertStringContainsString('data-theme-title=', $layout);
        self::assertStringContainsString('.title = ', $shell, 'the tooltip follows the stored theme');
        self::assertStringContainsString('.ariaLabel = ', $shell, 'so does the accessible name, through the property');
        self::assertStringContainsString('.hidden = ', $shell, 'the trigger icon is switched by the hidden property');
    }

    public function testToastsAddedLaterAreWiredLikeTheServerRenderedOnes(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');
        $layout = (string) file_get_contents(__DIR__ . '/../../../resources/admin/templates/layouts/admin.php');
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString('new MutationObserver(', $shell);
        self::assertStringContainsString("document.querySelectorAll('[data-toasts]')", $shell);
        self::assertStringContainsString('{ childList: true }', $shell);
        self::assertStringContainsString('<div class="admin-toasts" data-toasts aria-live="polite">', $layout);
        self::assertStringContainsString(':root:not(.js) .admin-toasts:empty { display: none; }', $css, 'under .js the empty stack stays rendered: a live region that appears with its content is not announced');
        self::assertStringContainsString('.js .admin-media-dialog .admin-toasts:empty { margin: 0; }', $css);
        self::assertStringContainsString("querySelector('[data-media-dialog-body] input:not([type=\"hidden\"]), [data-media-dialog-body] button')", $shell, 'closing a toast in the picker focuses its search, not the header close button');
    }

    /**
     * The drawer slides only after the burger was used: crossing the 768 px line by resizing the window moves the
     * sidebar without an animation. An opening drawer is visible at once (shell.js focuses its first link, which a
     * hidden element cannot take); a closing one is hidden only after sliding out. While it is open, Tab and
     * Shift+Tab stay inside it.
     */
    public function testTheDrawerAnimatesOnlyOnceOpenedByHandAndKeepsTheFocus(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString('.js.admin-drawer-animated .admin-sidebar { transition: transform .2s, visibility 0s .2s; }', $css);
        self::assertStringContainsString('.js.admin-drawer-animated.admin-drawer-open .admin-sidebar { transition: transform .2s; }', $css);
        self::assertSame(2, substr_count($css, 'transition: transform'), 'no other rule animates the sidebar');
        self::assertStringContainsString("root.classList.add('admin-drawer-animated')", $shell, 'the burger turns the animation on');
        self::assertStringContainsString("root.classList.remove('admin-drawer-animated')", $shell, 'crossing the breakpoint turns it off');
        self::assertStringContainsString("event.key !== 'Tab'", $shell);
        self::assertStringContainsString('last.focus()', $shell, 'Shift+Tab on the first control wraps to the last');
        self::assertStringContainsString('first.focus()', $shell, 'Tab on the last control wraps to the first');
        self::assertStringContainsString('getClientRects().length > 0', $shell, 'hidden controls are skipped');
    }

    /**
     * The open drawer covers the burger, so it has a close button of its own (the × in its brand band): only on
     * phones with JavaScript, wired to setDrawer(false), which returns the focus to the burger.
     */
    public function testTheDrawerHasItsOwnCloseButton(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringContainsString("document.querySelector('[data-drawer-dismiss]')", $shell);
        self::assertStringContainsString("dismiss?.addEventListener('click', () => setDrawer(false));", $shell);
        self::assertStringContainsString("\n.admin-sidebar .admin-drawer-close { display: none; }", $css, 'no drawer without JavaScript or above 768px; two classes beat the later .admin-button rule');
        self::assertMatchesRegularExpression('~@media \(max-width: 767px\) \{[^@]*\.js \.admin-sidebar \.admin-drawer-close \{ display: inline-flex;~', $css, 'shown on phones only');
    }

    /**
     * The toasts the server rendered (flashes, a refused post's errors) are announced: their texts, errors first, go
     * into the layout's empty status region once the page has settled.
     */
    public function testToastsPresentAtLoadAreAnnounced(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');

        self::assertStringContainsString("document.querySelector('[data-toast-announcer]')", $shell);
        self::assertMatchesRegularExpression('~announcer\.textContent = ~', $shell);
        self::assertStringContainsString("'.admin-toast-error'", $shell, 'errors come first');
        self::assertStringContainsString('ANNOUNCE_MS', $shell);
    }

    /**
     * While the drawer is open on a phone the page behind it is inert (the inert property, never setAttribute); every
     * way of closing it, and crossing 768 px, makes it usable again. The burger stays usable as a toggle beside the
     * drawer's own close button (the backdrop is a plain element).
     */
    public function testTheOpenDrawerMakesThePageInert(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');
        $layout = (string) file_get_contents(__DIR__ . '/../../../resources/admin/templates/layouts/admin.php');

        self::assertStringContainsString("document.querySelectorAll('.admin-skip, .admin-breadcrumbs, .admin-topbar-menus, .admin-content')", $shell);
        self::assertStringNotContainsString("querySelectorAll('.admin-main", $shell, 'never the whole column: the burger sits in its top bar');
        self::assertMatchesRegularExpression('~<header class="admin-topbar">\s*<button type="button" class="admin-burger[^"]*" data-drawer-open[^>]*>.*?</button>\s*<nav class="admin-breadcrumbs"~s', $layout, 'the top bar holds the burger, the breadcrumbs and the menus only');
        self::assertStringContainsString('.inert = open && phone.matches', $shell, 'set in setDrawer(), which every open and close path calls');
        self::assertStringNotContainsString('setAttribute', $shell);
    }

    /**
     * The sidebar's names become tips only where the label does not show in full: on the rail (not in the phone
     * drawer) or cut short; the toggle and both widths resync them.
     */
    public function testTheSidebarTipsShowOnlyOnTheRailOrForACutLabel(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');

        self::assertStringContainsString("const rail = isRail() && !phone.matches;", $shell);
        self::assertStringContainsString("link.title = rail || isCut(link) ? link.dataset.railTitle : '';", $shell);
        self::assertStringContainsString("narrow.addEventListener('change', syncToggle);", $shell);
        self::assertMatchesRegularExpression("~phone\\.addEventListener\\('change', \\(\\) => \\{[^}]*\\}\\s*syncToggle\\(\\);\\s*\\}\\);~", $shell);
    }

    /**
     * Only a success closes by itself: an error and an offer (the "info" toast with its Create translation form) stay
     * until the user closes them.
     */
    public function testOnlyASuccessToastClosesByItself(): void
    {
        $shell = (string) file_get_contents(self::ASSETS . '/shell.js');

        self::assertSame(1, substr_count($shell, 'window.setTimeout(close'));
        self::assertStringContainsString("toast.classList.contains('admin-toast-success')", $shell);
    }
}
