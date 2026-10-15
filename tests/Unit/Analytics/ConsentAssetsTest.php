<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Analytics;

use PHPUnit\Framework\TestCase;

/**
 * resources/admin/assets/consent.js, the cookie banner's core script (Plans 40 and 41): it follows the admin script
 * rules and the banner contract of the theme guide.
 */
final class ConsentAssetsTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../resources/admin/assets/consent.js';

    public function testItNeverInjectsMarkupStylesOrNamesAHost(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);

        foreach (['.innerHTML', '.outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', '//mc.', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage.setItem', 'facebook', 'tiktok.com', 'mail.ru', 'googletagmanager'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $script, $forbidden);
        }
    }

    /**
     * The loader's data attributes (Consent::head()) and the banner's (any theme's): the choice per category in the
     * atar_consent cookie, the banner shown while an offered category is undecided, each category only after it is
     * granted.
     */
    public function testItKeepsTheBannerContract(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);

        foreach ([
            "document.querySelector('script[data-atar-consent]')",
            "const COOKIE = 'atar_consent';",
            'const MAX_AGE = 15552000;',
            // SameSite=None (Secure) on https: Metrica's click map and Webvisor's player show the site in a frame on
            // metrika.yandex.ru, where a Lax cookie is not sent; on http None is refused, so Lax.
            "'; Path=/; SameSite=' + (location.protocol === 'https:' ? 'None; Secure' : 'Lax')",
            "document.querySelector('[data-consent]')",
            "banner?.querySelector('[data-consent-panel]')",
            "banner?.querySelector('[data-consent-choose]')",
            "'[data-consent-accept]'",
            "'[data-consent-decline]'",
            "'[data-consent-choose]'",
            "'[data-consent-save]'",
            "'[data-consent-category]'",
            "'[data-consent-open]'",
            'banner.hidden = false;',
            'banner.hidden = true;',
            'panel.hidden = !open;',
            "chooser.ariaExpanded = open ? 'true' : 'false';",
            // The switches show the stored choice; nothing is pre-ticked while undecided.
            'input.checked = choice[input.dataset.consentCategory] === true;',
            'opener.focus();',
            'banner.focus();',
            "...(data.metrica || data.ga ? ['statistics'] : []),",
            "...(data.meta || data.googleAds || data.vk || data.tiktok ? ['advertising'] : []),",
            "const LETTERS = {statistics: 's', advertising: 'a'};",
            // Plan 40's single choice was statistics only: advertising is asked anew.
            "if (value === '1' || value === '0') {",
            "return {statistics: value === '1'};",
            '/^(?:s([01])(?:\.a([01]))?|a([01]))$/',
            'const current = {...stored(), ...choice};',
            "if (banner !== null && offered.some((category) => state[category] === undefined)) {",
        ] as $code) {
            self::assertStringContainsString($code, $script, $code);
        }
    }

    /**
     * The services' scripts carry the loader's nonce (the property: browsers hide the attribute) and are initialised
     * here, never by inline code: Metrica, Google's tag (once, for GA4 and Google Ads, with Consent Mode v2), Meta,
     * VK and TikTok, each from its official snippet's queue.
     */
    public function testTheServicesAreCreatedWithTheNonceAndInitialisedHere(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);

        foreach ([
            "document.createElement('script')",
            'script.nonce = loader.nonce;',
            'script.async = true;',
            'script.src = src;',
            "window.ym(Number(metrica), 'init', {clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: webvisor !== undefined});",
            'window.dataLayer = window.dataLayer || [];',
            "window.gtag('consent', 'default', signals(choice));",
            // Every choice on the page brings Google's tag up to date in one call: analytics_storage and the ad signals
            // together, whichever Google id the site has (GA4 alone keeps ad signals for its advertising features).
            "if (tagged) {\n        window.gtag('consent', 'update', signals(choice));\n    }\n    for (const category of offered) {",
            "return {analytics_storage: choice.statistics === true ? 'granted' : 'denied', ad_storage: ads, ad_user_data: ads, ad_personalization: ads};",
            "window.gtag('js', new Date());",
            'add(data.gtagSrc + id);',
            "window.gtag('config', ga);",
            "window.gtag('config', googleAds);",
            // Allowed again after a withdrawal on the same page: what is loaded resumes.
            "window['ga-disable-' + ga] = false;",
            "window.fbq?.('consent', 'grant');",
            'window.ttq?.grantConsent?.();',
            "window.fbq('init', meta);",
            "window.fbq('track', 'PageView');",
            'add(metaSrc);',
            "tmr.push({id: vk, type: 'pageView', start: Date.now()});",
            "add(vkSrc, 'tmr-code');",
            "window.TiktokAnalyticsObject = 'ttq';",
            "'holdConsent', 'revokeConsent', 'grantConsent'",
            "add(tiktokSrc + '?sdkid=' + id + '&lib=ttq');",
            'ttq.load(tiktok);',
            'ttq.page();',
        ] as $code) {
            self::assertStringContainsString($code, $script, $code);
        }
    }

    /**
     * Withdrawn consent, per category: statistics stops Google Analytics; advertising revokes Meta's and TikTok's
     * consent and Google's ad signals; each category's first-party cookies (on the host and its parent domains) and
     * storage keys go, and only its own.
     */
    public function testWithdrawalStopsAndCleansEachCategory(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);

        foreach ([
            "window['ga-disable-' + ga] = true;",
            "window.fbq?.('consent', 'revoke');",
            'window.ttq?.revokeConsent?.();',
            'statistics: /^(?:_ym|_ga|_gid|_gat|mdd$)/,',
            'advertising: /^(?:_fbp$|_fbc$|_gcl_|_ttp$|_tt_enable_cookie$|ttcsid|tmr_|domain_sid$)/,',
            "const STORAGE = {statistics: '_ym', advertising: 'tmr_'};",
            '.filter((name) => COOKIES[category].test(name));',
            "'=; Max-Age=0; Path=/'",
            "'; Domain='",
            'key.startsWith(STORAGE[category])',
            'localStorage.removeItem(key);',
        ] as $code) {
            self::assertStringContainsString($code, $script, $code);
        }
        self::assertSame(1, substr_count($script, "'consent', 'update'"), 'one update, for every category at once');
        self::assertStringNotContainsString('if (googleAds) {', $script);
        self::assertStringNotContainsString('if (data.googleAds) {', $script);
    }
}
