<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;

/**
 * How the admin's languages behave, a build choice of the site (locales.admin in config/locales.php, read at boot):
 * Linked (the default) makes the top bar's language menu the one switch, so the interface always speaks the content
 * language of the screen (LinkedLanguage); Separate keeps two independent choices, the interface language of the
 * Profile (users.locale, InterfaceLanguage) and the content language of the top bar (users.content_locale,
 * ContentLocale). A site with one language behaves as Separate whatever is set (LinkedLanguage::enabled()).
 */
enum LanguageMode: string
{
    case Linked = 'linked';
    case Separate = 'separate';

    /**
     * @throws ConfigException unless locales.admin is missing, "linked" or "separate"
     */
    public static function fromConfig(Config $config): self
    {
        $value = $config->get('locales.admin', self::Linked->value);
        $mode = is_string($value) ? self::tryFrom($value) : null;

        return $mode ?? throw new ConfigException(sprintf(
            'locales.admin must be "linked" or "separate"; got %s',
            is_string($value) ? '"' . $value . '"' : get_debug_type($value),
        ));
    }
}
