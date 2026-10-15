<?php

declare(strict_types=1);

namespace AtarCms\Theme\Check;

/**
 * The guard of the stylesheets that lay pages out in both directions (the admin's admin.css, the themes' CSS through
 * theme:check): which declarations name a physical side (and break a right-to-left page) and which mirror something.
 */
final class LogicalCss
{
    /**
     * Selectors of the rich-text editor's explicit sides ("align left" stays left in Hebrew text): physical on purpose.
     */
    public const string EDITOR_SIDES = '/(?:text-align|image-style-align|table-style-align|table-style-block-align)-(?:left|right)\b/';

    /**
     * The rules of a stylesheet without its comments: [selector, body] pairs (nested at-rules give their inner rules).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function rules(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        return array_map(static fn(array $match): array => [trim($match[1]), $match[2]], $matches);
    }

    /**
     * The declarations of a rule's body as [property, value] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function declarations(string $body): array
    {
        $declarations = [];
        foreach (explode(';', $body) as $declaration) {
            if (str_contains($declaration, ':')) {
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                $declarations[] = [$property, $value];
            }
        }

        return $declarations;
    }

    public static function isFlip(string $property, string $value): bool
    {
        return ($property === 'transform' && preg_match('/scaleX\(\s*-|scale\(\s*-|matrix(?:3d)?\(|rotateY\(/', $value) === 1)
            || ($property === 'scale' && str_starts_with(trim($value), '-'));
    }

    public static function isPhysical(string $property, string $value): bool
    {
        $value = trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value));
        if (in_array($property, ['justify-content', 'justify-self', 'justify-items'], true) && preg_match('/\b(?:left|right)\b/', $value) === 1) {
            return true;
        }
        if (preg_match('/^(?:(?:margin|padding|border|scroll-margin|scroll-padding)-(?:left|right)(?:-|$)|(?:left|right)$|border-(?:top|bottom)-(?:left|right)-radius$)/', $property) === 1) {
            return true;
        }
        if (in_array($property, ['text-align', 'float', 'clear'], true) && in_array($value, ['left', 'right'], true)) {
            return true;
        }
        if (str_starts_with($property, 'background') && preg_match('/\b(?:left|right)\b/', $value) === 1) {
            return true;
        }
        $values = self::values($value);
        if (in_array($property, ['margin', 'padding', 'inset', 'scroll-margin', 'scroll-padding', 'border-width', 'border-color', 'border-style'], true)) {
            return count($values) === 4 && $values[1] !== $values[3];
        }
        if ($property === 'border-radius' && !str_contains($value, '/')) {
            // Corners: top-left, top-right, bottom-right, bottom-left; mirrored, the left ones swap with the right.
            [$topLeft, $topRight, $bottomRight, $bottomLeft] = match (count($values)) {
                1 => [$values[0], $values[0], $values[0], $values[0]],
                2 => [$values[0], $values[1], $values[0], $values[1]],
                3 => [$values[0], $values[1], $values[2], $values[1]],
                default => $values,
            };

            return $topLeft !== $topRight || $bottomLeft !== $bottomRight;
        }

        return false;
    }

    /**
     * The space-separated values of a declaration, a calc() or var() with its spaces counting as one.
     *
     * @return list<string>
     */
    public static function values(string $value): array
    {
        $values = [];
        $current = '';
        $depth = 0;
        foreach (str_split($value) as $character) {
            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0 && ctype_space($character)) {
                if ($current !== '') {
                    $values[] = $current;
                }
                $current = '';
                continue;
            }
            $current .= $character;
        }
        if ($current !== '') {
            $values[] = $current;
        }

        return $values;
    }
}
