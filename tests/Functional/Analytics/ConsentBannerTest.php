<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Analytics;

use AtarCms\Analytics\Consent;
use AtarCms\Analytics\ConsentChoice;
use AtarCms\Content\Preset\PresetPages;
use AtarCms\I18n\RequestLocale;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Functional\Skeleton\SkeletonTestCase;
use AtarCms\Tests\Support\Regex;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * The analytics counters and advertising pixels on the skeleton site (Plans 40 and 41): the core loader (consentHead())
 * and the theme's cookie banner (consent(), partials/consent.php), only with a service and only for visitors.
 */
final class ConsentBannerTest extends SkeletonTestCase
{
    public function testWithoutACounterNothingChanges(): void
    {
        $response = $this->browser->get('/search');
        $body = (string) $response->getBody();
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertStringNotContainsString('data-consent', $body);
        self::assertStringNotContainsString('consent.js', $body);
        self::assertStringNotContainsString('yandex', $csp);
        self::assertStringNotContainsString('google-analytics', $csp);
        self::assertStringContainsString("; connect-src 'self'; ", $csp);
        self::assertStringEndsWith("frame-ancestors 'self'", $csp);
    }

    public function testVisitorsGetTheLoaderTheHostsAndTheBanner(): void
    {
        $this->counters(webvisor: false);
        $this->app->container()->service(PresetPages::class)->sync();

        $response = $this->browser->get('/search');
        $body = (string) $response->getBody();
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/consent\.js\?v=[0-9a-f]+" nonce="[^"]+" data-atar-consent data-metrica="12345678" data-metrica-src="https://mc\.yandex\.ru/metrika/tag\.js" data-ga="G-TEST123456" data-gtag-src="https://www\.googletagmanager\.com/gtag/js\?id="></script>\s*</head>~', $body, 'the last thing in <head>');
        self::assertStringNotContainsString('data-webvisor', $body);
        self::assertStringNotContainsString('data-meta', $body);
        self::assertMatchesRegularExpression("~; script-src 'self' 'nonce-[^']+' https://mc\\.yandex\\.ru https://mc\\.yandex\\.com https://yastatic\\.net https://www\\.googletagmanager\\.com; ~", $csp);
        self::assertStringContainsString('https://*.google-analytics.com', $csp);
        self::assertStringContainsString("frame-ancestors 'self' https://metrika.yandex.ru", $csp);
        self::assertStringNotContainsString('webvisor', $csp);

        $banner = Regex::match('~<section class="consent" id="consent" aria-labelledby="consent-title" tabindex="-1" hidden data-consent>.*?</section>~s', $body);
        self::assertStringContainsString('<h2 id="consent-title">Cookies and statistics</h2>', $banner[0]);
        self::assertStringContainsString('We would like to use cookies to count visits and see how the site is used (Yandex Metrica and Google Analytics). They are set only if you accept.', $banner[0]);
        self::assertStringNotContainsString('session replay', $banner[0]);
        self::assertStringContainsString('<a href="/privacy">Privacy policy</a>', $banner[0]);
        self::assertMatchesRegularExpression('~<div class="consent-actions">\s*<button type="button" class="consent-button" data-consent-accept>Accept</button>\s*<button type="button" class="consent-button" data-consent-decline>Decline</button>\s*</div>\s*</section>~', $banner[0], 'of equal weight, and nothing else: statistics only is Plan 40\'s banner');
        self::assertStringNotContainsString('data-consent-panel', $banner[0]);
        self::assertStringNotContainsString('data-consent-choose', $banner[0]);
        self::assertMatchesRegularExpression('~<ul class="footer-legal">.*<li class="footer-consent"><button type="button" class="footer-consent-button" aria-controls="consent" data-consent-open>Cookie settings</button></li>\s*</ul>~s', $body);
        self::assertLessThan(strpos($body, '<header class="site-header">'), strpos($body, 'data-consent>'), 'early in the reading order');
    }

    public function testWebvisorIsNamedAndAddsItsHosts(): void
    {
        $this->counters(webvisor: true, ga: false);

        $response = $this->browser->get('/search');
        $body = (string) $response->getBody();
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString('data-metrica="12345678" data-metrica-src="https://mc.yandex.ru/metrika/tag.js" data-webvisor></script>', $body);
        self::assertStringContainsString('wss://mc.webvisor.org', $csp);
        self::assertStringContainsString('https://mc.webvisor.com', $csp);
        self::assertStringNotContainsString('googletagmanager', $csp);
        self::assertStringContainsString('(Yandex Metrica), including session replay, which records scrolling, clicks and what is typed into forms on its pages.', $body);
        self::assertStringNotContainsString('href="/privacy"', $body, 'no privacy page yet: no link');
        self::assertMatchesRegularExpression('~<ul class="footer-legal footer-legal-js">\s*<li class="footer-consent">~', $body, 'holding only the JavaScript-only button, the list is JavaScript-only too');
    }

    public function testTheOwnersTextWinsInItsLanguage(): void
    {
        $this->counters();
        $this->app->container()->service(SettingsRepository::class)->put(SiteSettings::CONSENT_TEXT, 'he', 'אנחנו משתמשים בעוגיות <סטטיסטיקה>.', new DateTimeImmutable());

        $he = (string) $this->browser->get('/he/search')->getBody();
        $en = (string) $this->browser->get('/search')->getBody();

        self::assertStringContainsString('<html lang="he" dir="rtl">', $he);
        self::assertStringContainsString('<p class="consent-text">אנחנו משתמשים בעוגיות &lt;סטטיסטיקה&gt;.</p>', $he);
        self::assertMatchesRegularExpression('~data-consent-accept>אשר</button>\s*<button type="button" class="consent-button" data-consent-decline>דחה</button>~', $he);
        self::assertStringContainsString('data-consent-open>הגדרות עוגיות</button>', $he);
        self::assertStringContainsString('We would like to use cookies', $en, 'the other languages keep the theme\'s text');
    }

    public function testSignedInUsersAreNotCounted(): void
    {
        $this->counters();
        $this->actingAs($this->createUser('editor@example.com', role: 'editor'));

        $response = $this->browser->get('/search');
        $body = (string) $response->getBody();
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertStringNotContainsString('consent.js', $body);
        self::assertStringNotContainsString('data-consent', $body);
        // The policy is the one of a site without counters: Metrica's framed pages (its maps, Webvisor's player) are
        // anonymous anyway, the session cookie being SameSite=Lax.
        $this->db->execute("DELETE FROM settings WHERE name LIKE 'analytics.%'");
        $plain = $this->browser->get('/search')->getHeaderLine('Content-Security-Policy');
        $strip = static fn(string $policy): string => (string) preg_replace("~'nonce-[^']+'~", '', $policy);
        self::assertSame($strip($plain), $strip($csp));
    }

    public function testTheAdminIsUntouched(): void
    {
        $this->counters();
        $this->actingAs($this->createUser('admin@example.com'));

        $response = $this->browser->get('/admin');
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertStringNotContainsString('consent.js', (string) $response->getBody());
        self::assertStringNotContainsString('yandex', $csp);
        self::assertStringNotContainsString('google-analytics', $csp);
    }

    public function testTheCookieIsReadForThemesThatWantIt(): void
    {
        $this->counters();
        $consent = $this->app->container()->service(Consent::class);
        $request = (new Psr17Factory())->createServerRequest('GET', '/')->withAttribute(RequestLocale::class, new RequestLocale('en', false));

        self::assertNull($consent->view($request)?->state);
        self::assertEquals(new ConsentChoice(true, null), $consent->view($request->withCookieParams(['atar_consent' => '1']))?->state, 'Plan 40\'s Accept: statistics only');
        self::assertEquals(new ConsentChoice(false, null), $consent->view($request->withCookieParams(['atar_consent' => '0']))?->state);
        self::assertEquals(new ConsentChoice(true, false), $consent->view($request->withCookieParams(['atar_consent' => 's1.a0']))?->state);
        self::assertNull($consent->view($request->withCookieParams(['atar_consent' => 'yes']))?->state);
        self::assertSame(['metrica', 'google_analytics'], $consent->view($request)?->services);
        self::assertSame([], $consent->view($request)->advertising);
    }

    public function testThePixelsGetTheirAttributesAndHosts(): void
    {
        $this->counters(ga: false);
        $this->pixels();

        $response = $this->browser->get('/search');
        $body = (string) $response->getBody();
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertMatchesRegularExpression('~ data-atar-consent data-metrica="12345678" data-metrica-src="https://mc\.yandex\.ru/metrika/tag\.js" data-google-ads="AW-123456789" data-gtag-src="https://www\.googletagmanager\.com/gtag/js\?id=" data-meta="123456789012345" data-meta-src="https://connect\.facebook\.net/en_US/fbevents\.js" data-vk="3456789" data-vk-src="https://top-fwz1\.mail\.ru/js/code\.js" data-tiktok="C4ABCDEFGH1234567890" data-tiktok-src="https://analytics\.tiktok\.com/i18n/pixel/events\.js"></script>~', $body);
        self::assertStringNotContainsString('data-ga=', $body);
        self::assertStringNotContainsString('<noscript', $body, 'no noscript pixels: they would fire without consent');
        foreach (['https://connect.facebook.net', 'https://www.googleadservices.com', 'https://top-fwz1.mail.ru', 'https://privacy-cs.mail.ru', 'https://analytics.tiktok.com'] as $host) {
            self::assertMatchesRegularExpression('~; script-src [^;]*' . preg_quote($host, '~') . '[ ;]~', $csp, $host);
        }
        self::assertMatchesRegularExpression('~; frame-src [^;]*https://td\.doubleclick\.net[ ;]~', $csp);
        self::assertMatchesRegularExpression('~; img-src [^;]*https://www\.facebook\.com[ ;]~', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);

        // One pixel alone brings only its own hosts.
        $this->db->execute("DELETE FROM settings WHERE name LIKE 'analytics.%' AND name <> 'analytics.meta_pixel'");
        $meta = $this->browser->get('/search')->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString('https://connect.facebook.net', $meta);
        foreach (['yandex', 'tiktok', 'mail.ru', 'googleadservices', 'doubleclick'] as $other) {
            self::assertStringNotContainsString($other, $meta, $other);
        }
    }

    public function testWithAdvertisingPixelsTheBannerOffersAChoicePerCategory(): void
    {
        $this->counters();
        $this->pixels();

        $body = (string) $this->browser->get('/search')->getBody();

        $banner = Regex::match('~<section class="consent" id="consent" aria-labelledby="consent-title" tabindex="-1" hidden data-consent>.*?</section>~s', $body)[0];
        self::assertStringContainsString('<h2 id="consent-title">Cookies, statistics and advertising</h2>', $banner);
        self::assertStringContainsString('<p class="consent-text">We would like to use cookies to count visits and see how the site is used (Yandex Metrica, Google Analytics) and for advertising: to measure our ads and show them to you on other sites (Meta Pixel, Google Ads, VK Ads, TikTok Pixel). They are set only if you allow them: all at once with “Accept all”, or each kind with “Choose”.', $banner);
        self::assertMatchesRegularExpression('~<button type="button" class="consent-button" data-consent-accept>Accept all</button>\s*<button type="button" class="consent-button" data-consent-decline>Decline</button>\s*<button type="button" class="consent-button" aria-expanded="false" aria-controls="consent-choice" data-consent-choose>Choose</button>~', $banner, 'three buttons of equal weight');
        self::assertMatchesRegularExpression('~<div class="consent-choice" id="consent-choice" hidden data-consent-panel>\s*<fieldset class="consent-categories">\s*<legend>Choose what to allow</legend>~', $banner);
        self::assertMatchesRegularExpression('~<input type="checkbox" role="switch" id="consent-statistics" aria-describedby="consent-statistics-text" data-consent-category="statistics">\s*<label for="consent-statistics">Statistics</label>\s*<p class="consent-category-text" id="consent-statistics-text">Counting visits and seeing how the site is used: Yandex Metrica, Google Analytics\.</p>~', $banner);
        self::assertMatchesRegularExpression('~<input type="checkbox" role="switch" id="consent-advertising" aria-describedby="consent-advertising-text" data-consent-category="advertising">\s*<label for="consent-advertising">Advertising</label>\s*<p class="consent-category-text" id="consent-advertising-text">Measuring our ads and showing them to you on other sites: Meta Pixel, Google Ads, VK Ads, TikTok Pixel\.</p>~', $banner);
        self::assertStringNotContainsString(' checked', $banner, 'nothing is pre-ticked');
        self::assertMatchesRegularExpression('~</fieldset>\s*<button type="button" class="consent-button consent-save" data-consent-save>Save choice</button>~', $banner);
        self::assertStringNotContainsString('role="dialog"', $banner, 'still a region');
    }

    public function testWithAdvertisingOnlyThePanelHasOnlyItsCategory(): void
    {
        $this->pixels(meta: false, ads: false, vk: false);

        $body = (string) $this->browser->get('/search')->getBody();

        self::assertStringContainsString('<h2 id="consent-title">Cookies and advertising</h2>', $body);
        self::assertStringContainsString('We would like to use cookies for advertising: to measure our ads and show them to you on other sites (TikTok Pixel). They are set only if you allow them.', $body);
        self::assertStringContainsString('data-consent-category="advertising"', $body);
        self::assertStringNotContainsString('data-consent-category="statistics"', $body);
        self::assertStringContainsString('data-consent-choose>Choose</button>', $body);
        self::assertStringNotContainsString('data-gtag-src', $body);
    }

    public function testWebvisorIsNamedBesideThePixels(): void
    {
        $this->counters(webvisor: true, ga: false);
        $this->pixels(ads: false, vk: false, tiktok: false);

        $body = (string) $this->browser->get('/search')->getBody();

        self::assertStringContainsString('(Yandex Metrica), including session replay, which records scrolling, clicks and what is typed into forms on its pages, and for advertising: to measure our ads and show them to you on other sites (Meta Pixel).', $body);
        self::assertStringContainsString('Counting visits and seeing how the site is used, including session replay (scrolling, clicks and what is typed into forms): Yandex Metrica.', $body);
    }

    public function testTheHebrewPanelReadsRightToLeft(): void
    {
        $this->counters();
        $this->pixels();

        $he = (string) $this->browser->get('/he/search')->getBody();

        self::assertStringContainsString('<html lang="he" dir="rtl">', $he);
        self::assertStringContainsString('<h2 id="consent-title">עוגיות, סטטיסטיקה ופרסום</h2>', $he);
        self::assertMatchesRegularExpression('~data-consent-accept>אשר הכול</button>\s*<button type="button" class="consent-button" data-consent-decline>דחה</button>\s*<button [^>]*data-consent-choose>בחר</button>~', $he);
        self::assertStringContainsString('<label for="consent-advertising">פרסום</label>', $he);
        self::assertStringContainsString('Meta Pixel, Google Ads, VK Ads, TikTok Pixel', $he, 'Latin names in a comma list');
        self::assertStringContainsString('data-consent-save>שמור בחירה</button>', $he);
    }

    public function testSignedInUsersGetNoPixels(): void
    {
        $this->pixels();
        $this->actingAs($this->createUser('editor@example.com', role: 'editor'));

        $response = $this->browser->get('/search');
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertStringNotContainsString('consent.js', (string) $response->getBody());
        foreach (['facebook', 'tiktok', 'mail.ru', 'googleadservices', 'doubleclick'] as $host) {
            self::assertStringNotContainsString($host, $csp, $host);
        }
    }

    private function counters(bool $webvisor = false, bool $ga = true): void
    {
        $settings = $this->app->container()->service(SettingsRepository::class);
        $now = new DateTimeImmutable();
        $settings->put(SiteSettings::YANDEX_METRICA, '', '12345678', $now);
        $settings->put(SiteSettings::YANDEX_WEBVISOR, '', $webvisor ? '1' : null, $now);
        $settings->put(SiteSettings::GOOGLE_ANALYTICS, '', $ga ? 'G-TEST123456' : null, $now);
    }

    private function pixels(bool $meta = true, bool $ads = true, bool $vk = true, bool $tiktok = true): void
    {
        $settings = $this->app->container()->service(SettingsRepository::class);
        $now = new DateTimeImmutable();
        $settings->put(SiteSettings::META_PIXEL, '', $meta ? '123456789012345' : null, $now);
        $settings->put(SiteSettings::GOOGLE_ADS, '', $ads ? 'AW-123456789' : null, $now);
        $settings->put(SiteSettings::VK_PIXEL, '', $vk ? '3456789' : null, $now);
        $settings->put(SiteSettings::TIKTOK_PIXEL, '', $tiktok ? 'C4ABCDEFGH1234567890' : null, $now);
    }
}
