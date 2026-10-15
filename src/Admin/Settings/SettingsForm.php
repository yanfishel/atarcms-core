<?php

declare(strict_types=1);

namespace AtarCms\Admin\Settings;

use AtarCms\Settings\Settings;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;

/**
 * The fields of the "Site settings" screen, listed once: their tab (or the images card), whether they are kept per
 * language, their input kind and their labels. The controller reads the submitted values from it, settings/form.php
 * renders it, and the errors of its fields stay at them (the others are toasts).
 *
 * @phpstan-type SettingsField array{tab: string, locale: bool, kind: string, max: int|null, label: string, help: string|null, icon: string, ltr: bool}
 */
final class SettingsForm
{
    /** The tabs of the left column, in order; their labels are admin.form.tab.{name}. */
    public const array TABS = ['general', 'seo', 'integrations'];

    /**
     * The tabs of the contact details on the contact page's entry form (config content.contact_page, contactFields()):
     * the address and opening hours of the form's language (with the map), the contacts and the social links.
     */
    public const array CONTACT_TABS = ['address', 'contacts', 'social'];

    /** The images card on the right (no tab). */
    public const string IMAGES = 'images';

    /** The checkbox that turns the theme colour on: unchecked, the colour input is ignored (no colour). */
    public const string THEME_COLOR_ON = 'theme_color_on';

    /**
     * Input name => its definition. Kinds: text, textarea, email, tel, url, color, toolbar (the accessibility
     * toolbar's switch, drawn as one group with the position after it), position
     * (the toolbar's corner, a dropdown of Settings::TOOLBAR_POSITIONS inside that group), switch, media. "ltr": always written left to right (phones, emails, addresses, usernames and codes), whatever the interface.
     *
     * @return array<string, SettingsField>
     */
    public static function fields(): array
    {
        return [
            'site_name' => self::field('general', true, 'text', SettingsService::MAX_SITE_NAME, 'admin.settings.field.site_name', 'admin.settings.help.max_chars', 'type'),
            'site_short_name' => self::field('general', true, 'text', SettingsService::MAX_SHORT_NAME, 'admin.settings.field.site_short_name', 'admin.settings.help.max_chars', 'type'),
            'theme_color' => self::field('general', false, 'color', null, 'admin.settings.field.theme_color', 'admin.settings.help.theme_color', 'palette'),
            'accessibility_toolbar' => self::field('general', false, 'toolbar', null, 'admin.settings.field.accessibility_toolbar', 'admin.settings.help.accessibility_toolbar', 'accessibility'),
            'accessibility_toolbar_position' => self::field('general', false, 'position', null, 'admin.settings.field.accessibility_toolbar_position', null, 'app-window'),
            'meta_title' => self::field('seo', true, 'text', SettingsService::MAX_TITLE, 'admin.settings.field.meta_title', null, 'type'),
            'meta_description' => self::field('seo', true, 'textarea', SettingsService::MAX_DESCRIPTION, 'admin.settings.field.meta_description', null, 'align-left'),
            'title_pattern' => self::field('seo', false, 'text', SettingsService::MAX_TITLE_PATTERN, 'admin.settings.field.title_pattern', 'admin.settings.help.title_pattern', 'type'),
            'hide_from_search' => self::field('seo', false, 'switch', null, 'admin.settings.field.hide_from_search', 'admin.settings.help.hide_from_search', 'eye'),
            // Integrations by service (Plans 40 and 41): Google's, Yandex's, Meta's, VK's, TikTok's, then the cookie
            // banner's text. A pasted whole meta tag is longer than the code it holds; the counters and pixels load only
            // after a visitor allows their category in the cookie banner (consent()).
            'google_verification' => self::field('integrations', false, 'text', 300, 'admin.settings.field.google_verification', 'admin.settings.help.verification', 'check', true),
            'google_analytics' => self::field('integrations', false, 'text', 30, 'admin.settings.field.google_analytics', 'admin.settings.help.google_analytics', 'chart-no-axes-column', true),
            'google_ads' => self::field('integrations', false, 'text', 30, 'admin.settings.field.google_ads', 'admin.settings.help.google_ads', 'chart-no-axes-column', true),
            'yandex_verification' => self::field('integrations', false, 'text', 300, 'admin.settings.field.yandex_verification', 'admin.settings.help.verification', 'check', true),
            'yandex_metrica' => self::field('integrations', false, 'text', 12, 'admin.settings.field.yandex_metrica', 'admin.settings.help.yandex_metrica', 'chart-no-axes-column', true),
            'yandex_webvisor' => self::field('integrations', false, 'switch', null, 'admin.settings.field.yandex_webvisor', 'admin.settings.help.yandex_webvisor', 'eye'),
            'meta_pixel' => self::field('integrations', false, 'text', 30, 'admin.settings.field.meta_pixel', 'admin.settings.help.meta_pixel', 'chart-no-axes-column', true),
            'vk_pixel' => self::field('integrations', false, 'text', 20, 'admin.settings.field.vk_pixel', 'admin.settings.help.vk_pixel', 'chart-no-axes-column', true),
            'tiktok_pixel' => self::field('integrations', false, 'text', 40, 'admin.settings.field.tiktok_pixel', 'admin.settings.help.tiktok_pixel', 'chart-no-axes-column', true),
            'consent_text' => self::field('integrations', true, 'textarea', SettingsService::MAX_TEXT, 'admin.settings.field.consent_text', 'admin.settings.help.consent_text', 'info'),
            'logo_id' => self::field(self::IMAGES, false, 'media', null, 'admin.settings.field.logo', null, 'gem'),
            'icon_id' => self::field(self::IMAGES, false, 'media', null, 'admin.settings.field.icon', 'admin.settings.help.icon', 'app-window'),
            'og_image_id' => self::field(self::IMAGES, false, 'media', null, 'admin.settings.field.og_image', 'admin.settings.field.og_image_help', 'share-2'),
        ];
    }

