<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use AtarCms\View\EditorLanguages;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The attributes of rich text that take a fixed set of values: list styles, numbering, the "open in a new tab" link
 * attributes, language marks and to-do checkboxes. A value outside its rule drops the attribute. Boolean attributes
 * are written with their own name as the value (checked="checked"), as the editor writes them; rel keeps only
 * "noopener" and "noreferrer", in that order, as the editor's link decorator writes it.
 */
final class KeywordAttributeSanitizer implements AttributeSanitizerInterface
{
    private const string BOOLEAN = 'boolean';

    private const string INTEGER = 'integer';

    private const string REL = 'rel';

    private const string LANGUAGE = 'language';

    /** element => attribute => the allowed values, or the kind of value. */
    private const array RULES = [
        'ol' => ['type' => ['1', 'a', 'A', 'i', 'I'], 'start' => self::INTEGER, 'reversed' => self::BOOLEAN],
        'ul' => ['type' => ['disc', 'circle', 'square']],
        'a' => ['target' => ['_blank'], 'rel' => self::REL],
        'span' => ['lang' => self::LANGUAGE, 'dir' => ['ltr', 'rtl']],
        'input' => ['type' => ['checkbox'], 'checked' => self::BOOLEAN, 'disabled' => self::BOOLEAN],
    ];

    private const array REL_TOKENS = ['noopener', 'noreferrer'];

    public function getSupportedElements(): array
    {
        return array_keys(self::RULES);
    }

    public function getSupportedAttributes(): array
    {
        return array_values(array_unique(array_merge(...array_values(array_map(array_keys(...), self::RULES)))));
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $rule = self::RULES[$element][$attribute] ?? null;

        return match (true) {
            is_array($rule) => in_array($value, $rule, true) ? $value : null,
            $rule === self::BOOLEAN => $attribute,
            $rule === self::INTEGER => preg_match('/^\d{1,6}$/D', $value) === 1 ? (string) (int) $value : null,
            $rule === self::LANGUAGE => isset(EditorLanguages::ALL[$value]) ? $value : null,
            $rule === self::REL => self::rel($value),
            default => null,
        };
    }

    private static function rel(string $value): ?string
    {
        $tokens = preg_split('/[\t\n\f\r ]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_values(array_intersect(self::REL_TOKENS, $tokens));

        return $kept === [] ? null : implode(' ', $kept);
    }
}
