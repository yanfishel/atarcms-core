<?php

declare(strict_types=1);

namespace AtarCms\Analytics;

use AtarCms\Auth\CurrentUser;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\PublicContent;
use AtarCms\Http\RequestMemo;
use AtarCms\Http\Security\CspNonce;
use AtarCms\Http\Security\CspSources;
use AtarCms\I18n\RequestLocale;
use AtarCms\Settings\SiteSettings;
use AtarCms\View\AssetUrl;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The cookie consent of the analytics counters and advertising pixels (Plans 40 and 41), behind the theme helpers
 * consent() and consentHead(). Nothing at all without a service; nothing for signed-in users (the admin and editors are
 * not counted: their policy stays the plain one); each consent category loads in the browser only after the visitor
 * allows it (consent.js, the choice in the atar_consent cookie), so every visitor gets the same page.
 */
final readonly class Consent
{
    /** The visitor's choice per category (ConsentChoice: "s1.a0" …), 180 days, first-party, readable by the script. */
    public const string COOKIE = 'atar_consent';

    /** The preset page the banner links (Collection::presets(), the skeleton's pages collection). */
    public const string PRIVACY_PRESET = 'privacy';

    /** The core script, an admin asset (as the AtarCMS logo files that public pages use). */
    public const string SCRIPT = 'consent.js';

    public function __construct(
        private SiteSettings $settings,
        private PublicContent $content,
        private EntryUrls $urls,
        private AssetUrl $adminAssets,
    ) {}

    /**
     * The banner's data, or null without a service or for a signed-in user.
     */
    public function view(ServerRequestInterface $request): ?ConsentView
    {
        $settings = $this->settings->forRequest($request);
        $counters = Counters::of($settings);
        if ($counters->isEmpty() || CurrentUser::of($request) !== null) {
            return null;
        }
        $locale = RequestLocale::of($request)->code;
        // The same memo as the presetEntry()/presetUrl() helpers: one query per request for every preset page.
        $presets = RequestMemo::through($request, 'presets:' . $locale, fn(): array => $this->content->presets($locale));
        $privacy = $presets[self::PRIVACY_PRESET] ?? null;

        return new ConsentView(
            metrica: $counters->metrica,
            webvisor: $counters->webvisor,
            googleAnalytics: $counters->googleAnalytics,
            text: $settings->consentText,
            privacyUrl: $privacy === null ? null : $this->urls->path($privacy),
            state: ConsentChoice::fromCookie(self::cookie($request)),
            services: $counters->services(),
            metaPixel: $counters->metaPixel,
            googleAds: $counters->googleAds,
            vkPixel: $counters->vkPixel,
            tiktokPixel: $counters->tiktokPixel,
            statistics: $counters->statistics(),
            advertising: $counters->advertising(),
        );
    }

    /**
     * The loader's <script> (with the nonce, the services' ids and script URLs in data attributes), or '' without a
     * service or for a signed-in user; with the loader it adds the services' hosts to this response's Content Security
     * Policy (Counters::allowIn()).
     */
    public function head(ServerRequestInterface $request): string
    {
        $counters = Counters::of($this->settings->forRequest($request));
        if ($counters->isEmpty() || CurrentUser::of($request) !== null) {
            return '';
        }
        $sources = CspSources::of($request);
        if ($sources !== null) {
            $counters->allowIn($sources);
        }
        $attributes = [
            'type' => 'module',
            'src' => $this->adminAssets->url(self::SCRIPT),
            'nonce' => CspNonce::of($request)->value,
            'data-atar-consent' => null,
        ];
        if ($counters->metrica !== null) {
            $attributes['data-metrica'] = $counters->metrica;
            $attributes['data-metrica-src'] = Counters::METRICA_SCRIPT;
            if ($counters->webvisor) {
                $attributes['data-webvisor'] = null;
            }
        }
        if ($counters->googleAnalytics !== null) {
            $attributes['data-ga'] = $counters->googleAnalytics;
        }
        if ($counters->googleAds !== null) {
            $attributes['data-google-ads'] = $counters->googleAds;
        }
        // One gtag.js for GA4 and Google Ads: the script adds the first allowed id.
        if ($counters->googleAnalytics !== null || $counters->googleAds !== null) {
            $attributes['data-gtag-src'] = Counters::GTAG_SCRIPT;
        }
        if ($counters->metaPixel !== null) {
            $attributes['data-meta'] = $counters->metaPixel;
            $attributes['data-meta-src'] = Counters::META_SCRIPT;
        }
        if ($counters->vkPixel !== null) {
            $attributes['data-vk'] = $counters->vkPixel;
            $attributes['data-vk-src'] = Counters::VK_SCRIPT;
        }
        if ($counters->tiktokPixel !== null) {
            $attributes['data-tiktok'] = $counters->tiktokPixel;
            $attributes['data-tiktok-src'] = Counters::TIKTOK_SCRIPT;
        }
        $html = '<script';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . ($value === null ? '' : '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"');
        }

        return $html . '></script>';
    }

    private static function cookie(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[self::COOKIE] ?? null;

        return is_string($value) ? $value : null;
    }
}
