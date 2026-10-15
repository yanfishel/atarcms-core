<?php

declare(strict_types=1);

namespace AtarCms\I18n;

/**
 * The writing direction of a locale, by its primary language subtag ("he-il" → "he"): the languages written right to
 * left are listed, everything else is left to right. Templates never test a locale themselves; they print
 * `langAttrs()`, which uses this.
 */
final class TextDirection
{
    private const array RTL = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'iw', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];

    /**
     * @return 'ltr'|'rtl'
     */
    public static function of(string $locale): string
    {
        $primary = strtolower(explode('-', str_replace('_', '-', $locale), 2)[0]);

        return in_array($primary, self::RTL, true) ? 'rtl' : 'ltr';
    }
}
