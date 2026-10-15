<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Http\RequestMemo;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the site settings (the admin's "Site settings" screen; SettingsService writes them). Names, SEO texts, the
 * address and the opening hours are per locale; everything else, and the main menu order (the Sections screen), is
 * site-wide. Templates read them through the siteSettings(), siteName(), pageTitle(), siteHead(), socialLinks() and
 * messengers() helpers, memoised per request.
 */
final class SiteSettings
{
    public const string META_TITLE = 'seo.meta_title';

    public const string META_DESCRIPTION = 'seo.meta_description';

    public const string OG_IMAGE = 'seo.og_image_id';

    public const string TITLE_PATTERN = 'seo.title_pattern';

    public const string HIDE_FROM_SEARCH = 'seo.hide_from_search';

    /** "0" when the theme's accessibility toolbar is off, site-wide; no row = on. */
    public const string ACCESSIBILITY_TOOLBAR = 'site.accessibility_toolbar';

    /** The corner of the toolbar's button (Settings::TOOLBAR_POSITIONS), site-wide; no row = the default. */
    public const string ACCESSIBILITY_TOOLBAR_POSITION = 'site.accessibility_toolbar_position';

    public const string SITE_NAME = 'site.name';

    public const string SITE_SHORT_NAME = 'site.short_name';

    public const string LOGO = 'site.logo_id';

    public const string ICON = 'site.icon_id';

    public const string THEME_COLOR = 'site.theme_color';

    public const string PHONE = 'contact.phone';

    public const string EMAIL = 'contact.email';

    public const string WHATSAPP = 'contact.whatsapp';

    public const string VIBER = 'contact.viber';

    public const string TELEGRAM = 'contact.telegram';

    public const string ADDRESS = 'contact.address';

    public const string OPENING_HOURS = 'contact.opening_hours';

    /** "1" when the theme shows its chat button with the messengers, site-wide; no row = off. */
    public const string CHAT_BUTTON = 'contact.chat_button';

    /** The site's map (GoogleMap::toArray() as JSON), site-wide. */
    public const string MAP = 'contact.map';

    /** The prefix of the social links' names: "social.vk", "social.youtube"… (Settings::SOCIAL_NETWORKS). */
    public const string SOCIAL = 'social.';

    public const string YANDEX_VERIFICATION = 'verification.yandex';

    public const string GOOGLE_VERIFICATION = 'verification.google';

    /** The analytics counters (Analytics\Counters), site-wide: a Yandex Metrica counter number, "1" for Webvisor, a GA4 id. */
    public const string YANDEX_METRICA = 'analytics.yandex_metrica';

    public const string YANDEX_WEBVISOR = 'analytics.yandex_webvisor';

    public const string GOOGLE_ANALYTICS = 'analytics.google_analytics';

    /** The advertising pixels (Analytics\Counters, Plan 41), site-wide: the Meta, Google Ads, VK Ads and TikTok ids. */
    public const string META_PIXEL = 'analytics.meta_pixel';

    public const string GOOGLE_ADS = 'analytics.google_ads';

    public const string VK_PIXEL = 'analytics.vk_pixel';

    public const string TIKTOK_PIXEL = 'analytics.tiktok_pixel';

    /** The cookie banner's text, per locale (none: the theme's). */
    public const string CONSENT_TEXT = 'analytics.consent_text';

    /** The top-level positions of the main menu's pinned collections (MainMenu), as a JSON object. */
    public const string MAIN_MENU_ORDER = 'main_menu.order';

    /** The placeholders of the title pattern: the page's title and the site's name in the page's language. */
    public const string TITLE = '{title}';

    public const string SITE = '{site}';

    public const string DEFAULT_TITLE_PATTERN = '{title} — {site}';

    /** The theme catalog's key of the site name, the fallback of an unset name. */
    public const string CATALOG_NAME = 'site.name';

    /** The media uses of the settings (mediaUses()). */
    public const string USE_SHARING = 'sharing';

    public const string USE_LOGO = 'logo';

    public const string USE_ICON = 'icon';

    /**
     * @param Translator|null $translator the catalogs whose "site.name" (the theme's) stands in for an unset name
     * @param string $appName the last fallback of the site name (config app.name)
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ?Translator $translator = null,
        private readonly string $appName = 'AtarCMS',
    ) {}

    public function of(string $locale): Settings
    {
        $values = $this->settings->forLocale($locale);
        $social = [];
        foreach (Settings::SOCIAL_NETWORKS as $network) {
            if (isset($values[self::SOCIAL . $network])) {
                $social[] = new SocialLink($network, $values[self::SOCIAL . $network]);
            }
        }
        $whatsapp = $values[self::WHATSAPP] ?? null;
        $viber = $values[self::VIBER] ?? null;
        $telegram = $values[self::TELEGRAM] ?? null;
        $messengers = [];
        if ($whatsapp !== null) {
            $messengers[] = new SocialLink('whatsapp', 'https://wa.me/' . ltrim($whatsapp, '+'));
        }
        if ($viber !== null) {
            $messengers[] = new SocialLink('viber', 'viber://chat?number=%2B' . ltrim($viber, '+'));
        }
        if ($telegram !== null) {
            $messengers[] = new SocialLink('telegram', 'https://t.me/' . $telegram);
        }

        return new Settings(
            metaTitle: $values[self::META_TITLE] ?? null,
            metaDescription: $values[self::META_DESCRIPTION] ?? null,
            ogImageId: self::id($values[self::OG_IMAGE] ?? ''),
            phone: $values[self::PHONE] ?? null,
            email: $values[self::EMAIL] ?? null,
            siteName: $values[self::SITE_NAME] ?? null,
            siteShortName: $values[self::SITE_SHORT_NAME] ?? null,
            logoId: self::id($values[self::LOGO] ?? ''),
            iconId: self::id($values[self::ICON] ?? ''),
            themeColor: $values[self::THEME_COLOR] ?? null,
            titlePattern: $values[self::TITLE_PATTERN] ?? null,
            hideFromSearch: ($values[self::HIDE_FROM_SEARCH] ?? '') === '1',
            whatsapp: $whatsapp,
            viber: $viber,
            telegram: $telegram,
            address: $values[self::ADDRESS] ?? null,
            openingHours: $values[self::OPENING_HOURS] ?? null,
            socialLinks: $social,
            messengers: $messengers,
            yandexVerification: $values[self::YANDEX_VERIFICATION] ?? null,
            googleVerification: $values[self::GOOGLE_VERIFICATION] ?? null,
            map: self::map($values[self::MAP] ?? null),
            accessibilityToolbar: ($values[self::ACCESSIBILITY_TOOLBAR] ?? '') !== '0',
            accessibilityToolbarPosition: in_array($values[self::ACCESSIBILITY_TOOLBAR_POSITION] ?? '', Settings::TOOLBAR_POSITIONS, true)
                ? $values[self::ACCESSIBILITY_TOOLBAR_POSITION]
                : Settings::DEFAULT_TOOLBAR_POSITION,
            yandexMetrica: $values[self::YANDEX_METRICA] ?? null,
            yandexWebvisor: ($values[self::YANDEX_WEBVISOR] ?? '') === '1',
            googleAnalytics: $values[self::GOOGLE_ANALYTICS] ?? null,
            consentText: $values[self::CONSENT_TEXT] ?? null,
            metaPixel: $values[self::META_PIXEL] ?? null,
            googleAds: $values[self::GOOGLE_ADS] ?? null,
            vkPixel: $values[self::VK_PIXEL] ?? null,
            tiktokPixel: $values[self::TIKTOK_PIXEL] ?? null,
            chatButton: ($values[self::CHAT_BUTTON] ?? '') === '1',
        );
    }

    /**
     * The site's name in $locale: the setting, else the theme catalog's "site.name", else app.name.
     */
    public function siteName(string $locale, ?Settings $settings = null): string
    {
        $name = ($settings ?? $this->of($locale))->siteName;
        if ($name !== null) {
            return $name;
        }
        if ($this->translator !== null && $this->translator->canTranslate($locale, self::CATALOG_NAME)) {
            return $this->translator->translate($locale, self::CATALOG_NAME);
        }

        return $this->appName;
    }

    /**
     * The name under the icon on a phone in $locale: the short name, else the site name.
     */
    public function shortName(string $locale, ?Settings $settings = null): string
    {
        $settings ??= $this->of($locale);

        return $settings->siteShortName ?? $this->siteName($locale, $settings);
    }

    /**
     * A page's <title> by the title pattern: the site name alone for a page without a title (null or '').
     */
    public function pageTitle(?string $title, string $locale, ?Settings $settings = null): string
    {
        $settings ??= $this->of($locale);
        $site = $this->siteName($locale, $settings);
        $title = trim($title ?? '');
        if ($title === '') {
            return $site;
        }

        return strtr($settings->titlePattern ?? self::DEFAULT_TITLE_PATTERN, [self::TITLE => $title, self::SITE => $site]);
    }

    /**
     * The media files the settings use (site-wide): media id => what for (USE_SHARING, USE_LOGO, USE_ICON). Such a
     * file is in use besides the entries: it is not "unused" and cannot be deleted.
     *
     * @return array<int, list<string>>
     */
    public function mediaUses(): array
    {
        $settings = $this->of('');
        $uses = [];
        foreach ([self::USE_LOGO => $settings->logoId, self::USE_ICON => $settings->iconId, self::USE_SHARING => $settings->ogImageId] as $use => $id) {
            if ($id !== null) {
                $uses[$id][] = $use;
            }
        }

        return $uses;
    }

    /**
     * @return array<string, int> collection name => its top-level index in the main menu; empty until the Sections
     *                            screen first saves the order
     */
    public function mainMenuOrder(): array
    {
        try {
            $order = json_decode($this->settings->forLocale('')[self::MAIN_MENU_ORDER] ?? '{}', true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        $positions = [];
        foreach (is_array($order) ? $order : [] as $name => $position) {
            if (is_string($name) && is_int($position)) {
                $positions[$name] = $position;
            }
        }

        return $positions;
    }

    /**
     * The settings of the request's locale, read once per request (RequestMemo).
     */
    public function forRequest(ServerRequestInterface $request): Settings
    {
        return RequestMemo::through($request, 'site-settings', fn(): Settings => $this->of(RequestLocale::of($request)->code));
    }

    /**
     * The stored map; a value that is not GoogleMap::toArray()'s JSON is no map.
     */
    private static function map(?string $value): ?GoogleMap
    {
        try {
            return $value === null ? null : GoogleMap::fromArray(json_decode($value, true, 3, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }
    }

    private static function id(string $value): ?int
    {
        return preg_match('/^[1-9]\d{0,9}$/', $value) === 1 ? (int) $value : null;
    }
}
