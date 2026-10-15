<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Content\ValidationErrors;

/**
 * The normalisers of the site settings' values (SettingsService): each trims its value, returns null for an empty one
 * (the setting is removed) and adds a translation key to $errors for a value it refuses (text that is not UTF-8 too:
 * it is refused, never dropped).
 */
final class SettingsValues
{
    public const int MIN_PHONE_DIGITS = 7;

    public const int MAX_PHONE_DIGITS = 15;

    public const int MAX_VERIFICATION = 100;

    /**
     * Single-line text: control characters and whitespace collapsed to single spaces.
     */
    public static function line(string $value, int $max, string $field, ValidationErrors $errors): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors->add($field, 'validation.field.invalid');

            return $value;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $value));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors->add($field, 'validation.field.too_long', ['max' => $max]);
        }

        return $value;
    }

    /**
     * Plain multi-line text (an address, opening hours): line breaks kept as "\n", other control characters dropped,
     * each line trimmed, at most one empty line in a row.
     */
    public static function multiline(string $value, int $max, string $field, ValidationErrors $errors): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors->add($field, 'validation.field.invalid');

            return $value;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', '', $value);
        $lines = array_map(static fn(string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line)), explode("\n", $value));
        $value = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors->add($field, 'validation.field.too_long', ['max' => $max]);
        }

        return $value;
    }

    /**
     * A phone number for a messenger in international format (a leading "+", digits, spaces, "-", "(", ")"), or the
     * Russian and Kazakh trunk form "8" + ten digits (as "+7"); stored as "+" and its digits.
     */
    public static function phone(string $value, string $field, ValidationErrors $errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $value);
        // Without "+" only the Russian and Kazakh trunk form is understood: "8" and ten digits is "+7" and those ten.
        if (preg_match('/^[0-9 ()-]+$/', $value) === 1 && strlen($digits) === 11 && str_starts_with($digits, '8')) {
            return '+7' . substr($digits, 1);
        }
        if (preg_match('/^\+[0-9 ()-]+$/', $value) !== 1 || strlen($digits) < self::MIN_PHONE_DIGITS || strlen($digits) > self::MAX_PHONE_DIGITS) {
            $errors->add($field, 'validation.settings.phone');

            return $value;
        }

        return '+' . $digits;
    }

    /**
     * A Telegram username, "@name" or "name" (5 to 32 letters, digits or "_"); stored without "@".
     */
    public static function telegram(string $value, string $field, ValidationErrors $errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $name = str_starts_with($value, '@') ? substr($value, 1) : $value;
        if (preg_match('/^[A-Za-z0-9_]{5,32}$/D', $name) !== 1) {
            $errors->add($field, 'validation.settings.telegram');

            return $value;
        }

        return $name;
    }

    /**
     * An absolute https:// URL with a host (a social network page); nothing else is checked, so a network's new
     * domain still works.
     */
    public static function httpsUrl(string $value, int $max, string $field, ValidationErrors $errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors->add($field, 'validation.field.too_long', ['max' => $max]);

            return $value;
        }
        $parts = parse_url($value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            $errors->add($field, 'validation.settings.url');
        }

        return $value;
    }

    /**
     * The content of a search console's verification meta tag; a pasted whole <meta … content="…"> tag is reduced to
     * its content.
     */
    public static function verification(string $value, string $field, ValidationErrors $errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '<')) {
            $value = preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/i', $value, $match) === 1 ? trim($match[2]) : $value;
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,' . self::MAX_VERIFICATION . '}$/D', $value) !== 1) {
            $errors->add($field, 'validation.settings.verification');
        }

        return $value;
    }

    /**
     * A Yandex Metrica counter number: digits, no leading zero, at most 12.
     */
    public static function metrica(string $value, string $field, ValidationErrors $errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,11}$/D', $value) !== 1) {
            $errors->add($field, 'validation.settings.metrica');
        }

        return $value;
    }

    /**
     * A Google Analytics 4 measurement id, "G-" and 4 to 20 letters or digits; stored in upper case.
     */
    public static function googleAnalytics(string $value, string $field, ValidationErrors $errors): ?string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^G-[A-Z0-9]{4,20}$/D', $value) !== 1) {
            $errors->add($field, 'validation.settings.google_analytics');
        }

        return $value;
    }

    /**
     * A Meta Pixel id: digits, no leading zero, at most 20.
     */
    public static function metaPixel(string $value, string $field, ValidationErrors $errors): ?string
    {
        return self::matching(trim($value), '/^[1-9][0-9]{0,19}$/D', $field, 'validation.settings.meta_pixel', $errors);
    }

    /**
     * A Google Ads conversion id, "AW-" and 4 to 20 digits; stored in upper case.
     */
    public static function googleAds(string $value, string $field, ValidationErrors $errors): ?string
    {
        return self::matching(strtoupper(trim($value)), '/^AW-[0-9]{4,20}$/D', $field, 'validation.settings.google_ads', $errors);
    }

    /**
     * A VK Ads (Top@Mail.ru) pixel id: digits, no leading zero, at most 12.
     */
    public static function vkPixel(string $value, string $field, ValidationErrors $errors): ?string
    {
        return self::matching(trim($value), '/^[1-9][0-9]{0,11}$/D', $field, 'validation.settings.vk_pixel', $errors);
    }

    /**
     * A TikTok Pixel id: 10 to 30 letters or digits; stored in upper case.
     */
    public static function tiktokPixel(string $value, string $field, ValidationErrors $errors): ?string
    {
        return self::matching(strtoupper(trim($value)), '/^[A-Z0-9]{10,30}$/D', $field, 'validation.settings.tiktok_pixel', $errors);
    }

    /**
     * An id already normalised: null when empty, else itself, with $error at $field unless it matches $pattern.
     */
    private static function matching(string $value, string $pattern, string $field, string $error, ValidationErrors $errors): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match($pattern, $value) !== 1) {
            $errors->add($field, $error);
        }

        return $value;
    }

    /**
     * A colour "#rrggbb", stored in lower case.
     */
    public static function color(string $value, string $field, ValidationErrors $errors): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^#[0-9a-f]{6}$/D', $value) !== 1) {
            $errors->add($field, 'validation.settings.theme_color');
        }

        return $value;
    }

    /**
     * The corner of the accessibility toolbar's button (Settings::TOOLBAR_POSITIONS): null for '' and for the default,
     * which is not stored.
     */
    public static function toolbarPosition(string $value, string $field, ValidationErrors $errors): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === Settings::DEFAULT_TOOLBAR_POSITION) {
            return null;
        }
        if (!in_array($value, Settings::TOOLBAR_POSITIONS, true)) {
            $errors->add($field, 'validation.settings.toolbar_position');
        }

        return $value;
    }

    /**
     * How page titles are built: single-line text that holds "{title}".
     */
    public static function titlePattern(string $value, string $field, ValidationErrors $errors): ?string
    {
        $value = self::line($value, SettingsService::MAX_TITLE_PATTERN, $field, $errors);
        if ($value !== null && !str_contains($value, SiteSettings::TITLE)) {
            $errors->add($field, 'validation.settings.title_pattern');
        }

        return $value;
    }
}
