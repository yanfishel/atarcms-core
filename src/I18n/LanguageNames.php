<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use AtarCms\View\EditorLanguages;
use Locale;

/**
 * A language named in itself ("English", "Русский", "עברית"), the same in every interface language, so a language is
 * found by its own name: the editor's fixed list first (it does not depend on the ICU data of the host), then ICU,
 * else the upper-cased code.
 */
final class LanguageNames
{
    public static function endonym(string $code): string
    {
        if (isset(EditorLanguages::ALL[$code])) {
            return EditorLanguages::ALL[$code][0];
        }
        $name = Locale::getDisplayLanguage($code, $code);
        // ICU answers an unknown code with the code itself.
        if ($name === false || strtolower($name) === strtolower($code)) {
            return mb_strtoupper($code);
        }

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }
}
