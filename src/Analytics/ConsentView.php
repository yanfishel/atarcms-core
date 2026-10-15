<?php

declare(strict_types=1);

namespace AtarCms\Analytics;

/**
 * What a theme's cookie banner shows (theme helper consent()): which counters and advertising pixels the site has, by
 * consent category, the owner's banner text of the page's language (null: the theme's own) and the privacy policy's
 * address. The banner itself is the theme's; resources/admin/assets/consent.js (consentHead()) shows it while the
 * visitor has not decided every configured category and loads each category after it is allowed. With advertising
 * pixels the banner offers a choice per category (the theme guide's Cookie banner). Every value is user input: escape it.
 */
final readonly class ConsentView
{
    /**
     * @param string|null $metrica the Yandex Metrica counter number
     * @param bool $webvisor Metrica records sessions (Webvisor): the banner must say so
     * @param string|null $googleAnalytics the GA4 measurement id
     * @param string|null $text the banner's text in this language from Site settings, or null for the theme's
     * @param string|null $privacyUrl the privacy policy (the preset page "privacy") in this language, or null
     * @param ConsentChoice|null $state the visitor's stored choice (the atar_consent cookie), null when none is readable.
     *                                  Informational: the script decides in the browser, so never vary the markup by it
     *                                  (a cached page would show another visitor's choice)
     * @param list<string> $services every configured service, the statistics ones first
     * @param string|null $metaPixel the Meta Pixel id
     * @param string|null $googleAds the Google Ads conversion id
     * @param string|null $vkPixel the VK Ads pixel id
     * @param string|null $tiktokPixel the TikTok Pixel id
     * @param list<string> $statistics the configured "statistics" services: "metrica", "google_analytics"
     * @param list<string> $advertising the configured "advertising" services: "meta", "google_ads", "vk", "tiktok"
     */
    public function __construct(
        public ?string $metrica,
        public bool $webvisor,
        public ?string $googleAnalytics,
        public ?string $text,
        public ?string $privacyUrl,
        public ?ConsentChoice $state,
        public array $services,
        public ?string $metaPixel = null,
        public ?string $googleAds = null,
        public ?string $vkPixel = null,
        public ?string $tiktokPixel = null,
        public array $statistics = [],
        public array $advertising = [],
    ) {}
}
