<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Content\ValidationErrors;
use AtarCms\Database\Connection;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Clock;
use AtarCms\Media\MediaRepository;

/**
 * The only write path for the site settings: validation and the audit log. Error values are translation keys (keyed
 * by the screen's input names); ICU parameters travel in ValidationFailed::$params.
 */
final class SettingsService
{
    /** Changing the site settings (the admin "Site settings" screen) needs this permission. */
    public const string PERMISSION = 'settings.manage';

    public const int MAX_TITLE = 255;

    public const int MAX_DESCRIPTION = 500;

    public const int MAX_PHONE = 100;

    public const int MAX_EMAIL = 200;

    public const int MAX_SITE_NAME = 100;

    public const int MAX_SHORT_NAME = 30;

    public const int MAX_TITLE_PATTERN = 100;

    public const int MAX_TEXT = 500;

    public const int MAX_URL = 500;

    /** The site icon: at least this many pixels on each side, square, one of ICON_TYPES. */
    public const int MIN_ICON = 512;

    public const array ICON_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** The contact details the contact page's form edits (config content.contact_page), with the social links. */
    public const array CONTACT_SETTINGS = [
        SiteSettings::PHONE,
        SiteSettings::EMAIL,
        SiteSettings::WHATSAPP,
        SiteSettings::VIBER,
        SiteSettings::TELEGRAM,
        SiteSettings::ADDRESS,
        SiteSettings::OPENING_HOURS,
        SiteSettings::CHAT_BUTTON,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly SiteSettings $site,
        private readonly MediaRepository $media,
        private readonly Locales $locales,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Saves the values of $locale (names, SEO texts, address, opening hours) and the site-wide ones.
     *
     * @throws ValidationFailed
     */
    public function save(string $locale, SettingsInput $input, ?User $actor, ?string $ip): Settings
    {
        $values = $this->values($locale, $input);
        $this->db->transaction(function () use ($values, $locale, $actor, $ip): void {
            $this->put($values);
            $this->audit->record('settings.updated', $actor?->id, null, null, ['locale' => $locale], $ip);
        });

        return $this->site->of($locale);
    }

    /**
     * Checks the contact details of $input (CONTACT_SETTINGS) as contactRows() reads them, writing nothing: the contact
     * page's form asks before it saves its entry.
     *
     * @throws ValidationFailed keyed by the input names (phone, address, social_vk…)
     */
    public function checkContacts(string $locale, SettingsInput $input): void
    {
        $this->contactRows($locale, $input);
    }

    /**
     * Saves only the contact details of $input (CONTACT_SETTINGS: the address and opening hours of $locale, the
     * site-wide phone, e-mail, messengers and social links), in one transaction: the contact page's form. The audit
     * row names the settings that changed; nothing changed, nothing is written.
     *
     * @throws ValidationFailed
     */
    public function saveContacts(string $locale, SettingsInput $input, ?User $actor, ?string $ip): void
    {
        $rows = $this->contactRows($locale, $input);
        $current = $this->settings->forLocale($locale);
        $changed = array_values(array_filter($rows, static fn(array $row): bool => ($current[$row[0]] ?? null) !== $row[2]));
        if ($changed === []) {
            return;
        }
        $this->db->transaction(function () use ($changed, $locale, $actor, $ip): void {
            $this->put($changed);
            $this->audit->record('settings.updated', $actor?->id, null, null, ['locale' => $locale, 'fields' => array_column($changed, 0)], $ip);
        });
    }

    /**
     * Whether $name is one of the contact details (CONTACT_SETTINGS or a social link).
     */
    public static function isContact(string $name): bool
    {
        return in_array($name, self::CONTACT_SETTINGS, true) || str_starts_with($name, SiteSettings::SOCIAL);
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}>
     *
     * @throws ValidationFailed
     */
    private function contactRows(string $locale, SettingsInput $input): array
    {
        return array_values(array_filter($this->values($locale, $input), static fn(array $row): bool => self::isContact($row[0])));
    }

    /**
     * Saves the settings of several languages at once (bin/atar settings:import): each validated like save(), all
     * written in one transaction with one audit row, or nothing written. Errors are keyed "{locale}.{field}".
     *
     * @param array<string, SettingsInput> $inputs locale => input; each carries the same site-wide values
     *
     * @throws ValidationFailed
     */
    public function import(array $inputs, ?User $actor, ?string $ip, bool $write = true): void
    {
        $all = [];
        $errors = [];
        $params = [];
        foreach ($inputs as $locale => $input) {
            try {
                $all[] = $this->values($locale, $input);
            } catch (ValidationFailed $failed) {
                foreach ($failed->errors as $field => $key) {
                    $errors[$locale . '.' . $field] = $key;
                }
                foreach ($failed->params as $field => $values) {
                    $params[$locale . '.' . $field] = $values;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors, $params);
        }
        if (!$write || $all === []) {
            return;
        }
        $this->db->transaction(function () use ($all, $inputs, $actor, $ip): void {
            foreach ($all as $values) {
                $this->put($values);
            }
            $this->audit->record('settings.updated', $actor?->id, null, null, ['locales' => array_keys($inputs), 'source' => 'import'], $ip);
        });
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $values
     */
    private function put(array $values): void
    {
        $now = $this->clock->now();
        foreach ($values as [$name, $scope, $value]) {
            $this->settings->put($name, $scope, $value, $now);
        }
    }

    /**
     * The rows save() writes for $locale: [name, scope ('' or the locale), value or null].
     *
     * @return list<array{0: string, 1: string, 2: ?string}>
     *
     * @throws ValidationFailed
     */
    private function values(string $locale, SettingsInput $input): array
    {
        $errors = new ValidationErrors();
        if (!$this->locales->isSupported($locale)) {
            $errors->add('locale', 'validation.locale.unsupported');
        }
        $siteName = SettingsValues::line($input->siteName, self::MAX_SITE_NAME, 'site_name', $errors);
        $shortName = SettingsValues::line($input->siteShortName, self::MAX_SHORT_NAME, 'site_short_name', $errors);
        $themeColor = SettingsValues::color($input->themeColor, 'theme_color', $errors);
        $title = SettingsValues::line($input->metaTitle, self::MAX_TITLE, 'meta_title', $errors);
        $description = SettingsValues::line($input->metaDescription, self::MAX_DESCRIPTION, 'meta_description', $errors);
        $titlePattern = SettingsValues::titlePattern($input->titlePattern, 'title_pattern', $errors);
        $phone = SettingsValues::line($input->phone, self::MAX_PHONE, 'phone', $errors);
        $email = SettingsValues::line($input->email, self::MAX_EMAIL, 'email', $errors);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors->add('email', 'validation.email.invalid');
        }
        $whatsapp = SettingsValues::phone($input->whatsapp, 'whatsapp', $errors);
        $viber = SettingsValues::phone($input->viber, 'viber', $errors);
        $telegram = SettingsValues::telegram($input->telegram, 'telegram', $errors);
        $address = SettingsValues::multiline($input->address, self::MAX_TEXT, 'address', $errors);
        $hours = SettingsValues::multiline($input->openingHours, self::MAX_TEXT, 'opening_hours', $errors);
        $map = $this->mapInput($input->map, $errors);
        $social = [];
        foreach (Settings::SOCIAL_NETWORKS as $network) {
            $social[$network] = SettingsValues::httpsUrl($input->social[$network] ?? '', self::MAX_URL, 'social_' . $network, $errors);
        }
        $yandex = SettingsValues::verification($input->yandexVerification, 'yandex_verification', $errors);
        $google = SettingsValues::verification($input->googleVerification, 'google_verification', $errors);
        $metrica = SettingsValues::metrica($input->yandexMetrica, 'yandex_metrica', $errors);
        $googleAnalytics = SettingsValues::googleAnalytics($input->googleAnalytics, 'google_analytics', $errors);
        $metaPixel = SettingsValues::metaPixel($input->metaPixel, 'meta_pixel', $errors);
        $googleAds = SettingsValues::googleAds($input->googleAds, 'google_ads', $errors);
        $vkPixel = SettingsValues::vkPixel($input->vkPixel, 'vk_pixel', $errors);
        $tiktokPixel = SettingsValues::tiktokPixel($input->tiktokPixel, 'tiktok_pixel', $errors);
        $consentText = SettingsValues::line($input->consentText, self::MAX_TEXT, 'consent_text', $errors);
        $toolbarPosition = SettingsValues::toolbarPosition($input->accessibilityToolbarPosition, 'accessibility_toolbar_position', $errors);
        $this->checkImage($input->logoId, 'logo_id', $errors);
        $this->checkImage($input->ogImageId, 'og_image_id', $errors);
        $this->checkIcon($input->iconId, $errors);
        $errors->throwIfAny();

        $id = static fn(?int $id): ?string => $id === null ? null : (string) $id;
        $values = [
            [SiteSettings::SITE_NAME, $locale, $siteName],
            [SiteSettings::SITE_SHORT_NAME, $locale, $shortName],
            [SiteSettings::LOGO, '', $id($input->logoId)],
            [SiteSettings::ICON, '', $id($input->iconId)],
            [SiteSettings::THEME_COLOR, '', $themeColor],
            [SiteSettings::META_TITLE, $locale, $title],
            [SiteSettings::META_DESCRIPTION, $locale, $description],
            [SiteSettings::OG_IMAGE, '', $id($input->ogImageId)],
            [SiteSettings::TITLE_PATTERN, '', $titlePattern],
            [SiteSettings::HIDE_FROM_SEARCH, '', $input->hideFromSearch ? '1' : null],
            [SiteSettings::PHONE, '', $phone],
            [SiteSettings::EMAIL, '', $email],
            [SiteSettings::WHATSAPP, '', $whatsapp],
            [SiteSettings::VIBER, '', $viber],
            [SiteSettings::TELEGRAM, '', $telegram],
            [SiteSettings::ADDRESS, $locale, $address],
            [SiteSettings::OPENING_HOURS, $locale, $hours],
            // Off is the default: only "on" is stored.
            [SiteSettings::CHAT_BUTTON, '', $input->chatButton ? '1' : null],
            [SiteSettings::YANDEX_VERIFICATION, '', $yandex],
            [SiteSettings::GOOGLE_VERIFICATION, '', $google],
            // On is the default: only "off" is stored.
            [SiteSettings::ACCESSIBILITY_TOOLBAR, '', $input->accessibilityToolbar ? null : '0'],
            [SiteSettings::ACCESSIBILITY_TOOLBAR_POSITION, '', $toolbarPosition],
            [SiteSettings::YANDEX_METRICA, '', $metrica],
            // Webvisor is Metrica's: without a counter it is not stored.
            [SiteSettings::YANDEX_WEBVISOR, '', $metrica !== null && $input->yandexWebvisor ? '1' : null],
            [SiteSettings::GOOGLE_ANALYTICS, '', $googleAnalytics],
            [SiteSettings::CONSENT_TEXT, $locale, $consentText],
            [SiteSettings::META_PIXEL, '', $metaPixel],
            [SiteSettings::GOOGLE_ADS, '', $googleAds],
            [SiteSettings::VK_PIXEL, '', $vkPixel],
            [SiteSettings::TIKTOK_PIXEL, '', $tiktokPixel],
        ];
        foreach ($social as $network => $url) {
            $values[] = [SiteSettings::SOCIAL . $network, '', $url];
        }
        if ($input->map !== null) {
            $values[] = [SiteSettings::MAP, '', $map === null ? null : self::mapJson($map)];
        }

        return $values;
    }

    /**
     * Stores the top-level positions of the main menu's pinned collections (collection name => index); MainMenuService
     * calls it when the Sections screen saves the order.
     *
     * @param array<string, int> $order
     */
    public function saveMainMenuOrder(array $order, ?User $actor, ?string $ip): void
    {
        if ($order === $this->site->mainMenuOrder()) {
            return;
        }
        $this->settings->put(SiteSettings::MAIN_MENU_ORDER, '', json_encode((object) $order, JSON_THROW_ON_ERROR), $this->clock->now());
        $this->audit->record('main_menu.reordered', $actor?->id, null, null, ['order' => $order], $ip);
    }

    /**
     * Stores the site's map (the contact map dialog; GoogleMapInput reads what the admin entered).
     */
    public function saveMap(GoogleMap $map, ?User $actor, ?string $ip): void
    {
        $this->putMap(self::mapJson($map), $actor, $ip);
    }

    /**
     * Removes the site's map: the contact page shows none.
     */
    public function removeMap(?User $actor, ?string $ip): void
    {
        $this->putMap(null, $actor, $ip);
    }

    private function putMap(?string $value, ?User $actor, ?string $ip): void
    {
        $this->db->transaction(function () use ($value, $actor, $ip): void {
            $this->settings->put(SiteSettings::MAP, '', $value, $this->clock->now());
            $this->audit->record('settings.updated', $actor?->id, null, null, ['fields' => [SiteSettings::MAP]], $ip);
        });
    }

    private static function mapJson(GoogleMap $map): string
    {
        return json_encode($map->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The map of an import (SettingsInput::$map): null when none is given or it is removed ([]); its errors go under
     * "map_{input}" (SettingsImport::path() finds them in the JSON).
     *
     * @param array<string, string>|null $input
     */
    private function mapInput(?array $input, ValidationErrors $errors): ?GoogleMap
    {
        if ($input === null || $input === []) {
            return null;
        }
        $method = $input['method'] ?? '';
        if (!in_array($method, GoogleMapInput::METHODS, true)) {
            $errors->add('map_method', 'validation.field.invalid');

            return null;
        }
        try {
            return GoogleMapInput::parse($method, $input);
        } catch (ValidationFailed $failed) {
            foreach ($failed->errors as $field => $key) {
                $errors->add('map_' . $field, $key, $failed->params[$field] ?? []);
            }

            return null;
        }
    }

    private function checkImage(?int $id, string $field, ValidationErrors $errors): void
    {
        if ($id === null) {
            return;
        }
        $image = $this->media->find($id);
        if ($image === null) {
            $errors->add($field, 'validation.field.media');
        } elseif (!$image->isImage()) {
            $errors->add($field, 'validation.field.media_type');
        }
    }

    private function checkIcon(?int $id, ValidationErrors $errors): void
    {
        if ($id === null) {
            return;
        }
        $icon = $this->media->find($id);
        if ($icon === null) {
            $errors->add('icon_id', 'validation.field.media');
        } elseif (!$icon->isImage()) {
            $errors->add('icon_id', 'validation.field.media_type');
        } elseif (!in_array($icon->mime, self::ICON_TYPES, true)) {
            $errors->add('icon_id', 'validation.settings.icon_type');
        } elseif ($icon->width !== $icon->height) {
            $errors->add('icon_id', 'validation.settings.icon_square');
        } elseif (($icon->width ?? 0) < self::MIN_ICON) {
            $errors->add('icon_id', 'validation.settings.icon_small', ['min' => self::MIN_ICON]);
        }
    }
}
