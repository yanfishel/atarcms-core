<?php

declare(strict_types=1);

namespace AtarCms\I18n;

/**
 * The languages the admin interface speaks: the core catalogs (resources/lang/{code}.php), independent of the site's
 * content locales. A user's interface language (users.locale) is one of them.
 */
final class InterfaceLanguages
{
    /** @var list<string> */
    public const array ALL = ['en', 'ru', 'he', 'de', 'fr'];

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::ALL, true);
    }
}
