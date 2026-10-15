<?php

declare(strict_types=1);

namespace AtarCms\Media\Share;

use AtarCms\I18n\Bidi;
use RuntimeException;

/**
 * Which characters a TrueType font has glyphs for, read from its cmap table (subtable formats 4 and 12, the Unicode
 * ones). GD draws a box for a character the font lacks (an emoji, CJK, most symbols), so the share card leaves such
 * characters out. A font's map is read once per process: the vendored font never changes while PHP runs.
 */
final class FontGlyphs
{
    /** Characters drawn as a look-alike when the font has no glyph for them: the Hebrew geresh and gershayim. */
    private const array FALLBACKS = [0x05F3 => "'", 0x05F4 => '"'];

    /** @var array<string, list<array{int, int, bool}>> font path => ranges [first, last, every glyph mapped] */
    private static array $ranges = [];

    /** @var array<string, array<int, bool>> font path => code point => has a glyph (format 4 holes) */
    private static array $holes = [];

    public static function has(string $font, int $codepoint): bool
    {
        if (!isset(self::$ranges[$font])) {
            self::load($font);
        }
        foreach (self::$ranges[$font] as [$first, $last, $full]) {
            if ($codepoint >= $first && $codepoint <= $last) {
                return $full || (self::$holes[$font][$codepoint] ?? false);
            }
        }

        return false;
    }

    /**
     * $text without the characters $font has no glyph for, whitespace collapsed and trimmed. Directional formatting
     * characters (marks, isolates) stay for the bidi step, which leaves them out of the drawn text; text of nothing
     * else is empty. Boundary neutrals (soft hyphens, zero-width joiners) are dropped without a space, the Hebrew
     * geresh and gershayim become ' and " when the font lacks them, and invalid UTF-8 is scrubbed.
     */
    public static function clean(string $text, string $font): string
    {
        $kept = '';
        $visible = false;
        foreach (mb_str_split(mb_scrub($text, 'UTF-8')) as $char) {
            $codepoint = mb_ord($char);
            if (Bidi::isBoundaryNeutral($codepoint)) {
                continue;
            }
            if (isset(self::FALLBACKS[$codepoint]) && !self::has($font, $codepoint)) {
                $char = self::FALLBACKS[$codepoint];
                $codepoint = mb_ord($char);
            }
            if (Bidi::isControl($codepoint) || preg_match('/^\s$/u', $char) === 1) {
                $kept .= $char;
            } elseif (self::has($font, $codepoint)) {
                $kept .= $char;
                $visible = true;
            } else {
                $kept .= ' ';
            }
        }

        return $visible ? trim((string) preg_replace('/\s+/u', ' ', $kept)) : '';
    }

    private static function load(string $font): void
    {
        $data = @file_get_contents($font);
        if ($data === false) {
            throw new RuntimeException(sprintf('Cannot read the font "%s"', $font));
        }
        $tables = self::u16($data, 4);
        $cmap = null;
        for ($i = 0; $i < $tables; $i++) {
            $record = 12 + 16 * $i;
            if (substr($data, $record, 4) === 'cmap') {
                $cmap = self::u32($data, $record + 8);
            }
        }
        if ($cmap === null) {
            throw new RuntimeException(sprintf('The font "%s" has no cmap table', $font));
        }
        $ranges = [];
        $holes = [];
        $best = null;
        for ($i = 0, $count = self::u16($data, $cmap + 2); $i < $count; $i++) {
            $offset = $cmap + self::u32($data, $cmap + 4 + 8 * $i + 4);
            $format = self::u16($data, $offset);
            // Format 12 covers everything format 4 does and more: prefer it.
            if ($format === 12 || ($format === 4 && $best === null)) {
                $best = [$format, $offset];
            }
        }
        if ($best === null) {
            throw new RuntimeException(sprintf('The font "%s" has no Unicode cmap subtable', $font));
        }
        [$format, $offset] = $best;
        if ($format === 12) {
            for ($i = 0, $groups = self::u32($data, $offset + 12); $i < $groups; $i++) {
                $group = $offset + 16 + 12 * $i;
                $ranges[] = [self::u32($data, $group), self::u32($data, $group + 4), true];
            }
        } else {
            $segments = intdiv(self::u16($data, $offset + 6), 2);
            $ends = $offset + 14;
            $starts = $ends + 2 * $segments + 2;
            $deltas = $starts + 2 * $segments;
            $rangeOffsets = $deltas + 2 * $segments;
            for ($s = 0; $s < $segments; $s++) {
                $end = self::u16($data, $ends + 2 * $s);
                $start = self::u16($data, $starts + 2 * $s);
                $rangeOffset = self::u16($data, $rangeOffsets + 2 * $s);
                if ($start === 0xFFFF) {
                    continue;
                }
                if ($rangeOffset === 0) {
                    $ranges[] = [$start, $end, true];

                    continue;
                }
                $ranges[] = [$start, $end, false];
                for ($c = $start; $c <= $end; $c++) {
                    $glyph = self::u16($data, $rangeOffsets + 2 * $s + $rangeOffset + 2 * ($c - $start));
                    $holes[$c] = $glyph !== 0;
                }
            }
        }
        self::$ranges[$font] = $ranges;
        self::$holes[$font] = $holes;
    }

    private static function u16(string $data, int $at): int
    {
        $value = unpack('n', $data, $at);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : throw new RuntimeException('Truncated font file');
    }

    private static function u32(string $data, int $at): int
    {
        $value = unpack('N', $data, $at);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : throw new RuntimeException('Truncated font file');
    }
}
