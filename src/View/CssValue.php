<?php

declare(strict_types=1);

namespace AtarCms\View;

/**
 * The CSS values stored rich text may carry in a style attribute (StyleAttributeSanitizer) and a theme's editor
 * palette and fonts may declare (EditorSettings): lengths, colours, a few keywords, borders and font families, never
 * a function that loads or computes anything (url(), var(), calc() …). Keywords, units and function names match in
 * any letter case; values keep their own. normalize() gives a value in its stored form (colour functions written
 * compactly, font families as the editor writes them), or null when the property does not take it.
 */
final class CssValue
{
    /** The 148 named colours of CSS Color 4. */
    public const array NAMED_COLORS = [
        'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black', 'blanchedalmond', 'blue',
        'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse', 'chocolate', 'coral', 'cornflowerblue', 'cornsilk',
        'crimson', 'cyan', 'darkblue', 'darkcyan', 'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey', 'darkkhaki',
        'darkmagenta', 'darkolivegreen', 'darkorange', 'darkorchid', 'darkred', 'darksalmon', 'darkseagreen',
        'darkslateblue', 'darkslategray', 'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink', 'deepskyblue',
        'dimgray', 'dimgrey', 'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen', 'fuchsia', 'gainsboro',
        'ghostwhite', 'gold', 'goldenrod', 'gray', 'green', 'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred',
        'indigo', 'ivory', 'khaki', 'lavender', 'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue', 'lightcoral',
        'lightcyan', 'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey', 'lightpink', 'lightsalmon',
        'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey', 'lightsteelblue', 'lightyellow', 'lime',
        'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine', 'mediumblue', 'mediumorchid', 'mediumpurple',
        'mediumseagreen', 'mediumslateblue', 'mediumspringgreen', 'mediumturquoise', 'mediumvioletred', 'midnightblue',
        'mintcream', 'mistyrose', 'moccasin', 'navajowhite', 'navy', 'oldlace', 'olive', 'olivedrab', 'orange',
        'orangered', 'orchid', 'palegoldenrod', 'palegreen', 'paleturquoise', 'palevioletred', 'papayawhip', 'peachpuff',
        'peru', 'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple', 'red', 'rosybrown', 'royalblue', 'saddlebrown',
        'salmon', 'sandybrown', 'seagreen', 'seashell', 'sienna', 'silver', 'skyblue', 'slateblue', 'slategray',
        'slategrey', 'snow', 'springgreen', 'steelblue', 'tan', 'teal', 'thistle', 'tomato', 'turquoise', 'violet',
        'wheat', 'white', 'whitesmoke', 'yellow', 'yellowgreen',
    ];

    /** At most this many families in one font-family value. */
    public const int MAX_FAMILIES = 8;

    private const string NUMBER = '\d{1,6}(?:\.\d{1,4})?';

    private const array TEXT_ALIGN = ['left', 'right', 'center', 'justify', 'start', 'end'];

    private const array VERTICAL_ALIGN = ['top', 'middle', 'bottom', 'baseline'];

    private const array BORDER_STYLES = ['none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'];

    private const array BORDER_WIDTHS = ['thin', 'medium', 'thick'];

    /**
     * The value of $property in its stored form (trimmed, whitespace runs as one space, no space around commas or
     * inside parentheses, font families as the editor writes them), or null when the property is unknown or does not
     * take the value.
     */
    public static function normalize(string $property, string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if ($value === '') {
            return null;
        }
        if ($property === 'font-family') {
            return self::fontFamily($value);
        }
        // Colour functions without spaces around their commas and inside their parentheses, as the editor reads
        // them back ("hsl(0,0%,90%)"), so a stored value survives the next save unchanged.
        $value = (string) preg_replace(['/ ?, ?/', '/\( /', '/ \)/'], [',', '(', ')'], $value);
        $side = preg_match('/^border-(?:top|right|bottom|left)(-color|-style|-width)?$/D', $property, $match) === 1 ? ($match[1] ?? '') : null;
        $valid = match (true) {
            $property === 'width', $property === 'height' => self::isLength($value, true) || strcasecmp($value, 'auto') === 0,
            $property === 'aspect-ratio' => preg_match('~^(?:\d{1,5} ?/ ?\d{1,5}|' . self::NUMBER . ')$~D', $value) === 1,
            $property === 'color', $property === 'background-color', $side === '-color' => self::isColor($value),
            $property === 'text-align' => self::isKeyword($value, self::TEXT_ALIGN),
            $property === 'vertical-align' => self::isKeyword($value, self::VERTICAL_ALIGN),
            $property === 'padding' => self::repeats($value, static fn(string $part): bool => self::isLength($part, false)),
            $property === 'border-color' => self::repeats($value, self::isColor(...)),
            $property === 'border-style' => self::repeats($value, self::isBorderStyle(...)),
            $property === 'border-width' => self::repeats($value, self::isBorderWidth(...)),
            $side === '-style' => self::isBorderStyle($value),
            $side === '-width' => self::isBorderWidth($value),
            $property === 'border', $side === '' => self::isBorder($value),
            default => false,
        };

        return $valid ? $value : null;
    }

