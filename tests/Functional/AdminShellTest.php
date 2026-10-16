<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Admin\AdminView;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\Security\CspNonce;
use AtarCms\Http\Session\Session;
use AtarCms\Http\Session\SessionMiddleware;
use AtarCms\I18n\RequestLocale;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\Regex;
use Nyholm\Psr7\Factory\Psr17Factory;

final class AdminShellTest extends FunctionalTestCase
{
    public function testGuestIsRedirectedToLogin(): void
    {
        $response = $this->browser->get('/admin');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login?redirect=%2Fadmin', $response->getHeaderLine('Location'));
    }

    public function testGuestOnFixtureRouteIsRedirectedToo(): void
    {
        self::assertSame('/admin/login?redirect=%2Fwhoami', $this->browser->get('/whoami')->getHeaderLine('Location'));
    }

    public function testDashboardRendersShellForSignedInUser(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'admin', 'Ann <b>'));

        $response = $this->browser->get('/admin');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello, Ann &lt;b&gt;!', $body);
        self::assertStringContainsString('Administrator', $body);
        self::assertMatchesRegularExpression(self::navLink('/admin', 'Dashboard', true), $body);
        self::assertStringContainsString('<body class="admin-shell"', $body);
        self::assertStringContainsString('<aside class="admin-sidebar" id="sidebar">', $body);
        self::assertStringContainsString('<span class="admin-avatar admin-role admin-role-admin" aria-hidden="true">A&lt;</span>', $body, 'initials of "Ann <b>"');
        self::assertStringContainsString('<input type="radio" name="theme" value="system" data-theme-choice checked>', $body);
        self::assertMatchesRegularExpression('~<form class="admin-signout" method="post" action="/admin/logout">\s*<input type="hidden" name="_csrf"~', $body);
        self::assertStringContainsString('<li><span aria-current="page">Dashboard</span></li>', $body, 'the dashboard names itself in the top bar');
        self::assertStringContainsString('hx-headers="{&quot;X-CSRF-Token&quot;:&quot;' . $this->csrfToken() . '&quot;}"', $body);
        self::assertStringContainsString('<input type="hidden" name="_csrf" value="' . $this->csrfToken() . '">', $body);
        self::assertMatchesRegularExpression('~/admin-assets/admin\.css\?v=[0-9a-f]{12}~', $body);
        self::assertMatchesRegularExpression('~/admin-assets/vendor/htmx\.min\.js\?v=[0-9a-f]{12}~', $body);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testTheTopBarHoldsTheThemeContentLanguageAndUserMenusAndTheSidebarNoLonger(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'admin', 'Ann <b>'));

        $body = (string) $this->browser->get('/admin')->getBody();
        $sidebar = self::between($body, '<aside class="admin-sidebar"', '</aside>');
        $topbar = self::between($body, '<header class="admin-topbar">', '</header>');

        foreach (['admin-avatar', 'data-theme-choice', 'admin-signout', '<details'] as $moved) {
            self::assertStringNotContainsString($moved, $sidebar, $moved);
        }
        self::assertStringContainsString('data-sidebar-toggle', $sidebar, 'the rail toggle stays in the sidebar');

        preg_match_all('~<details class="admin-menu[^"]*" data-menu>\s*<summary class="admin-menu-trigger" title="([^"<:]+): ([^"]*)" aria-label="\1: \2"~', $topbar, $menus);
        self::assertSame(['Appearance', 'Content language', 'Your account'], $menus[1], 'theme, content language, user, in this order');
        self::assertSame(['System', 'English','Ann &lt;b&gt;'], $menus[2], 'the tooltip and the accessible name say the same');
        self::assertStringNotContainsString('admin-menu-text', $topbar, 'the triggers show icons only');
        self::assertStringNotContainsString('admin-visually-hidden', $topbar, 'one accessible name, not two');
        self::assertStringNotContainsString('admin-menu-heading', $topbar, 'the panels have no headings');
        self::assertStringContainsString('<div class="admin-menu-panel admin-menu-panel-account">', self::between($topbar, 'Your account: ', '</details>'));

        $theme = self::between($topbar, 'admin-theme-menu" data-menu>', '</summary>');
        self::assertStringContainsString('<summary class="admin-menu-trigger" title="Appearance: System" aria-label="Appearance: System" data-theme-trigger data-theme-title="Appearance">', $theme);
        self::assertMatchesRegularExpression('~<span data-theme-icon="light" hidden><svg[^>]*><use href="[^"]+#sun"></use></svg></span>~', $theme);
        self::assertMatchesRegularExpression('~<span data-theme-icon="dark" hidden><svg[^>]*><use href="[^"]+#moon"></use></svg></span>~', $theme);
        self::assertMatchesRegularExpression('~<span data-theme-icon="system"><svg[^>]*><use href="[^"]+#monitor"></use></svg></span>~', $theme, 'the server shows the device setting; shell.js shows the stored one');
        self::assertMatchesRegularExpression('~<span data-theme-icon="system"><svg[^>]*><use href="[^"]+#monitor"></use></svg></span>\s*$~', $theme, 'the icon alone, as the inbox and the avatar');
        self::assertStringNotContainsString('#chevron-down', $topbar, 'no top-bar trigger shows a chevron');
        self::assertStringContainsString('<fieldset class="admin-menu-list admin-theme-switch" aria-label="Appearance">', self::between($topbar, 'admin-theme-menu" data-menu>', '<div class="admin-user-card">'));

        $user = self::between($topbar, 'Your account: ', '</details>');
        self::assertStringContainsString('Ann &lt;b&gt;"><span class="admin-avatar admin-role admin-role-admin" aria-hidden="true">A&lt;</span></summary>', $user);
        self::assertStringContainsString('<p class="admin-user-name">Ann &lt;b&gt;</p>', $user);
        self::assertStringContainsString('<p class="admin-user-meta">ann@example.com</p>', $user);
        self::assertStringContainsString('<p class="admin-user-meta">Administrator</p>', $user);
        self::assertMatchesRegularExpression(
            '~</div>\s*<a class="admin-menu-item admin-menu-item-icon" href="/admin/profile"><svg[^>]*><use href="[^"]+#users"></use></svg>Profile</a>\s*'
            . '<hr class="admin-menu-separator">\s*'
            . '<form class="admin-signout" method="post" action="/admin/logout">\s*<input type="hidden" name="_csrf" value="' . $this->csrfToken() . '">\s*'
            . '<button type="submit" class="admin-menu-item admin-menu-item-icon"><svg[^>]*><use href="[^"]+#log-out"></use></svg>Sign out</button>~',
            $user,
            'Profile and Sign out are menu items, Sign out set off by a line',
        );
        self::assertStringNotContainsString('admin-button', $user);
    }

    /**
     * The theme menu lists its choices as the language menu does: menu items with the icon, the label and the check
     * mark at the end (shown on the checked one by admin.css), each around its radio; it sits before the language.
     */
    public function testTheThemeChoicesAreMenuItemsLikeTheLanguagesAndComeBeforeTheLanguageMenu(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'admin', 'Ann'));

        $topbar = self::between((string) $this->browser->get('/admin')->getBody(), '<div class="admin-topbar-menus">', '</header>');
        $theme = self::between($topbar, '<fieldset class="admin-menu-list admin-theme-switch"', '</fieldset>');
        foreach (['light' => ['sun', 'Light', ''], 'dark' => ['moon', 'Dark', ''], 'system' => ['monitor', 'System', ' checked']] as $choice => [$icon, $label, $checked]) {
            self::assertMatchesRegularExpression(
                '~<label class="admin-menu-item admin-theme-option"><input type="radio" name="theme" value="' . $choice . '" data-theme-choice' . $checked . '><svg[^>]*><use href="[^"]+#' . $icon . '"></use></svg>' . $label . '<svg[^>]*><use href="[^"]+#check"></use></svg></label>~',
                $theme,
                $choice,
            );
        }
        self::assertStringNotContainsString('aria-current', $theme, 'the checked radio is the current choice');

        $themeMenu = strpos($topbar, '<details class="admin-menu admin-theme-menu" data-menu>');
        $languageMenu = strpos($topbar, '<details class="admin-menu admin-content-locale" data-menu>');
        self::assertIsInt($themeMenu);
        self::assertIsInt($languageMenu);
        self::assertLessThan($languageMenu, $themeMenu, 'the theme before the language');
    }

    public function testThePageFooterCarriesTheGuideLinkTheProjectLinksAndTheFishartMark(): void
    {
        $login = (string) $this->browser->get('/admin/login')->getBody();
        $this->actingAs($this->createUser('ann@example.com'));
        $dashboard = (string) $this->browser->get('/admin')->getBody();

        $project = '<a class="admin-footer-link" href="https://atarcms\.com" target="_blank" rel="noopener">AtarCMS</a>\s*<a class="admin-footer-link" href="https://github\.com/yanfishel/atarcms" target="_blank" rel="noopener">GitHub</a>';
        $brand = '<p class="admin-footer-brand">' . date('Y') . ' © <a class="admin-footer-brand-link" href="https://fishart\.co\.il" target="_blank" rel="noopener"><img class="admin-fishart" src="/admin-assets/fishart\.png\?v=[0-9a-f]{12}" alt="fishart" height="16"></a></p>';
        self::assertMatchesRegularExpression('~</main>\s*<footer class="admin-footer">\s*<a class="admin-footer-link" href="/admin/ai-guide">AI guide</a>\s*' . $project . '\s*' . $brand . '\s*</footer>\s*</div>\s*</body>~', $dashboard, 'the footer ends the page: the guide link at the start, the project links after it, the mark at the end');
        self::assertMatchesRegularExpression('~</main>\s*<footer class="admin-footer">\s*' . $project . '\s*' . $brand . '\s*</footer>\s*</body>~', $login, 'guest pages carry the footer too, without the guide link');
        self::assertStringNotContainsString('ai-guide', $login, 'the guide needs a signed-in user');
        self::assertStringNotContainsString('fishart', self::between($dashboard, '<aside class="admin-sidebar"', '</aside>'), 'the mark left the sidebar');
        self::assertStringNotContainsString('admin-sidebar-footer', $dashboard);
        self::assertMatchesRegularExpression('~</nav>\s*<button type="button" class="admin-sidebar-toggle" data-sidebar-toggle [^>]*>.*?</button>\s*</aside>~s', $dashboard, 'the rail toggle is a child of the sidebar itself, outside the scrolling nav');
    }

    public function testLoginPageForGuestsAndRedirectForSignedInUsers(): void
    {
        $page = $this->browser->get('/admin/login');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('<h1>Sign in</h1>', (string) $page->getBody());
        self::assertStringContainsString('<body class="admin-guest">', (string) $page->getBody());
        self::assertStringNotContainsString('admin-nav', (string) $page->getBody());
        self::assertStringNotContainsString('admin-sidebar', (string) $page->getBody());
        self::assertMatchesRegularExpression('~<main class="admin-guest-main">\s*<section class="admin-login">\s*<div class="admin-login-hero">\s*<img class="admin-login-hero-mark" src="[^"]+" alt="" width="96" height="96">\s*<p class="admin-login-hero-name">Fixture</p>\s*</div>\s*<div class="admin-login-card">\s*<p class="admin-login-site"><img class="admin-brand-mark" src="[^"]+" alt="" width="36" height="36">[^<]+</p>\s*<h1>Sign in</h1>\s*<p class="admin-muted">Sign in to manage the site\.</p>~', (string) $page->getBody(), 'the hero panel (shown beside the card from 768px) leads, then the card with its own brand row (the phone shows only the card)');
        self::assertFalse($page->hasHeader('Set-Cookie'), 'the login page must not start a session');

        $this->actingAs($this->createUser('ann@example.com'));
        self::assertSame('/admin', $this->browser->get('/admin/login')->getHeaderLine('Location'));
    }

    public function testLoginPageShowsOnlyKnownErrors(): void
    {
        self::assertStringContainsString(
            'Your account is disabled.',
            (string) $this->browser->get('/admin/login?error=disabled')->getBody(),
        );
        self::assertStringNotContainsString(
            'admin-alert',
            (string) $this->browser->get('/admin/login?error=%3Cscript%3E')->getBody(),
        );
    }

    public function testLogoutRequiresCsrfTokenAndEndsTheSession(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $rejected = $this->browser->post('/admin/logout');
        self::assertSame(419, $rejected->getStatusCode());
        self::assertStringContainsString('class="admin-error"', (string) $rejected->getBody());
        self::assertStringContainsString('The page was open too long. Go back, reload it and try again.', (string) $rejected->getBody());

        $response = $this->browser->post('/admin/logout', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
        self::assertNull($this->browser->cookie(SessionMiddleware::SECURE_COOKIE));
        self::assertSame([], $this->sessions->sessions);
        self::assertSame(302, $this->browser->get('/admin')->getStatusCode());
        self::assertSame(['auth.logout'], $this->auditActions());
    }

    public function testDisabledUserCanStillLogOut(): void
    {
        $user = $this->createUser('ann@example.com');
        $this->actingAs($user);
        $token = $this->csrfToken();
        $this->app->container()->service(UserRepository::class)->setStatus($user->id, UserStatus::Disabled);

        $response = $this->browser->post('/admin/logout', ['_csrf' => $token]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testDisabledUserSessionIsRejected(): void
    {
        $user = $this->createUser('ann@example.com');
        $this->actingAs($user);
        self::assertSame(200, $this->browser->get('/admin')->getStatusCode());

        $this->app->container()->service(UserRepository::class)->setStatus($user->id, UserStatus::Disabled);

        self::assertSame(302, $this->browser->get('/admin')->getStatusCode());
        self::assertSame([], $this->sessions->sessions);
    }

    public function testAdminPathsUseTheAdminErrorPage(): void
    {
        $admin = $this->browser->get('/admin/nope');
        $theme = $this->browser->get('/nope');

        self::assertSame(404, $admin->getStatusCode());
        self::assertStringContainsString('class="admin-error"', (string) $admin->getBody());
        self::assertStringContainsString('404 Not Found', (string) $admin->getBody());
        self::assertStringContainsString('<p class="admin-error-code" aria-hidden="true">404</p>', (string) $admin->getBody());
        self::assertStringContainsString('<p>There is no such page. It may have been moved or deleted.</p>', (string) $admin->getBody());
        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-primary" href="/admin"><svg[^>]*>.*?</svg>Go to the dashboard</a>~', (string) $admin->getBody());
        self::assertStringContainsString('<body class="admin-guest">', (string) $admin->getBody());
        self::assertSame(404, $theme->getStatusCode());
        self::assertStringContainsString('class="error-404"', (string) $theme->getBody());
    }

    public function testTheSidebarGroupsTheMenuUnderHeadings(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin')->getBody();

        preg_match_all('~<p class="admin-nav-heading" id="(nav-group-\d+)">([^<]+)</p>\s*<ul class="admin-nav-list" aria-labelledby="([^"]+)">~', $body, $headings);
        self::assertSame(['Content', 'Structure', 'Files', 'Settings'], $headings[2], 'no Modules heading without module items');
        self::assertSame($headings[1], $headings[3], 'each list is named by its group heading');
        self::assertSame(count($headings[1]), count(array_unique($headings[1])));
        self::assertStringContainsString('<h1', $body);
        self::assertStringNotContainsString('<h2', substr($body, 0, (int) strpos($body, '<h1')), 'the page h1 is the first heading: the sidebar groups are not headings');
        self::assertMatchesRegularExpression('~<use href="/admin-assets/icons\.svg\?v=[0-9a-f]{12}#newspaper"></use></svg><span class="admin-nav-label">Articles</span>~', $body);
        self::assertMatchesRegularExpression(self::navLink('/admin/media', 'Photos and files'), $body);
    }

    public function testAPlainSingleIsInTheMenuOnlyForWhoMayPublishIt(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'author'));
        $author = (string) $this->browser->get('/admin')->getBody();
        self::assertStringNotContainsString('href="/admin/content/welcome"', $author, 'always published: an author cannot edit it');
        self::assertStringContainsString('href="/admin/content/contact"', $author, 'a single that is not plain keeps the view permission');

        $this->actingAs($this->createUser('bob@example.com', 'editor'));
        self::assertStringContainsString('href="/admin/content/welcome"', (string) $this->browser->get('/admin')->getBody());
    }

    public function testTheSidebarLinksNameThemselvesAndGiveTheirTipOnlyToTheRail(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertStringNotContainsString('<a class="admin-nav-link" href="/admin/media" title=', $body, 'the open sidebar shows the label: no tip');
        self::assertStringContainsString('<a class="admin-nav-link" href="/admin/media" data-rail-title="Photos and files">', $body);
        self::assertMatchesRegularExpression('~<a class="admin-brand" href="/admin" data-rail-title="[^"]+">~', $body);
    }

    /**
     * The CSP allows scripts with the page's nonce only. The theme bootstrap is the one inline script: without the
     * nonce the browser would drop it silently and every page would load unthemed.
     */
    public function testTheThemeBootstrapIsTheOnlyInlineScriptAndRunsBeforeTheStylesheet(): void
    {
        $guest = $this->browser->get('/admin/login');
        $this->actingAs($this->createUser('ann@example.com'));
        $user = $this->browser->get('/admin');

        foreach (['guest' => $guest, 'user' => $user] as $who => $response) {
            $body = (string) $response->getBody();
            $csp = Regex::match("~script-src 'self' 'nonce-([A-Za-z0-9+/=_-]+)'~", $response->getHeaderLine('Content-Security-Policy'), $who);
            preg_match_all('~<script\b([^>]*)>~', $body, $tags, PREG_OFFSET_CAPTURE);
            $inline = array_values(array_filter($tags[1], static fn(array $tag): bool => !str_contains($tag[0], ' src=')));

            self::assertCount(1, $inline, $who);
            self::assertSame(' nonce="' . $csp[1] . '"', $inline[0][0], $who);
            self::assertStringContainsString('localStorage.getItem("admin.theme")', substr($body, $inline[0][1], 400), $who);
            self::assertLessThan(strpos($body, '<link rel="stylesheet"'), $inline[0][1], $who . ': the theme is set before the stylesheet paints');
            foreach ($tags[1] as $tag) {
                if (!str_contains($tag[0], 'htmx.min.js')) {
                    self::assertStringContainsString(' nonce="' . $csp[1] . '"', $tag[0], $who);
                }
            }
            self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/tooltip\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($csp[1], '~') . '"></script>~', $body, $who . ': titles show as tooltips on every page');
            self::assertStringNotContainsString('<style', $body, $who);
            self::assertStringNotContainsString(' style=', $body, $who);
        }
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/shell\.js\?v=[0-9a-f]{12}" nonce="~', (string) $user->getBody());
    }

    public function testFlashesAreVisibleWithoutJavaScript(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'bob@example.com', 'name' => 'Bob', 'role' => 'editor']);
        $body = (string) $this->browser->get('/admin/users')->getBody();

        self::assertMatchesRegularExpression('~<div class="admin-toasts" data-toasts aria-live="polite">\s*<div class="admin-toast admin-toast-success" data-toast>~', $body, 'the stack is the live region, so toasts added later are announced too');
        self::assertStringContainsString('<button type="button" class="admin-toast-close" data-toast-close><svg class="admin-icon" role="img" aria-label="Close">', $body);
    }

    public function testThePageAlwaysHasAnEmptyToastStackForScriptsToFill(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertMatchesRegularExpression('~<main class="admin-content" id="main-content" tabindex="-1">\s*<div class="admin-toasts" data-toasts aria-live="polite"></div>~', $body, 'empty, without whitespace, so :empty hides it');
    }

    /**
     * Live regions announce changes only: shell.js repeats the texts of the toasts present at load in an empty status
     * region that exists from the start.
     */
    public function testTheLayoutRendersAnEmptyToastAnnouncer(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertSame(1, substr_count($body, '<div class="admin-visually-hidden" role="status" data-toast-announcer></div>'));
    }

    public function testTheDrawerHasACloseButtonAfterTheBrand(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertMatchesRegularExpression('~<aside class="admin-sidebar" id="sidebar">\s*<a class="admin-brand" [^>]*>.*?</a>\s*<button type="button" class="admin-button admin-button-link admin-button-icon admin-drawer-close" title="Close the menu" data-drawer-dismiss><svg class="admin-icon" role="img" aria-label="Close the menu"[^>]*><use href="[^"]+#x"></use></svg></button>\s*<nav class="admin-nav"~s', $body, 'right after the brand: the focus on opening and the Tab cycle stay');
    }

    public function testTheTopBarShowsTheBreadcrumbsAndAnItemWithoutAUrlIsPlainText(): void
    {
        $user = $this->createUser('ann@example.com');
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://localhost/admin/users')
            ->withAttribute(User::class, $user)
            ->withAttribute(RequestLocale::class, new RequestLocale('en', false))
            ->withAttribute(CspNonce::class, CspNonce::generate())
            ->withAttribute(Session::class, new Session('test'));
        $view = $this->app->container()->service(AdminView::class);

        $plain = $view->renderer()->render('layouts/admin', ['content' => '<p>x</p>'], $request);
        $trail = $view->renderer()->render('layouts/admin', [
            'content' => '<p>x</p>',
            'breadcrumbs' => [['Users', '/admin/users'], ['Groups'], ['Ann']],
        ], $request);

        self::assertMatchesRegularExpression('~<header class="admin-topbar">\s*<button[^>]*data-drawer-open[^>]*>.*?</button>\s*<nav class="admin-breadcrumbs" aria-label="You are here">\s*<ol>\s*<li><span aria-current="page">Dashboard</span></li>\s*</ol>~s', $plain, 'without a trail the page names itself, so the bar is never empty');
        $titled = $view->renderer()->render('layouts/admin', ['content' => '<p>x</p>', 'title' => '404 Not Found'], $request);
        self::assertMatchesRegularExpression('~<ol>\s*<li><span aria-current="page">404 Not Found</span></li>\s*</ol>~', $titled);
        self::assertStringContainsString('<li><a href="/admin">Dashboard</a></li>', $trail);
        self::assertStringContainsString('<li><a href="/admin/users">Users</a></li>', $trail);
        self::assertStringContainsString('<li><span>Groups</span></li>', $trail);
        self::assertStringContainsString('<li><span aria-current="page">Ann</span></li>', $trail);
        self::assertStringContainsString('<main class="admin-content" id="main-content" tabindex="-1">', $trail);
    }

    private static function between(string $body, string $start, string $end): string
    {
        $from = strpos($body, $start);
        self::assertNotFalse($from, $start);
        $to = strpos($body, $end, $from + strlen($start));
        self::assertNotFalse($to, $end);

        return substr($body, $from + strlen($start), $to - $from - strlen($start));
    }

    public function testTheIconHelperUsesTheVersionedSprite(): void
    {
        $renderer = $this->app->container()->service(AdminView::class)->renderer();

        self::assertMatchesRegularExpression(
            '~^<svg class="admin-icon" aria-hidden="true"><use href="/admin-assets/icons\.svg\?v=[0-9a-f]{12}#newspaper"></use></svg>$~',
            Cast::string($renderer->callHelper('icon', ['newspaper'])),
        );
    }
}
