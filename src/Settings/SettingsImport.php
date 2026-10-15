<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use Closure;

/**
 * The JSON shape of bin/atar settings:import, the values of the Settings screen:
 *
 *     {"site": {"theme_color", "title_pattern", "hide_from_search", "accessibility_toolbar", "phone", "email",
 *               "whatsapp", "viber", "telegram", "chat_button", "social": {network: url}, "map": {one way, see below}, "yandex_verification",
 *               "google_verification", "accessibility_toolbar_position", "logo_id", "icon_id",
 *               "og_image_id", "yandex_metrica", "yandex_webvisor", "google_analytics", "meta_pixel", "google_ads",
 *               "vk_pixel", "tiktok_pixel"},
 *      "locales": {"en": {"site_name", "site_short_name", "meta_title", "meta_description", "address", "opening_hours",
 *                         "consent_text"}}}
 *
 * The map (GoogleMap, one for every language) is given one way of the contact map dialog (GoogleMapInput): {"embed":
 * Google's embed code, its src or its pb}, {"link": a Google Maps address}, {"address": text}, {"latitude",
 * "longitude", "zoom"}, or the stored shape that --example prints, {"query", "zoom"}; null removes it.
 *
 * A missing key keeps the current value, "" or null removes it. inputs() lays the JSON over the current settings and
 * gives the SettingsInput of each language to save: the listed ones, and the default language when "site" is given
 * (the site-wide values are saved with any language). SettingsService validates them like the screen.
 *
 * @phpstan-type Fields array{metaTitle: string, metaDescription: string, ogImageId: ?int, phone: string, email: string, siteName: string, siteShortName: string, logoId: ?int, iconId: ?int, themeColor: string, titlePattern: string, hideFromSearch: bool, whatsapp: string, viber: string, telegram: string, address: string, openingHours: string, social: array<string, string>, yandexVerification: string, googleVerification: string, accessibilityToolbar: bool, accessibilityToolbarPosition: string, yandexMetrica: string, yandexWebvisor: bool, googleAnalytics: string, consentText: string, metaPixel: string, googleAds: string, vkPixel: string, tiktokPixel: string, chatButton: bool}
 */
final class SettingsImport
{
    /** JSON key => SettingsInput property, per language. */
    public const array LOCALE_KEYS = [
        'site_name' => 'siteName',
        'site_short_name' => 'siteShortName',
        'meta_title' => 'metaTitle',
        'meta_description' => 'metaDescription',
        'address' => 'address',
        'opening_hours' => 'openingHours',
        'consent_text' => 'consentText',
    ];

    /** JSON key => SettingsInput property, site-wide strings. */
    public const array SITE_KEYS = [
        'theme_color' => 'themeColor',
        'title_pattern' => 'titlePattern',
        'phone' => 'phone',
        'email' => 'email',
        'whatsapp' => 'whatsapp',
        'viber' => 'viber',
        'telegram' => 'telegram',
        'yandex_verification' => 'yandexVerification',
        'google_verification' => 'googleVerification',
        'accessibility_toolbar_position' => 'accessibilityToolbarPosition',
        'yandex_metrica' => 'yandexMetrica',
        'google_analytics' => 'googleAnalytics',
        'meta_pixel' => 'metaPixel',
        'google_ads' => 'googleAds',
        'vk_pixel' => 'vkPixel',
        'tiktok_pixel' => 'tiktokPixel',
    ];

    /** JSON key => SettingsInput property, site-wide media ids. */
    public const array MEDIA_KEYS = ['logo_id' => 'logoId', 'icon_id' => 'iconId', 'og_image_id' => 'ogImageId'];

