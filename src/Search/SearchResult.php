<?php

declare(strict_types=1);

namespace AtarCms\Search;

use AtarCms\Content\Entry;

/**
 * One hit on the search page: the entry, its URL and a short description for search.php.
 */
final readonly class SearchResult
{
    public const int DESCRIPTION_LENGTH = 200;

    public function __construct(
        public Entry $entry,
        public string $url,
        public string $description,
    ) {}

    /**
     * The entry's meta description, or else the start of its first non-empty text field (EntryText); '' without either.
     */
    public static function describe(Entry $entry): string
    {
        $meta = trim($entry->seo->metaDescription ?? '');

        return $meta !== '' ? $meta : self::excerpt(EntryText::of($entry)[0] ?? '', self::DESCRIPTION_LENGTH);
    }

    /**
     * $text with whitespace collapsed; when longer than $length characters, cut at the last word boundary within
     * them (inside a word only when the first word is longer) and ended with "…".
     */
    public static function excerpt(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        // One character more shows whether the cut falls right before a space.
        $head = mb_substr($text, 0, $length + 1);
        $space = mb_strrpos($head, ' ');
        $cut = $space === false ? mb_substr($text, 0, $length) : mb_substr($head, 0, $space);

        return preg_replace('/[\s,.;:!?\-–—]+$/u', '', $cut) . '…';
    }
}