    /**
     * The contact details (SettingsService::CONTACT_SETTINGS and the social links), edited on the contact page's entry
     * form in CONTACT_TABS, not on the Site settings screen; the same definitions as fields().
     *
     * @return array<string, SettingsField>
     */
    public static function contactFields(): array
    {
        $fields = [
            'address' => self::field('address', true, 'textarea', SettingsService::MAX_TEXT, 'admin.settings.field.address', null, 'map-pin'),
            'opening_hours' => self::field('address', true, 'textarea', SettingsService::MAX_TEXT, 'admin.settings.field.opening_hours', null, 'calendar'),
            'phone' => self::field('contacts', false, 'tel', SettingsService::MAX_PHONE, 'admin.settings.field.phone', null, 'hash'),
            'email' => self::field('contacts', false, 'email', SettingsService::MAX_EMAIL, 'admin.settings.field.email', null, 'link'),
            'whatsapp' => self::field('contacts', false, 'tel', SettingsService::MAX_PHONE, 'admin.settings.field.whatsapp', 'admin.settings.help.messenger_phone', 'hash'),
            'viber' => self::field('contacts', false, 'tel', SettingsService::MAX_PHONE, 'admin.settings.field.viber', 'admin.settings.help.messenger_phone', 'hash'),
            'telegram' => self::field('contacts', false, 'text', 33, 'admin.settings.field.telegram', 'admin.settings.help.telegram', 'link', true),
            'chat_button' => self::field('contacts', false, 'switch', null, 'admin.settings.field.chat_button', 'admin.settings.help.chat_button', 'link'),
        ];
        foreach (Settings::SOCIAL_NETWORKS as $network) {
            $fields['social_' . $network] = self::field('social', false, 'url', SettingsService::MAX_URL, 'admin.settings.field.social_' . $network, null, 'link');
        }

        return $fields;
    }

    /**
     * The fields of $tab (a TABS name or IMAGES), in order.
     *
     * @return array<string, SettingsField>
     */
    public static function tab(string $tab): array
    {
        return array_filter([...self::fields(), ...self::contactFields()], static fn(array $field): bool => $field['tab'] === $tab);
    }

    /**
     * The form's values from stored settings: every field as the string its input shows ('1'/'' for switches).
     *
     * @return array<string, string>
     */
    public static function values(Settings $settings): array
    {
        $id = static fn(?int $id): string => $id === null ? '' : (string) $id;
        $values = [
            'site_name' => $settings->siteName ?? '',
            'site_short_name' => $settings->siteShortName ?? '',
            'theme_color' => $settings->themeColor ?? '',
            self::THEME_COLOR_ON => $settings->themeColor === null ? '' : '1',
            'meta_title' => $settings->metaTitle ?? '',
            'meta_description' => $settings->metaDescription ?? '',
            'title_pattern' => $settings->titlePattern ?? '',
            'hide_from_search' => $settings->hideFromSearch ? '1' : '',
            'phone' => $settings->phone ?? '',
            'email' => $settings->email ?? '',
            'whatsapp' => $settings->whatsapp ?? '',
            'viber' => $settings->viber ?? '',
            'telegram' => $settings->telegram === null ? '' : '@' . $settings->telegram,
            'chat_button' => $settings->chatButton ? '1' : '',
            'address' => $settings->address ?? '',
            'opening_hours' => $settings->openingHours ?? '',
            'yandex_verification' => $settings->yandexVerification ?? '',
            'google_verification' => $settings->googleVerification ?? '',
            'accessibility_toolbar' => $settings->accessibilityToolbar ? '1' : '',
            'accessibility_toolbar_position' => $settings->accessibilityToolbarPosition,
            'yandex_metrica' => $settings->yandexMetrica ?? '',
            'yandex_webvisor' => $settings->yandexWebvisor ? '1' : '',
            'google_analytics' => $settings->googleAnalytics ?? '',
            'consent_text' => $settings->consentText ?? '',
            'meta_pixel' => $settings->metaPixel ?? '',
            'google_ads' => $settings->googleAds ?? '',
            'vk_pixel' => $settings->vkPixel ?? '',
            'tiktok_pixel' => $settings->tiktokPixel ?? '',
            'logo_id' => $id($settings->logoId),
            'icon_id' => $id($settings->iconId),
            'og_image_id' => $id($settings->ogImageId),
        ];
        foreach (Settings::SOCIAL_NETWORKS as $network) {
            $values['social_' . $network] = '';
        }
        foreach ($settings->socialLinks as $link) {
            $values['social_' . $link->network] = $link->url;
        }

        return $values;
    }