    /**
     * @param array<mixed> $data the decoded JSON
     * @param list<string> $locales the site's languages
     * @param Closure(string): Settings $current the current settings of a language
     *
     * @return array<string, SettingsInput> locale => input, the default language first
     *
     * @throws SettingsImportException
     */
    public static function inputs(array $data, array $locales, string $default, Closure $current): array
    {
        $problems = [];
        foreach (array_keys($data) as $key) {
            if ($key !== 'site' && $key !== 'locales') {
                $problems[] = sprintf('Unknown key "%s"; known keys: site, locales', $key);
            }
        }
        $site = self::object($data, 'site', 'site', $problems);
        $siteValues = [];
        foreach ($site ?? [] as $key => $value) {
            $path = 'site.' . $key;
            if (isset(self::SITE_KEYS[$key])) {
                $siteValues[self::SITE_KEYS[$key]] = self::string($value, $path, $problems);
            } elseif (isset(self::MEDIA_KEYS[$key])) {
                // "" or null removes the image, like every other key.
                if ($value !== null && $value !== '' && !is_int($value)) {
                    $problems[] = $path . ' must be a media id (a number), "" or null';
                } else {
                    $siteValues[self::MEDIA_KEYS[$key]] = $value === '' ? null : $value;
                }
            } elseif ($key === 'hide_from_search' || $key === 'accessibility_toolbar' || $key === 'yandex_webvisor' || $key === 'chat_button') {
                if ($value !== null && $value !== '' && !is_bool($value)) {
                    $problems[] = $path . ' must be true, false, "" or null';
                } elseif ($key === 'hide_from_search') {
                    $siteValues['hideFromSearch'] = $value === true;
                } elseif ($key === 'yandex_webvisor') {
                    $siteValues['yandexWebvisor'] = $value === true;
                } elseif ($key === 'chat_button') {
                    $siteValues['chatButton'] = $value === true;
                } else {
                    // Removed ("" or null) is the default: on.
                    $siteValues['accessibilityToolbar'] = $value !== false;
                }
            } elseif ($key === 'social') {
                $social = self::object(['social' => $value], 'social', $path, $problems) ?? [];
                foreach ($social as $network => $url) {
                    if (!in_array($network, Settings::SOCIAL_NETWORKS, true)) {
                        $problems[] = sprintf('Unknown network "%s.%s"; known: %s', $path, $network, implode(', ', Settings::SOCIAL_NETWORKS));
                    } else {
                        $siteValues['social'][$network] = self::string($url, $path . '.' . $network, $problems);
                    }
                }
            } elseif ($key === 'map') {
                $siteValues['map'] = self::map($value, $path, $problems, $current($default)->map);
            } else {
                $problems[] = sprintf('Unknown key "%s"', $path);
            }
        }
        $localeValues = [];
        foreach (self::object($data, 'locales', 'locales', $problems) ?? [] as $locale => $values) {
            $locale = (string) $locale;
            if (!in_array($locale, $locales, true)) {
                $problems[] = sprintf('The site has no language "%s" (config/locales.php: %s)', $locale, implode(', ', $locales));

                continue;
            }
            $localeValues[$locale] = [];
            foreach (self::object([$locale => $values], $locale, 'locales.' . $locale, $problems) ?? [] as $key => $value) {
                if (isset(self::LOCALE_KEYS[$key])) {
                    $localeValues[$locale][self::LOCALE_KEYS[$key]] = self::string($value, 'locales.' . $locale . '.' . $key, $problems);
                } else {
                    $problems[] = sprintf('Unknown key "locales.%s.%s"', $locale, $key);
                }
            }
        }
        if ($problems !== []) {
            throw new SettingsImportException($problems);
        }

        if ($site !== null) {
            $localeValues = [$default => $localeValues[$default] ?? []] + $localeValues;
        }
        $inputs = [];
        foreach ($localeValues as $locale => $values) {
            // The current values, then the given site-wide and per-language ones, each checked above.
            /** @var Fields $fields */
            $fields = [...self::fields($current($locale)), ...$siteValues, ...$values];
            if (isset($siteValues['social'])) {
                $fields['social'] = array_filter([...self::fields($current($locale))['social'], ...$siteValues['social']], static fn(string $url): bool => $url !== '');
            }
            $inputs[$locale] = new SettingsInput(...$fields);
        }

        return $inputs;
    }

    /**
     * The current settings in the import shape ("" where nothing is set): the template settings:import --example prints.
     *
     * @param list<string> $locales
     * @param Closure(string): Settings $current
     *
     * @return array{site: array<string, mixed>, locales: array<string, array<string, string>>}
     */
    public static function example(array $locales, Closure $current): array
    {
        $fields = self::fields($current($locales[0] ?? 'en'));
        $site = [];
        foreach (self::SITE_KEYS as $key => $property) {
            $site[$key] = $fields[$property];
        }
        $site['hide_from_search'] = $fields['hideFromSearch'];
        $site['accessibility_toolbar'] = $fields['accessibilityToolbar'];
        $site['yandex_webvisor'] = $fields['yandexWebvisor'];
        $site['chat_button'] = $fields['chatButton'];
        $site['social'] = array_merge(array_fill_keys(Settings::SOCIAL_NETWORKS, ''), $fields['social']);
        $site['map'] = $current($locales[0] ?? 'en')->map?->toArray();
        foreach (self::MEDIA_KEYS as $key => $property) {
            $site[$key] = $fields[$property];
        }
        $perLocale = [];
        foreach ($locales as $locale) {
            $values = self::fields($current($locale));
            foreach (self::LOCALE_KEYS as $key => $property) {
                $perLocale[$locale][$key] = $values[$property];
            }
        }

        return ['site' => $site, 'locales' => $perLocale];
    }

    /**
     * Where an error of SettingsService (a field of the Settings screen) is in the JSON.
     */
    public static function path(string $locale, string $field): string
    {
        if (str_starts_with($field, 'map_')) {
            return $field === 'map_method' ? 'site.map' : 'site.map.' . substr($field, 4);
        }
        if (str_starts_with($field, 'social_')) {
            return 'site.social.' . substr($field, 7);
        }

        return isset(self::LOCALE_KEYS[$field]) ? 'locales.' . $locale . '.' . $field : 'site.' . $field;
    }

