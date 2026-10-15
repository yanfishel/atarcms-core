<?php

declare(strict_types=1);

namespace AtarCms\Settings;

/**
 * The submitted site settings of one locale, before validation; '' (or null) removes a value.
 */
final readonly class SettingsInput
{
    /**
     * @param array<string, string> $social network (Settings::SOCIAL_NETWORKS) => URL; a missing network is removed
     * @param string $accessibilityToolbarPosition one of Settings::TOOLBAR_POSITIONS; '' is the default too
     * @param array<string, string>|null $map the map to save through GoogleMapInput (bin/atar settings:import): null keeps
     *                                     the stored one, [] removes it, else the input of one way, "method" naming it
     */
    public function __construct(
        public string $metaTitle = '',
        public string $metaDescription = '',
        public ?int $ogImageId = null,
        public string $phone = '',
        public string $email = '',
        public string $siteName = '',
        public string $siteShortName = '',
        public ?int $logoId = null,
        public ?int $iconId = null,
        public string $themeColor = '',
        public string $titlePattern = '',
        public bool $hideFromSearch = false,
        public string $whatsapp = '',
        public string $viber = '',
        public string $telegram = '',
        public string $address = '',
        public string $openingHours = '',
        public array $social = [],
        public string $yandexVerification = '',
        public string $googleVerification = '',
        public ?array $map = null,
        public bool $accessibilityToolbar = true,
        public string $accessibilityToolbarPosition = Settings::DEFAULT_TOOLBAR_POSITION,
        public string $yandexMetrica = '',
        public bool $yandexWebvisor = false,
        public string $googleAnalytics = '',
        public string $consentText = '',
        public string $metaPixel = '',
        public string $googleAds = '',
        public string $vkPixel = '',
        public string $tiktokPixel = '',
        public bool $chatButton = false,
    ) {}
}
