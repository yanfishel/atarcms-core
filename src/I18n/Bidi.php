<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use IntlChar;

/**
 * The visual order of one line of mixed-direction text, for drawing it left to right where nothing else does (GD's
 * FreeType text in the share cards). A subset of the Unicode Bidirectional Algorithm (UAX #9): the paragraph direction
 * given by the caller (the text's language) or of the first strong character (P2, P3), boundary neutrals (soft hyphen,
 * zero-width joiners) removed (X9) and never drawn, isolates (LRI, RLI, FSI … PDI; the older embeddings and overrides are
 * ignored), the weak rules W1–W7, paired brackets N0, neutrals N1–N2, levels I1–I2, then L1, L2 and L4 (mirrored
 * glyphs); combining marks stay after their base character (L3). Character classes and mirrors come from ICU
 * (IntlChar); PHP has no bidi reordering of its own. Arabic letters are right to left but not shaped.
 */
final class Bidi
{
    private const int MAX_BRACKETS = 63;

    private const array NEUTRAL = ['B' => true, 'S' => true, 'WS' => true, 'ON' => true, 'LRI' => true, 'RLI' => true, 'FSI' => true, 'PDI' => true];

    private const array ISOLATE = ['LRI' => true, 'RLI' => true, 'FSI' => true, 'PDI' => true];

    /** Explicit embeddings and overrides (X9: removed, not supported) and the boundary neutrals. */
    private const array REMOVED = ['LRE' => true, 'RLE' => true, 'LRO' => true, 'RLO' => true, 'PDF' => true, 'BN' => true];

    /** The implicit marks: strong characters with no glyph. */
    private const array MARKS = [0x200E => true, 0x200F => true, 0x061C => true];