    /**
     * The SettingsInput arguments that keep $settings as they are.
     *
     * @return Fields
     */
    private static function fields(Settings $settings): array
    {
        $social = [];
        foreach ($settings->socialLinks as $link) {
            $social[$link->network] = $link->url;
        }

        return [
            'metaTitle' => $settings->metaTitle ?? '',
            'metaDescription' => $settings->metaDescription ?? '',
            'ogImageId' => $settings->ogImageId,
            'phone' => $settings->phone ?? '',
            'email' => $settings->email ?? '',
            'siteName' => $settings->siteName ?? '',
            'siteShortName' => $settings->siteShortName ?? '',
            'logoId' => $settings->logoId,
            'iconId' => $settings->iconId,
            'themeColor' => $settings->themeColor ?? '',
            'titlePattern' => $settings->titlePattern ?? '',
            'hideFromSearch' => $settings->hideFromSearch,
            'whatsapp' => $settings->whatsapp ?? '',
            'viber' => $settings->viber ?? '',
            'telegram' => $settings->telegram ?? '',
            'address' => $settings->address ?? '',
            'openingHours' => $settings->openingHours ?? '',
            'social' => $social,
            'yandexVerification' => $settings->yandexVerification ?? '',
            'googleVerification' => $settings->googleVerification ?? '',
            'accessibilityToolbar' => $settings->accessibilityToolbar,
            'accessibilityToolbarPosition' => $settings->accessibilityToolbarPosition,
            'yandexMetrica' => $settings->yandexMetrica ?? '',
            'yandexWebvisor' => $settings->yandexWebvisor,
            'googleAnalytics' => $settings->googleAnalytics ?? '',
            'consentText' => $settings->consentText ?? '',
            'metaPixel' => $settings->metaPixel ?? '',
            'googleAds' => $settings->googleAds ?? '',
            'vkPixel' => $settings->vkPixel ?? '',
            'tiktokPixel' => $settings->tiktokPixel ?? '',
            'chatButton' => $settings->chatButton,
        ];
    }

    /**
     * The map's SettingsInput value: null keeps the stored map (nothing given, or the same as $current), [] removes
     * it, else the input of one way with "method" naming it (SettingsService checks it through GoogleMapInput).
     *
     * @param list<string> $problems
     *
     * @return array<string, string>|null
     */
    private static function map(mixed $value, string $path, array &$problems, ?GoogleMap $current): ?array
    {
        if ($value === null || $value === '') {
            return $current === null ? null : [];
        }
        if ($current !== null && $value === $current->toArray()) {
            return null;
        }
        $map = self::object(['map' => $value], 'map', $path, $problems);
        if ($map === null) {
            return null;
        }
        $known = ['embed', 'link', 'query', 'address', 'latitude', 'longitude', 'zoom'];
        $input = [];
        foreach ($map as $name => $item) {
            if (!in_array($name, $known, true)) {
                $problems[] = sprintf('Unknown key "%s.%s"; known: %s', $path, $name, implode(', ', $known));
            } elseif (is_string($item) || is_int($item) || is_float($item)) {
                $input[$name] = (string) $item;
            } else {
                $problems[] = sprintf('%s.%s must be a string or a number', $path, $name);
            }
        }
        if (count($input) !== count($map)) {
            return null;
        }
        $ways = array_keys(array_diff_key($input, ['zoom' => true]));
        $method = match (true) {
            $ways === ['embed'] && count($input) === 1 => 'embed',
            $ways === ['link'] && count($input) === 1 => 'link',
            $ways === ['query'] => 'query',
            $ways === ['address'] && count($input) === 1 => 'address',
            $ways !== [] && array_diff($ways, ['latitude', 'longitude']) === [] => 'coordinates',
            default => null,
        };
        if ($method === null) {
            $problems[] = $path . ' must be given one way: {"embed"}, {"link"}, {"query", "zoom"}, {"address"} or {"latitude", "longitude", "zoom"}';

            return null;
        }

        return ['method' => $method, ...$input];
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $problems
     *
     * @return array<string, mixed>|null null when the key is missing (or not an object)
     */
    private static function object(array $data, string $key, string $path, array &$problems): ?array
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $problems[] = $path . ' must be an object';

            return null;
        }
        $object = [];
        foreach ($value as $name => $item) {
            $object[(string) $name] = $item;
        }

        return $object;
    }

    /**
     * @param list<string> $problems
     */
    private static function string(mixed $value, string $path, array &$problems): string
    {
        if ($value !== null && !is_string($value)) {
            $problems[] = $path . ' must be a string or null';

            return '';
        }

        return $value ?? '';
    }
}
