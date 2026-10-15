<?php

declare(strict_types=1);

namespace AtarCms\Settings;

/**
 * The site settings as one locale sees them (SiteSettings::of()): the values of that locale (names, SEO texts,
 * address, opening hours) and the site-wide ones. Every value is user input: escape it in templates ($this->e()).
 */
final readonly class Settings
{
    /** The social networks of the "Social" tab, in the order themes list them. */
    public const array SOCIAL_NETWORKS = ['telegram_channel', 'youtube', 'instagram', 'facebook', 'x', 'tiktok', 'pinterest', 'linkedin', 'vk', 'rutube', 'dzen', 'ok'];

    /** The messengers of the "Contacts" tab, in the order themes list them. */
    public const array MESSENGERS = ['whatsapp', 'viber', 'telegram'];

    /** The corners of the accessibility toolbar's button, logical (the order of the admin's list). */
    public const array TOOLBAR_POSITIONS = ['top-start', 'bottom-start', 'top-end', 'bottom-end'];

    public const string DEFAULT_TOOLBAR_POSITION = 'bottom-end';

    /**
     * @param string|null $metaTitle the site title for search engines and link previews in this locale (the home
     *                               page's whole title)
     * @param string|null $metaDescription the default description of pages without their own
     * @param int|null $ogImageId the default image of link previews (a media id)
     * @param string|null $siteName the site's name in this locale (SiteSettings::siteName() adds the fallbacks)
     * @param string|null $siteShortName the name under the icon on a phone in this locale
     * @param int|null $logoId the site's logo (a media id)
     * @param int|null $iconId the site icon: a square image of at least 512 px (a media id)
     * @param string|null $themeColor the browser's toolbar colour, "#rrggbb"
     * @param string|null $titlePattern how page titles are built, with "{title}" and "{site}" (null = the default)
     * @param bool $hideFromSearch the site asks search engines to stay away (a site under construction)
     * @param string|null $whatsapp a phone number, "+" and digits
     * @param string|null $viber a phone number, "+" and digits
     * @param string|null $telegram a Telegram username without "@"
     * @param string|null $address plain multi-line text in this locale
     * @param string|null $openingHours plain multi-line text in this locale
     * @param list<SocialLink> $socialLinks the filled social networks, in SOCIAL_NETWORKS order
     * @param list<SocialLink> $messengers the filled messengers with their chat links, in MESSENGERS order
     * @param GoogleMap|null $map the site's map (one for every language; the page of config content.contact_page shows it)
     * @param bool $accessibilityToolbar the theme shows its accessibility toolbar (on unless switched off)
     * @param string $accessibilityToolbarPosition the corner of the toolbar's button, one of TOOLBAR_POSITIONS (logical:
     *                                            "start" is the left in left-to-right pages, the right in right-to-left ones)
     * @param string|null $yandexMetrica the Yandex Metrica counter number (digits; Analytics\Counters)
     * @param bool $yandexWebvisor Metrica records sessions (Webvisor); stored only with a counter
     * @param string|null $googleAnalytics the Google Analytics 4 measurement id, "G-…"
     * @param string|null $consentText the cookie banner's text in this locale (null: the theme's)
     * @param string|null $metaPixel the Meta Pixel id (digits; an advertising pixel, Analytics\Counters)
     * @param string|null $googleAds the Google Ads conversion id, "AW-…"
     * @param string|null $vkPixel the VK Ads (Top@Mail.ru) pixel id (digits)
     * @param string|null $tiktokPixel the TikTok Pixel id (letters and digits, upper case)
     * @param bool $chatButton the theme shows a chat button with the messengers (off unless switched on)
     */
    public function __construct(
        public ?string $metaTitle = null,
        public ?string $metaDescription = null,
        public ?int $ogImageId = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $siteName = null,
        public ?string $siteShortName = null,
        public ?int $logoId = null,
        public ?int $iconId = null,
        public ?string $themeColor = null,
        public ?string $titlePattern = null,
        public bool $hideFromSearch = false,
        public ?string $whatsapp = null,
        public ?string $viber = null,
        public ?string $telegram = null,
        public ?string $address = null,
        public ?string $openingHours = null,
        public array $socialLinks = [],
        public array $messengers = [],
        public ?string $yandexVerification = null,
        public ?string $googleVerification = null,
        public ?GoogleMap $map = null,
        public bool $accessibilityToolbar = true,
        public string $accessibilityToolbarPosition = self::DEFAULT_TOOLBAR_POSITION,
        public ?string $yandexMetrica = null,
        public bool $yandexWebvisor = false,
        public ?string $googleAnalytics = null,
        public ?string $consentText = null,
        public ?string $metaPixel = null,
        public ?string $googleAds = null,
        public ?string $vkPixel = null,
        public ?string $tiktokPixel = null,
        public bool $chatButton = false,
    ) {}

    /**
     * The site colour: the admin colour preset nearest to the theme colour (green without one). The default site icon
     * (the AtarCMS logo), the share images' brand, the login page and the admin of a user who chose no colour use it.
     */
    public function accent(): AdminAccent
    {
        return AdminAccent::nearest($this->themeColor);
    }
}