    /**
     * The submitted values: every field as a string ('' when missing or not a string). With $contact the contact details
     * (contactFields()), else the Site settings screen's fields.
     *
     * @param array<mixed> $body
     *
     * @return array<string, string>
     */
    public static function submitted(array $body, bool $contact = false): array
    {
        $values = [];
        foreach ($contact ? array_keys(self::contactFields()) : [...array_keys(self::fields()), self::THEME_COLOR_ON] as $name) {
            $values[$name] = is_string($body[$name] ?? null) ? $body[$name] : '';
        }

        return $values;
    }

    /**
     * The media fields whose submitted value is not an id: "validation.field.media" before the service is asked.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    public static function invalidIds(array $values): array
    {
        $errors = [];
        foreach (self::tab(self::IMAGES) as $name => $field) {
            if (self::id($values[$name]) === false) {
                $errors[$name] = 'validation.field.media';
            }
        }

        return $errors;
    }

    /**
     * The input of the contact page's contact details (SettingsService::saveContacts() writes only those).
     *
     * @param array<string, string> $values submitted(…, true)
     */
    public static function contactInput(array $values): SettingsInput
    {
        return self::input([...self::values(new Settings()), ...$values]);
    }

    /**
     * @param array<string, string> $values every field's value (the Site settings screen passes the stored contact
     *                                      details with its submitted values), the ids valid (invalidIds() is empty)
     */
    public static function input(array $values): SettingsInput
    {
        $social = [];
        foreach (Settings::SOCIAL_NETWORKS as $network) {
            $social[$network] = $values['social_' . $network];
        }
        $id = static fn(string $value): ?int => is_int($parsed = self::id($value)) ? $parsed : null;

        return new SettingsInput(
            metaTitle: $values['meta_title'],
            metaDescription: $values['meta_description'],
            ogImageId: $id($values['og_image_id']),
            phone: $values['phone'],
            email: $values['email'],
            siteName: $values['site_name'],
            siteShortName: $values['site_short_name'],
            logoId: $id($values['logo_id']),
            iconId: $id($values['icon_id']),
            themeColor: $values[self::THEME_COLOR_ON] === '1' ? $values['theme_color'] : '',
            titlePattern: $values['title_pattern'],
            hideFromSearch: $values['hide_from_search'] === '1',
            whatsapp: $values['whatsapp'],
            viber: $values['viber'],
            telegram: $values['telegram'],
            chatButton: $values['chat_button'] === '1',
            address: $values['address'],
            openingHours: $values['opening_hours'],
            social: $social,
            yandexVerification: $values['yandex_verification'],
            googleVerification: $values['google_verification'],
            accessibilityToolbar: $values['accessibility_toolbar'] === '1',
            accessibilityToolbarPosition: $values['accessibility_toolbar_position'],
            yandexMetrica: $values['yandex_metrica'],
            yandexWebvisor: $values['yandex_webvisor'] === '1',
            googleAnalytics: $values['google_analytics'],
            consentText: $values['consent_text'],
            metaPixel: $values['meta_pixel'],
            googleAds: $values['google_ads'],
            vkPixel: $values['vk_pixel'],
            tiktokPixel: $values['tiktok_pixel'],
        );
    }

    /**
     * The error counts of the Site settings screen's tabs, or with $contact of CONTACT_TABS.
     *
     * @param array<string, string> $errors
     *
     * @return array<string, int> tab => the number of its fields' errors
     */
    public static function tabErrors(array $errors, bool $contact = false): array
    {
        $counts = array_fill_keys($contact ? self::CONTACT_TABS : self::TABS, 0);
        foreach ($contact ? self::contactFields() : self::fields() as $name => $field) {
            if (isset($errors[$name], $counts[$field['tab']])) {
                ++$counts[$field['tab']];
            }
        }

        return $counts;
    }

    /**
     * @return int|false|null null for '', false for anything but an id
     */
    private static function id(string $value): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return preg_match('/^[1-9]\d{0,9}$/', $value) === 1 ? (int) $value : false;
    }

    /**
     * @return SettingsField
     */
    private static function field(string $tab, bool $locale, string $kind, ?int $max, string $label, ?string $help, string $icon, bool $ltr = false): array
    {
        return ['tab' => $tab, 'locale' => $locale, 'kind' => $kind, 'max' => $max, 'label' => $label, 'help' => $help, 'icon' => $icon, 'ltr' => $ltr || in_array($kind, ['tel', 'email', 'url'], true)];
    }
}
