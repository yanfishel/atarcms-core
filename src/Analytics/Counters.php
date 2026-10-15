<?php

declare(strict_types=1);

namespace AtarCms\Analytics;

use AtarCms\Http\Security\CspSources;
use AtarCms\Settings\Settings;

/**
 * The site's analytics counters and advertising pixels (Site settings → Integrations) in their two consent categories:
 * "statistics" — a Yandex Metrica counter, optionally with Webvisor (session replay), and a Google Analytics 4
 * measurement id (Plan 40); "advertising" — a Meta Pixel, a Google Ads conversion id, a VK Ads (Top@Mail.ru) pixel and a
 * TikTok Pixel (Plan 41). Each category loads only after a visitor allows it (resources/admin/assets/consent.js,
 * printed by consentHead()); the script URLs and Content Security Policy hosts live here, so the script names no host.
 *
 * Hosts (see Plans 40 and 41 for the sources): Yandex's "Using the counter with Content Security Policy"
 * (yandex.com/support/metrica/code/install-counter-csp.html); Google's "Use a Content Security Policy"
 * (developers.google.com/tag-platform/security/guides/csp: Google Analytics, and Google Ads); Meta's pixel guide
 * (developers.facebook.com/docs/meta-pixel/advanced/) and its fbevents.js; VK's and TikTok's loaded scripts (neither
 * publishes a list).
 */
