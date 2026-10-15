<?php

declare(strict_types=1);

namespace AtarCms\Content;

use Transliterator;

/**
 * URL slugs: lowercase ASCII words joined by single hyphens.
 */
final class Slug
{
    public const string PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** Slugs joined by "/": the nested path of a subpage or of a section ("about/team"). */
    public const string PATH_PATTERN = self::PATTERN . '(?:/' . self::PATTERN . ')*';

    public const int MAX_LENGTH = 190;

    public static function isValid(string $slug): bool
    {
        return strlen($slug) <= self::MAX_LENGTH && preg_match('/^' . self::PATTERN . '$/', $slug) === 1;
    }

    /**
     * Transliterates text (e.g. a title) to a slug; returns '' when nothing usable is left.
     */
    public static function fromText(string $text): string
    {
        $ascii = Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($text);
        if (!is_string($ascii)) {
            $ascii = strtolower($text);
        }
        // Transliterated soft and hard signs become quotes; drop them instead of splitting the word.
        $ascii = str_replace(["'", '"', '`'], '', $ascii);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return $slug;
    }

    /**
     * "about" + 2 → "about-2", shortening the slug so the result stays within MAX_LENGTH.
     */
    public static function withSuffix(string $slug, int $n): string
    {
        $suffix = '-' . $n;

        return rtrim(substr($slug, 0, self::MAX_LENGTH - strlen($suffix)), '-') . $suffix;
    }
}