    /**
     * Whether the first strong character of $text (outside isolates) is right to left; false without one.
     */
    public static function isRtl(string $text): bool
    {
        $depth = 0;
        foreach (mb_str_split($text) as $char) {
            $type = self::type(mb_ord($char));
            if ($type === 'LRI' || $type === 'RLI' || $type === 'FSI') {
                $depth++;
            } elseif ($type === 'PDI') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $type === 'L') {
                return false;
            } elseif ($depth === 0 && ($type === 'R' || $type === 'AL')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $codepoint is a directional formatting character, never drawn: the marks, isolates, embeddings and
     * overrides.
     */
    public static function isControl(int $codepoint): bool
    {
        $type = self::type($codepoint);

        return isset(self::MARKS[$codepoint]) || isset(self::ISOLATE[$type]) || ($type !== 'BN' && isset(self::REMOVED[$type]));
    }

    /**
     * Whether $codepoint is a boundary neutral (BN: a soft hyphen, zero-width joiners, other format and control
     * characters), which the algorithm removes (X9) and nothing draws.
     */
    public static function isBoundaryNeutral(int $codepoint): bool
    {
        return self::type($codepoint) === 'BN';
    }

    /**
     * $text as it is drawn, in logical order: without its directional formatting characters and boundary neutrals.
     */
    public static function strip(string $text): string
    {
        $kept = '';
        foreach (mb_str_split($text) as $char) {
            $codepoint = mb_ord($char);
            if (!self::isControl($codepoint) && !self::isBoundaryNeutral($codepoint)) {
                $kept .= $char;
            }
        }

        return $kept;
    }

    /**
     * The line $text in the order its characters are drawn from left to right, with the mirrored glyphs of brackets in
     * right-to-left runs and without directional formatting characters.
     *
     * @param ?bool $rtl the paragraph direction (a wrapped line takes its paragraph's), by default the line's own
     */
    public static function visual(string $text, ?bool $rtl = null): string
    {
        $codepoints = [];
        $types = [];
        foreach (mb_str_split($text) as $char) {
            $codepoint = mb_ord($char);
            $type = self::type($codepoint);
            if (!isset(self::REMOVED[$type])) {
                $codepoints[] = $codepoint;
                $types[] = $type;
            }
        }
        if ($codepoints === []) {
            return '';
        }
        $base = ($rtl ?? self::isRtl($text)) ? 1 : 0;
        $levels = array_fill(0, count($codepoints), $base);
        self::resolve($codepoints, $types, array_keys($codepoints), $base, $levels);

        // L1: whitespace and isolate formatting characters at the end of the line take the paragraph level.
        for ($i = count($types) - 1; $i >= 0 && ($types[$i] === 'WS' || isset(self::ISOLATE[$types[$i]])); $i--) {
            $levels[$i] = $base;
        }

        return self::reorder($codepoints, $types, $levels);
    }

    /**
     * Resolves the levels of one isolating run sequence ($positions, at the embedding $level), its isolates first.
     *
     * @param list<int> $codepoints
     * @param list<string> $types
     * @param list<int> $positions
     * @param array<int, int> $levels
     */
    private static function resolve(array $codepoints, array $types, array $positions, int $level, array &$levels): void
    {
        $sequence = [];
        $count = count($positions);
        for ($i = 0; $i < $count; $i++) {
            $position = $positions[$i];
            $sequence[] = $position;
            $type = $types[$position];
            if ($type !== 'LRI' && $type !== 'RLI' && $type !== 'FSI') {
                continue;
            }
            $inner = [];
            $depth = 1;
            for ($i++; $i < $count; $i++) {
                $nested = $types[$positions[$i]];
                if ($nested === 'LRI' || $nested === 'RLI' || $nested === 'FSI') {
                    $depth++;
                } elseif ($nested === 'PDI' && --$depth === 0) {
                    $sequence[] = $positions[$i];

                    break;
                }
                $inner[] = $positions[$i];
            }
            if ($inner === []) {
                continue;
            }
            $innerRtl = match ($type) {
                'RLI' => true,
                'LRI' => false,
                default => self::isRtl(implode('', array_map(static fn(int $p): string => mb_chr($codepoints[$p]), $inner))),
            };
            $innerLevel = $level + ($innerRtl === ($level % 2 === 1) ? 2 : 1);
            foreach ($inner as $position) {
                $levels[$position] = $innerLevel;
            }
            self::resolve($codepoints, $types, $inner, $innerLevel, $levels);
        }

        $t = array_map(static fn(int $p): string => $types[$p], $sequence);
        $cps = array_map(static fn(int $p): int => $codepoints[$p], $sequence);
        $e = $level % 2 === 1 ? 'R' : 'L';
        $t = self::weak($t, $e);
        $t = self::brackets($t, $cps, $e, array_map(static fn(int $p): string => $types[$p], $sequence));
        $t = self::neutrals($t, $e);
        foreach ($sequence as $i => $position) {
            $levels[$position] = $level + self::raise($t[$i], $level % 2 === 1);
        }
    }

    /**
     * I1–I2: how much a resolved type raises its embedding level.
     */
    private static function raise(string $type, bool $odd): int
    {
        if ($odd) {
            return $type === 'L' || $type === 'EN' || $type === 'AN' ? 1 : 0;
        }

        return match ($type) {
            'R' => 1,
            'EN', 'AN' => 2,
            default => 0,
        };
    }

    /**
     * W1–W7 on the types of one isolating run sequence whose start and end are of the direction $e.
     *
     * @param array<int, string> $t
     *
     * @return array<int, string>
     */
    private static function weak(array $t, string $e): array
    {
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i] === 'NSM') {
                $previous = $i === 0 ? $e : $t[$i - 1];
                $t[$i] = isset(self::ISOLATE[$previous]) ? 'ON' : $previous;
            }
        }
        $strong = $e;
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i] === 'L' || $t[$i] === 'R' || $t[$i] === 'AL') {
                $strong = $t[$i];
            } elseif ($t[$i] === 'EN' && $strong === 'AL') {
                $t[$i] = 'AN';
            }
        }
        foreach ($t as $i => $type) {
            if ($type === 'AL') {
                $t[$i] = 'R';
            }
        }
        for ($i = 1; $i < $n - 1; $i++) {
            if ($t[$i] === 'ES' && $t[$i - 1] === 'EN' && $t[$i + 1] === 'EN') {
                $t[$i] = 'EN';
            } elseif ($t[$i] === 'CS' && $t[$i - 1] === $t[$i + 1] && ($t[$i - 1] === 'EN' || $t[$i - 1] === 'AN')) {
                $t[$i] = $t[$i - 1];
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i] !== 'ET') {
                continue;
            }
            $end = $i;
            while ($end < $n && $t[$end] === 'ET') {
                $end++;
            }
            if (($i > 0 && $t[$i - 1] === 'EN') || ($end < $n && $t[$end] === 'EN')) {
                for ($k = $i; $k < $end; $k++) {
                    $t[$k] = 'EN';
                }
            }
            $i = $end - 1;
        }
        foreach ($t as $i => $type) {
            if ($type === 'ES' || $type === 'ET' || $type === 'CS') {
                $t[$i] = 'ON';
            }
        }
        $strong = $e;
        foreach ($t as $i => $type) {
            if ($type === 'L' || $type === 'R') {
                $strong = $type;
            } elseif ($type === 'EN' && $strong === 'L') {
                $t[$i] = 'L';
            }
        }

        return $t;
    }

    /**
     * N0: a pair of brackets takes the embedding direction $e when it holds a strong character of that direction,
     * else the opposite one when it holds one and the context before it is opposite too.
     *
     * @param array<int, string> $t
     * @param list<int> $codepoints
     * @param list<string> $original the types before W1 (for the combining marks after a bracket)
     *
     * @return array<int, string>
     */
    private static function brackets(array $t, array $codepoints, string $e, array $original): array
    {
        $pairs = [];
        $stack = [];
        foreach ($t as $i => $type) {
            if ($type !== 'ON') {
                continue;
            }
            $bracket = (int) IntlChar::getIntPropertyValue($codepoints[$i], IntlChar::PROPERTY_BIDI_PAIRED_BRACKET_TYPE);
            if ($bracket === IntlChar::BPT_OPEN) {
                if (count($stack) === self::MAX_BRACKETS) {
                    // BD16: on overflow no brackets are paired at all.
                    $pairs = [];

                    break;
                }
                $stack[] = [(int) IntlChar::getBidiPairedBracket($codepoints[$i]), $i];
            } elseif ($bracket === IntlChar::BPT_CLOSE) {
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($stack[$k][0] === $codepoints[$i]) {
                        $pairs[] = [$stack[$k][1], $i];
                        $stack = array_slice($stack, 0, $k);

                        break;
                    }
                }
            }
        }
        usort($pairs, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $opposite = $e === 'L' ? 'R' : 'L';
        foreach ($pairs as [$open, $close]) {
            $inside = [];
            for ($k = $open + 1; $k < $close; $k++) {
                $strong = self::strong($t[$k]);
                if ($strong !== null) {
                    $inside[$strong] = true;
                }
            }
            if (isset($inside[$e])) {
                $direction = $e;
            } elseif (isset($inside[$opposite])) {
                $context = $e;
                for ($k = $open - 1; $k >= 0; $k--) {
                    $strong = self::strong($t[$k]);
                    if ($strong !== null) {
                        $context = $strong;

                        break;
                    }
                }
                $direction = $context === $opposite ? $opposite : $e;
            } else {
                continue;
            }
            foreach ([$open, $close] as $at) {
                $t[$at] = $direction;
                for ($k = $at + 1; $k < count($t) && $original[$k] === 'NSM'; $k++) {
                    $t[$k] = $direction;
                }
            }
        }

        return $t;
    }

    /**
     * N1–N2: a run of neutrals between characters of one direction (numbers count as right to left) takes it, else
     * the embedding direction $e.
     *
     * @param array<int, string> $t
     *
     * @return array<int, string>
     */
    private static function neutrals(array $t, string $e): array
    {
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            if (!isset(self::NEUTRAL[$t[$i]])) {
                continue;
            }
            $end = $i;
            while ($end < $n && isset(self::NEUTRAL[$t[$end]])) {
                $end++;
            }
            $before = $i === 0 ? $e : (self::strong($t[$i - 1]) ?? $e);
            $after = $end === $n ? $e : (self::strong($t[$end]) ?? $e);
            $direction = $before === $after ? $before : $e;
            for ($k = $i; $k < $end; $k++) {
                $t[$k] = $direction;
            }
            $i = $end - 1;
        }

        return $t;
    }

    /**
     * L2–L4: reverses the runs from the highest level down to the lowest odd one, keeping combining marks after their
     * base, mirrors the glyphs at odd levels and leaves out the formatting characters.
     *
     * @param list<int> $codepoints
     * @param list<string> $types
     * @param array<int, int> $levels
     */
    private static function reorder(array $codepoints, array $types, array $levels): string
    {
        $clusters = [];
        $clusterLevels = [];
        foreach ($codepoints as $i => $codepoint) {
            $last = count($clusters) - 1;
            if ($types[$i] === 'NSM' && $last >= 0) {
                $clusters[$last][] = $codepoint;
            } else {
                $clusters[] = [$codepoint];
                $clusterLevels[] = $levels[$i];
            }
        }
        $order = array_keys($clusters);
        $highest = (int) max([0, ...$clusterLevels]);
        $odd = array_filter($clusterLevels, static fn(int $level): bool => $level % 2 === 1);
        $lowestOdd = $odd === [] ? $highest + 1 : (int) min([...$odd, $highest]);
        $count = count($order);
        for ($level = $highest; $level >= $lowestOdd; $level--) {
            for ($i = 0; $i < $count; $i++) {
                if ($clusterLevels[$order[$i]] < $level) {
                    continue;
                }
                $end = $i;
                while ($end < $count && $clusterLevels[$order[$end]] >= $level) {
                    $end++;
                }
                array_splice($order, $i, $end - $i, array_reverse(array_slice($order, $i, $end - $i)));
                $i = $end;
            }
        }

        $visual = '';
        foreach ($order as $index) {
            $level = $clusterLevels[$index];
            foreach ($clusters[$index] as $k => $codepoint) {
                if (self::isControl($codepoint)) {
                    continue;
                }
                if ($k === 0 && $level % 2 === 1 && IntlChar::isMirrored($codepoint)) {
                    $codepoint = (int) IntlChar::charMirror($codepoint);
                }
                $visual .= mb_chr($codepoint);
            }
        }

        return $visual;
    }

    /**
     * The direction a resolved type counts as for N0 and N1 (numbers are right to left), null for a neutral.
     */
    private static function strong(string $type): ?string
    {
        return match ($type) {
            'L' => 'L',
            'R', 'AL', 'EN', 'AN' => 'R',
            default => null,
        };
    }

    private static function type(int $codepoint): string
    {
        return match (IntlChar::charDirection($codepoint)) {
            IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT => 'L',
            IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT => 'R',
            IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_ARABIC => 'AL',
            IntlChar::CHAR_DIRECTION_EUROPEAN_NUMBER => 'EN',
            IntlChar::CHAR_DIRECTION_EUROPEAN_NUMBER_SEPARATOR => 'ES',
            IntlChar::CHAR_DIRECTION_EUROPEAN_NUMBER_TERMINATOR => 'ET',
            IntlChar::CHAR_DIRECTION_ARABIC_NUMBER => 'AN',
            IntlChar::CHAR_DIRECTION_COMMON_NUMBER_SEPARATOR => 'CS',
            IntlChar::CHAR_DIRECTION_DIR_NON_SPACING_MARK => 'NSM',
            IntlChar::CHAR_DIRECTION_BOUNDARY_NEUTRAL => 'BN',
            IntlChar::CHAR_DIRECTION_BLOCK_SEPARATOR => 'B',
            IntlChar::CHAR_DIRECTION_SEGMENT_SEPARATOR => 'S',
            IntlChar::CHAR_DIRECTION_WHITE_SPACE_NEUTRAL => 'WS',
            IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT_EMBEDDING => 'LRE',
            IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT_OVERRIDE => 'LRO',
            IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_EMBEDDING => 'RLE',
            IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_OVERRIDE => 'RLO',
            IntlChar::CHAR_DIRECTION_POP_DIRECTIONAL_FORMAT => 'PDF',
            IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT_ISOLATE => 'LRI',
            IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_ISOLATE => 'RLI',
            IntlChar::CHAR_DIRECTION_FIRST_STRONG_ISOLATE => 'FSI',
            IntlChar::CHAR_DIRECTION_POP_DIRECTIONAL_ISOLATE => 'PDI',
            default => 'ON',
        };
    }
}
