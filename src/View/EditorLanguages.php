<?php

declare(strict_types=1);

namespace AtarCms\View;

/**
 * The languages the admin editor can mark a phrase in (<span lang dir>), a fixed list independent of the site's
 * locales: code => [the language's name in itself, text direction]. The names are shown as they are in every
 * interface language, so a language is found by its own name.
 */
final class EditorLanguages
{
    /** @var array<string, array{string, 'ltr'|'rtl'}> */
    public const array ALL = [
        'en' => ['English', 'ltr'],
        'ru' => ['Русский', 'ltr'],
        'uk' => ['Українська', 'ltr'],
        'de' => ['Deutsch', 'ltr'],
        'fr' => ['Français', 'ltr'],
        'es' => ['Español', 'ltr'],
        'it' => ['Italiano', 'ltr'],
        'pt' => ['Português', 'ltr'],
        'pl' => ['Polski', 'ltr'],
        'cs' => ['Čeština', 'ltr'],
        'zh' => ['中文', 'ltr'],
        'ja' => ['日本語', 'ltr'],
        'ar' => ['العربية', 'rtl'],
        'he' => ['עברית', 'rtl'],
    ];
}