final readonly class Counters
{
    public const string METRICA_SCRIPT = 'https://mc.yandex.ru/metrika/tag.js';

    /** Google's tag (gtag.js) without the id: GA4 and Google Ads share one load, named by the first id allowed. */
    public const string GTAG_SCRIPT = 'https://www.googletagmanager.com/gtag/js?id=';

    public const string META_SCRIPT = 'https://connect.facebook.net/en_US/fbevents.js';

    public const string VK_SCRIPT = 'https://top-fwz1.mail.ru/js/code.js';

    /** TikTok's loader; consent.js adds "?sdkid={id}&lib=ttq" as TikTok's snippet does. */
    public const string TIKTOK_SCRIPT = 'https://analytics.tiktok.com/i18n/pixel/events.js';

    /** Metrica's counter hosts: mc.yandex.ru and its official regional twins. */
    private const array METRICA_HOSTS = [
        'mc.yandex.ru', 'mc.yandex.az', 'mc.yandex.by', 'mc.yandex.co.il', 'mc.yandex.com', 'mc.yandex.com.am',
        'mc.yandex.com.ge', 'mc.yandex.com.tr', 'mc.yandex.ee', 'mc.yandex.fr', 'mc.yandex.kg', 'mc.yandex.kz',
        'mc.yandex.lt', 'mc.yandex.lv', 'mc.yandex.md', 'mc.yandex.tj', 'mc.yandex.tm', 'mc.yandex.uz',
    ];

    private const array METRICA_SCRIPTS = ['https://mc.yandex.ru', 'https://mc.yandex.com', 'https://yastatic.net'];

    private const array WEBVISOR_HOSTS = ['mc.webvisor.com', 'mc.webvisor.org'];

    /** The Metrica pages that show the site in a frame: the click and link maps, Webvisor's player. */
    private const array METRICA_FRAME_ANCESTORS = [
        'metrika.yandex.ru', 'analytics.yandex.by', 'analytics.yandex.com', 'analytics.yandex.com.tr',
        'analytics.yandex.kz', 'analytics.yandex.ru', 'metr.yandex.by', 'metr.yandex.com', 'metr.yandex.com.tr',
        'metr.yandex.kz', 'metr.yandex.ru', 'metrica.ya.ru', 'metrica.yandex', 'metrica.yandex.by', 'metrica.yandex.com',
        'metrica.yandex.com.tr', 'metrica.yandex.kz', 'metrica.yandex.ru', 'metrika.ya.ru', 'metrika.yandex',
        'metrika.yandex.by', 'metrika.yandex.com', 'metrika.yandex.com.tr', 'metrika.yandex.kz', 'metrika.yandex.uz',
    ];

    private const array GOOGLE_SCRIPTS = ['https://www.googletagmanager.com'];

    private const array GOOGLE_IMAGES = ['https://www.googletagmanager.com', 'https://*.google-analytics.com'];

    private const array GOOGLE_CONNECT = ['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.google.com'];

    /**
     * Google's GA4 list with the advertising features (Google signals) adds *.g.doubleclick.net to img-src and
     * connect-src: allowed for a Google tag (GA4 or Ads) once the site has an advertising pixel (Plan 41 review).
     */
    private const string GOOGLE_DOUBLECLICK = 'https://*.g.doubleclick.net';

    private const array GOOGLE_ADS_SCRIPTS = ['https://www.googletagmanager.com', 'https://www.googleadservices.com', 'https://www.google.com'];

    /**
     * Google Ads' images and requests. Its audience lists report to the visitor's www.google.<TLD>, which CSP cannot
     * wildcard: www.google.com and the countries of the five interface languages (Plan 41).
     */
    private const array GOOGLE_ADS_HOSTS = [
        'https://www.googletagmanager.com', 'https://www.googleadservices.com', 'https://googleads.g.doubleclick.net',
        'https://pagead2.googlesyndication.com', 'https://www.google.com', 'https://www.google.ru',
        'https://www.google.co.il', 'https://www.google.de', 'https://www.google.fr', 'https://www.google.co.uk',
    ];

    /** Google Ads' frames: the tag's own and the remarketing frame. */
    private const array GOOGLE_ADS_FRAMES = ['https://www.googletagmanager.com', 'https://td.doubleclick.net'];

    /** The VK counter (code.js) loads privacy-cs.mail.ru's sync-loader.js and pings ad.mail.ru's retargeting. */
    private const array VK_SCRIPTS = ['https://top-fwz1.mail.ru', 'https://privacy-cs.mail.ru'];

    private const array VK_IMAGES = ['https://top-fwz1.mail.ru', 'https://ad.mail.ru'];

    private const array VK_CONNECT = ['https://top-fwz1.mail.ru', 'https://privacy-cs.mail.ru'];

    private const array TIKTOK_HOSTS = ['https://analytics.tiktok.com'];

    /**
     * @param string|null $metrica the Yandex Metrica counter number (digits)
     * @param bool $webvisor Metrica records sessions (Webvisor); only with a counter
     * @param string|null $googleAnalytics the GA4 measurement id, "G-…"
     * @param string|null $metaPixel the Meta Pixel id (digits)
     * @param string|null $googleAds the Google Ads conversion id, "AW-…"
     * @param string|null $vkPixel the VK Ads (Top@Mail.ru) pixel id (digits)
     * @param string|null $tiktokPixel the TikTok Pixel id
     */
    private function __construct(
        public ?string $metrica,
        public bool $webvisor,
        public ?string $googleAnalytics,
        public ?string $metaPixel,
        public ?string $googleAds,
        public ?string $vkPixel,
        public ?string $tiktokPixel,
    ) {}

    public static function of(Settings $settings): self
    {
        return new self(
            $settings->yandexMetrica,
            $settings->yandexMetrica !== null && $settings->yandexWebvisor,
            $settings->googleAnalytics,
            $settings->metaPixel,
            $settings->googleAds,
            $settings->vkPixel,
            $settings->tiktokPixel,
        );
    }

    public function isEmpty(): bool
    {
        return $this->services() === [];
    }

    /**
     * The configured services of the "statistics" category, in this order: "metrica", "google_analytics".
     *
     * @return list<string>
     */
    public function statistics(): array
    {
        return self::keys(['metrica' => $this->metrica, 'google_analytics' => $this->googleAnalytics]);
    }

    /**
     * The configured services of the "advertising" category, in this order: "meta", "google_ads", "vk", "tiktok".
     *
     * @return list<string>
     */
    public function advertising(): array
    {
        return self::keys(['meta' => $this->metaPixel, 'google_ads' => $this->googleAds, 'vk' => $this->vkPixel, 'tiktok' => $this->tiktokPixel]);
    }

    /**
     * Every configured service, the statistics ones first (the theme names them in its banner).
     *
     * @return list<string>
     */
    public function services(): array
    {
        return [...$this->statistics(), ...$this->advertising()];
    }

    /**
     * Adds the configured services' hosts to this response's policy (a page that loads them after consent: a visitor's;
     * a page framed by Metrica's click map or Webvisor's player is a visitor's too, the session cookie being SameSite=Lax).
     */
    public function allowIn(CspSources $sources): void
    {
        if ($this->metrica !== null) {
            $sources->allow('frame-ancestors', ...self::prefixed('https://', self::METRICA_FRAME_ANCESTORS));
            $sources->allow('script-src', ...self::METRICA_SCRIPTS);
            foreach (['img-src', 'connect-src', 'frame-src'] as $directive) {
                $sources->allow($directive, ...self::prefixed('https://', self::METRICA_HOSTS));
            }
            // tag.js opens a WebSocket (wss://mc.yandex.ru/solid.ws) without Webvisor too (found in the Plan 40 smoke).
            $sources->allow('connect-src', ...self::prefixed('wss://', self::METRICA_HOSTS));
            if ($this->webvisor) {
                $sources->allow('connect-src', ...self::prefixed('https://', self::WEBVISOR_HOSTS));
                $sources->allow('frame-src', ...self::prefixed('https://', self::WEBVISOR_HOSTS));
                $sources->allow('connect-src', ...self::prefixed('wss://', self::WEBVISOR_HOSTS));
            }
        }
        if ($this->googleAnalytics !== null) {
            $sources->allow('script-src', ...self::GOOGLE_SCRIPTS);
            $sources->allow('img-src', ...self::GOOGLE_IMAGES);
            $sources->allow('connect-src', ...self::GOOGLE_CONNECT);
        }
        if ($this->metaPixel !== null) {
            $sources->allow('script-src', 'https://connect.facebook.net');
            $sources->allow('img-src', 'https://www.facebook.com');
            $sources->allow('connect-src', 'https://www.facebook.com', 'https://connect.facebook.net');
        }
        if ($this->googleAds !== null) {
            $sources->allow('script-src', ...self::GOOGLE_ADS_SCRIPTS);
            $sources->allow('img-src', ...self::GOOGLE_ADS_HOSTS);
            $sources->allow('connect-src', ...[...self::GOOGLE_ADS_HOSTS, 'https://ad.doubleclick.net']);
            $sources->allow('frame-src', ...self::GOOGLE_ADS_FRAMES);
        }
        if (($this->googleAnalytics !== null || $this->googleAds !== null) && $this->advertising() !== []) {
            $sources->allow('img-src', self::GOOGLE_DOUBLECLICK);
            $sources->allow('connect-src', self::GOOGLE_DOUBLECLICK);
        }
        if ($this->vkPixel !== null) {
            $sources->allow('script-src', ...self::VK_SCRIPTS);
            $sources->allow('img-src', ...self::VK_IMAGES);
            $sources->allow('connect-src', ...self::VK_CONNECT);
        }
        if ($this->tiktokPixel !== null) {
            foreach (['script-src', 'img-src', 'connect-src'] as $directive) {
                $sources->allow($directive, ...self::TIKTOK_HOSTS);
            }
        }
    }

    /**
     * @param array<string, ?string> $services key => id
     *
     * @return list<string>
     */
    private static function keys(array $services): array
    {
        return array_keys(array_filter($services, static fn(?string $id): bool => $id !== null));
    }

    /**
     * @param list<string> $hosts
     *
     * @return list<string>
     */
    private static function prefixed(string $scheme, array $hosts): array
    {
        return array_map(static fn(string $host): string => $scheme . $host, $hosts);
    }
}
