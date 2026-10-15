<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use Closure;
use MessageFormatter;

/**
 * The plural forms of a message for a script, which cannot translate: the message for every plural category a whole
 * number takes in a locale, each formatted for the smallest such number with "#" in place of that number. The browser
 * picks the form with Intl.PluralRules(locale).select(n) and puts its number in place of "#" (the tree editor's
 * number of children, data-tree-counts).
 */
final class PluralForms
{
    /** Whole numbers up to this one are tried: every category a whole number takes in the CLDR rules shows below it. */
    private const int LIMIT = 200;

    private const string CATEGORY = '{n, plural, zero {zero} one {one} two {two} few {few} many {many} other {other}}';

    /**
     * @param Closure(int): string $format the message for a number, e.g. fn($n) => $translator->translate(..., ['count' => $n])
     *
     * @return array<string, string> category => message with "#" for the number, in the order of their smallest numbers
     */
    public static function of(string $locale, Closure $format): array
    {
        $forms = [];
        foreach (self::samples($locale) as $category => $number) {
            $text = $format($number);
            $forms[$category] = (string) preg_replace('/(?<!\d)' . $number . '(?!\d)/', '#', $text, 1);
        }

        return $forms;
    }

    /**
     * @return array<string, int> category => its smallest whole number
     */
    private static function samples(string $locale): array
    {
        $samples = [];
        $formatter = MessageFormatter::create($locale, self::CATEGORY);
        for ($number = 0; $number <= self::LIMIT; ++$number) {
            $category = $formatter->format(['n' => $number]);
            if (is_string($category) && !isset($samples[$category])) {
                $samples[$category] = $number;
            }
        }

        return $samples === [] ? ['other' => 0] : $samples;
    }
}
