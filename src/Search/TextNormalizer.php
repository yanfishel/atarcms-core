<?php

declare(strict_types=1);

namespace AtarCms\Search;

use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

/**
 * Turns text into the words the search index stores and queries match: lower-cased, "ё" read as "е", split on
 * anything that is not a Unicode letter or digit, and each word reduced to its Snowball stem ("новостями" →
 * "новост", "running" → "run", "Häuser" → "haus") when the locale's language has a stemmer (ru, en, de, fr); other
 * locales are not stemmed. The same class normalises indexed text and queries, so both sides always agree.
 */
final class TextNormalizer
{
    /** The languages with a stemmer: the part of a locale code before "-" or "_" ("ru-RU" → "ru"). */
    private const array STEMMED = ['ru', 'en', 'de', 'fr'];

    /**
     * @return list<string> the stems in text order, duplicates kept; none for invalid UTF-8
     */
    public function words(string $text, string $locale): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return [];
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', mb_strtolower($text, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return [];
        }
        $stemmer = self::stemmer($locale);
        if ($stemmer === null) {
            return $words;
        }

        return array_map(static fn(string $word): string => (string) $stemmer->stem($word), $words);
    }

    /**
     * The words of $text, separated by single spaces: the form stored in the search index.
     */
    public function normalize(string $text, string $locale): string
    {
        return implode(' ', $this->words($text, $locale));
    }

    private static function stemmer(string $locale): ?Stemmer
    {
        $language = strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        return in_array($language, self::STEMMED, true) ? StemmerFactory::create($language) : null;
    }
}