    public static function isColor(string $value): bool
    {
        $number = self::NUMBER;
        $part = $number . '%?';
        $patterns = [
            '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD',
            "/^rgba?\\( ?{$part} ?, ?{$part} ?, ?{$part}(?: ?, ?{$part})? ?\\)$/iD",
            "/^rgba?\\( ?{$part} {$part} {$part}(?: ?\\/ ?{$part})? ?\\)$/iD",
            "/^hsla?\\( ?{$number}(?:deg)? ?, ?{$number}% ?, ?{$number}%(?: ?, ?{$part})? ?\\)$/iD",
            "/^hsla?\\( ?{$number}(?:deg)? {$number}% {$number}%(?: ?\\/ ?{$part})? ?\\)$/iD",
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return self::isKeyword($value, [...self::NAMED_COLORS, 'transparent', 'currentcolor']);
    }

    /**
     * A font-family value as the editor writes it: quotes stripped, names that contain a space in single quotes,
     * joined with ", "; null unless it lists 1 to MAX_FAMILIES names of letters, digits, spaces, "_" and "-" (64
     * characters at most each), each optionally in quotes.
     */
    public static function fontFamily(string $value): ?string
    {
        $families = explode(',', $value);
        if (count($families) > self::MAX_FAMILIES) {
            return null;
        }
        $normalized = [];
        foreach ($families as $family) {
            $family = trim((string) preg_replace('/\s+/u', ' ', $family));
            if (preg_match('/^([\'"])(.*)\1$/sD', $family, $quoted) === 1) {
                $family = trim($quoted[2]);
            }
            if (preg_match('/^[\p{L}\p{N} _-]{1,64}$/uD', $family) !== 1) {
                return null;
            }
            $normalized[] = str_contains($family, ' ') ? "'" . $family . "'" : $family;
        }

        return implode(', ', $normalized);
    }

    /**
     * 0 or a number with px, em, rem, pt, vw, vh (and % when $percent).
     */
    private static function isLength(string $value, bool $percent): bool
    {
        $units = $percent ? 'px|%|em|rem|pt|vw|vh' : 'px|em|rem|pt|vw|vh';

        return preg_match('/^(?:0|' . self::NUMBER . '(?:' . $units . '))$/iD', $value) === 1;
    }

    private static function isBorderStyle(string $value): bool
    {
        return self::isKeyword($value, self::BORDER_STYLES);
    }

    private static function isBorderWidth(string $value): bool
    {
        return self::isLength($value, false) || self::isKeyword($value, self::BORDER_WIDTHS);
    }

    /**
     * A border shorthand: one to three parts, at most one width, one style and one colour.
     */
    private static function isBorder(string $value): bool
    {
        $parts = self::parts($value);
        if ($parts === null || count($parts) > 3) {
            return false;
        }
        $seen = [];
        foreach ($parts as $part) {
            $kind = match (true) {
                self::isBorderWidth($part) => 'width',
                self::isBorderStyle($part) => 'style',
                self::isColor($part) => 'color',
                default => null,
            };
            if ($kind === null || isset($seen[$kind])) {
                return false;
            }
            $seen[$kind] = true;
        }

        return true;
    }

    /**
     * One to four space-separated values (spaces inside parentheses do not separate), each accepted by $accepts.
     *
     * @param callable(string): bool $accepts
     */
    private static function repeats(string $value, callable $accepts): bool
    {
        $parts = self::parts($value);
        if ($parts === null || count($parts) > 4) {
            return false;
        }
        foreach ($parts as $part) {
            if (!$accepts($part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The space-separated parts of a value whose whitespace is already collapsed, keeping "hsl(0, 0%, 0%)" whole;
     * null when its parentheses do not balance.
     *
     * @return list<string>|null
     */
    private static function parts(string $value): ?array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        foreach (str_split($value) as $character) {
            if ($character === ' ' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth < 0 || $depth > 1) {
                return null;
            }
            $current .= $character;
        }

        return $depth === 0 ? [...$parts, $current] : null;
    }

    /**
     * @param list<string> $keywords lower-case
     */
    private static function isKeyword(string $value, array $keywords): bool
    {
        return in_array(strtolower($value), $keywords, true);
    }
}
