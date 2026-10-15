<?php

declare(strict_types=1);

namespace AtarCms\Media\Share;

use AtarCms\I18n\Bidi;
use RuntimeException;

/**
 * Text measuring and wrapping for the share card, with GD's FreeType metrics. Sizes are CSS pixels; GD takes points
 * at 96 dpi, so a size is multiplied by POINTS. Wrapping is greedy by words without hyphenation, in logical order: a
 * word wider than the box is cut with an ellipsis, and text beyond the last allowed line ends that line with one.
 * Characters the font has no glyph for (emoji, CJK, most symbols) are left out first (FontGlyphs), so GD draws no
 * boxes. GD draws a string left to right as it is, so place() reorders each line for right-to-left text (Bidi).
 */
final class TitleLines
{
    public const float POINTS = 0.75;

    public const string ELLIPSIS = '…';

    /**
     * @return list<string> the lines of $text (whitespace collapsed), at most $maxLines, each at most $width wide
     */
    public static function wrap(string $text, string $font, float $px, int $width, int $maxLines = 4): array
    {
        $words = preg_split('/\s+/u', FontGlyphs::clean($text, $font), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';
        $overflow = false;
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (self::width($candidate, $font, $px) <= $width) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            if (count($lines) === $maxLines) {
                $overflow = true;

                break;
            }
            $current = self::width($word, $font, $px) <= $width ? $word : self::fit($word, $font, $px, $width);
        }
        if (!$overflow && $current !== '') {
            $lines[] = $current;
        }
        if (count($lines) > $maxLines) {
            $overflow = true;
            $lines = array_slice($lines, 0, $maxLines);
        }
        if ($overflow && $lines !== []) {
            $lines[count($lines) - 1] = self::ellipsize($lines[count($lines) - 1], $font, $px, $width);
        }

        return $lines;
    }

    /**
     * The lines of wrap() as they are drawn: each in visual order (Bidi, in the direction $rtl, by default that of the text) and its
     * offset from the left of the box, 0 for a left-to-right text, else so that the line ends at the box's right edge.
     *
     * @return list<array{string, int}>
     */
    public static function place(string $text, string $font, float $px, int $width, int $maxLines = 4, ?bool $rtl = null): array
    {
        $rtl ??= Bidi::isRtl($text);
        $placed = [];
        foreach (self::wrap($text, $font, $px, $width, $maxLines) as $line) {
            $visual = Bidi::visual($line, $rtl);
            $placed[] = [$visual, $rtl ? max(0, $width - self::width($visual, $font, $px)) : 0];
        }

        return $placed;
    }

    /**
     * $text on one line: as it is when it fits $width, else shortened character by character and ended with "…".
     */
    public static function fit(string $text, string $font, float $px, int $width): string
    {
        $text = FontGlyphs::clean($text, $font);
        if (self::width($text, $font, $px) <= $width) {
            return $text;
        }
        $chars = mb_str_split($text);
        while ($chars !== []) {
            array_pop($chars);
            $candidate = rtrim(implode('', $chars)) . self::ELLIPSIS;
            if (self::width($candidate, $font, $px) <= $width) {
                return $candidate;
            }
        }

        return self::ELLIPSIS;
    }

    /**
     * The drawn width of $text in pixels (directional formatting characters are not drawn; the order does not matter).
     */
    public static function width(string $text, string $font, float $px): int
    {
        $text = Bidi::strip($text);
        if ($text === '') {
            return 0;
        }
        $box = imagettfbbox($px * self::POINTS, 0, $font, $text);
        if ($box === false) {
            throw new RuntimeException(sprintf('Cannot measure text with the font "%s"', $font));
        }

        /** @var array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int} $box */
        return max($box[2], $box[4]) - min($box[0], $box[6]);
    }

    /**
     * The last line of a cut text: whole words dropped from its end until "line…" fits (character by character for a
     * single word).
     */
    private static function ellipsize(string $line, string $font, float $px, int $width): string
    {
        $words = explode(' ', $line);
        while (count($words) > 1) {
            $candidate = self::trimEnd(implode(' ', $words)) . self::ELLIPSIS;
            if (self::width($candidate, $font, $px) <= $width) {
                return $candidate;
            }
            array_pop($words);
        }
        $word = $words[0];
        if (!str_ends_with($word, self::ELLIPSIS)) {
            $trimmed = self::trimEnd($word);
            $word = ($trimmed === '' ? $word : $trimmed) . self::ELLIPSIS;
        }

        // fit() drops characters from the end (the ellipsis first) and adds one again.
        return self::fit($word, $font, $px, $width);
    }

    /**
     * $text without the spaces and punctuation (dashes, the Hebrew maqaf) an ellipsis must not follow.
     */
    private static function trimEnd(string $text): string
    {
        return (string) preg_replace('/[\s,;:.\-–—…־]+$/u', '', $text);
    }
}
