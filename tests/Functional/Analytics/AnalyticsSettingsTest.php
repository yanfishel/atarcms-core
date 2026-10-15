<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Analytics;

use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Regex;

/**
 * The analytics counters and the cookie banner's text on Site settings → Integrations (Plan 40).
 */
final class AnalyticsSettingsTest extends ContentFunctionalTestCase
{
    public function testTheIntegrationsTabHoldsTheCountersAndTheBannerText(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/settings?locale=ru')->getBody();

        $tab = Regex::match('~id="panel-integrations".*?</section>~s', $page)[0];
        self::assertMatchesRegularExpression('~<label for="yandex_metrica"><svg[^>]*><use href="[^"]+#chart-no-axes-column"></use></svg>Yandex Metrica counter number</label>\s*<input id="yandex_metrica" name="yandex_metrica" maxlength="12" value="" dir="ltr">\s*<span class="admin-help">Digits only, for example 12345678\. The counters load only after a visitor accepts cookies in the site’s banner; signed-in users are not counted\.</span>~', $tab);
        self::assertMatchesRegularExpression('~<label class="admin-toggle" for="yandex_webvisor"><input type="checkbox" role="switch" id="yandex_webvisor" name="yandex_webvisor" value="1">Webvisor \(session replay\)</label>~', $tab);
        self::assertMatchesRegularExpression('~<input id="google_analytics" name="google_analytics" maxlength="30" value="" dir="ltr">~', $tab);
        self::assertMatchesRegularExpression('~<label for="consent_text"><svg[^>]*>.*?</svg>Cookie banner text<span class="admin-locale-badge"[^>]*>RU</span><span class="admin-visually-hidden">[^<]*</span></label>\s*<textarea id="consent_text" name="consent_text" maxlength="500" rows="3" lang="ru" dir="ltr"></textarea>\s*<span class="admin-help">Shown in the cookie banner in this language, up to 500 characters\. Empty: the theme’s text, which names the services and session replay\.</span>~', $tab);
        self::assertMatchesRegularExpression('~<label for="meta_pixel"><svg[^>]*>.*?</svg>Meta Pixel ID</label>\s*<input id="meta_pixel" name="meta_pixel" maxlength="30" value="" dir="ltr">\s*<span class="admin-help">Digits only, for example 123456789012345\. Advertising pixels load only after a visitor allows advertising cookies in the site’s banner; signed-in users are not tracked\.</span>~', $tab);
        self::assertMatchesRegularExpression('~<input id="google_ads" name="google_ads" maxlength="30" value="" dir="ltr">\s*<span class="admin-help">For example AW-123456789\. It shares the Google tag with Google Analytics 4\. ~', $tab);
        self::assertMatchesRegularExpression('~<label for="vk_pixel"><svg[^>]*>.*?</svg>VK Ads pixel ID</label>\s*<input id="vk_pixel" name="vk_pixel" maxlength="20" value="" dir="ltr">~', $tab);
        self::assertMatchesRegularExpression('~<label for="tiktok_pixel"><svg[^>]*>.*?</svg>TikTok Pixel ID</label>\s*<input id="tiktok_pixel" name="tiktok_pixel" maxlength="40" value="" dir="ltr">~', $tab);
        // By service (the user's order): Google's, Yandex's, Meta's, VK's, TikTok's, then the banner's text.
        preg_match_all('~ name="([a-z_]+)"~', $tab, $names);
        self::assertSame(['google_verification', 'google_analytics', 'google_ads', 'yandex_verification', 'yandex_metrica', 'yandex_webvisor', 'meta_pixel', 'vk_pixel', 'tiktok_pixel', 'consent_text'], $names[1]);
    }

    public function testTheCountersAreSavedAndAudited(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->browser->get('/admin/settings?locale=ru');

        $response = $this->browser->post('/admin/settings', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'ru',
            'accessibility_toolbar' => '1',
            'yandex_metrica' => '12345678',
            'yandex_webvisor' => '1',
            'google_analytics' => 'g-test123456',
            'consent_text' => 'Мы используем cookie.',
            'meta_pixel' => '123456789012345',
            'google_ads' => 'aw-123456789',
            'vk_pixel' => '3456789',
            'tiktok_pixel' => 'c4abcdefgh1234567890',
        ]);

        self::assertSame(303, $response->getStatusCode());
        $site = $this->app->container()->service(SiteSettings::class);
        $ru = $site->of('ru');
        self::assertSame(['12345678', true, 'G-TEST123456', 'Мы используем cookie.'], [$ru->yandexMetrica, $ru->yandexWebvisor, $ru->googleAnalytics, $ru->consentText]);
        self::assertNull($site->of('en')->consentText);
        self::assertSame(['123456789012345', 'AW-123456789', '3456789', 'C4ABCDEFGH1234567890'], [$ru->metaPixel, $ru->googleAds, $ru->vkPixel, $ru->tiktokPixel]);
        self::assertSame(['settings.updated'], $this->auditActions());
        $page = (string) $this->browser->get('/admin/settings?locale=ru')->getBody();
        self::assertStringContainsString('<input id="yandex_metrica" name="yandex_metrica" maxlength="12" value="12345678" dir="ltr">', $page);
        self::assertStringContainsString('id="yandex_webvisor" name="yandex_webvisor" value="1" checked>', $page);
        self::assertStringContainsString('value="G-TEST123456"', $page);
        self::assertStringContainsString('<input id="google_ads" name="google_ads" maxlength="30" value="AW-123456789" dir="ltr">', $page);
        self::assertStringContainsString('<input id="tiktok_pixel" name="tiktok_pixel" maxlength="40" value="C4ABCDEFGH1234567890" dir="ltr">', $page);
        self::assertStringContainsString('lang="ru" dir="ltr">Мы используем cookie.</textarea>', $page);
    }

    public function testMistakesStayAtTheirFieldsOnTheIntegrationsTab(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->browser->get('/admin/settings');

        $response = $this->browser->post('/admin/settings', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'yandex_metrica' => 'ym-123',
            'google_analytics' => 'UA-1234-1',
            'meta_pixel' => 'fb-123',
            'google_ads' => 'G-TEST123456',
            'vk_pixel' => 'vk',
            'tiktok_pixel' => 'C4-1',
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<p>Please fix 6 fields.</p>', $body);
        self::assertStringContainsString('data-tab="integrations" data-errors="6"', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="meta_pixel-error">Enter the pixel ID: digits only, for example 123456789012345.</span>', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="google_ads-error">Enter the conversion ID in the form AW-123456789.</span>', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="vk_pixel-error">Enter the pixel ID: digits only, for example 3456789.</span>', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="tiktok_pixel-error">Enter the pixel ID: Latin letters and digits, for example C4ABCDEFGH1234567890.</span>', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="yandex_metrica-error">Enter the counter number: digits only, for example 12345678.</span>', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="google_analytics-error">Enter the measurement ID in the form G-XXXXXXXXXX.</span>', $body);
        self::assertNull($this->app->container()->service(SiteSettings::class)->of('en')->yandexMetrica);
    }
}
