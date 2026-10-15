<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use AtarCms\View\CssValue;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The inline styles the admin editor writes where it cannot use a class (font colour and family, image and table
 * sizes, table borders and cell properties), each element with its own properties and every value checked by
 * CssValue. A style that could load or evaluate anything (url(), expression, escapes, comments, at-rules, "!" …) is
 * refused whole; a declaration of another property or with an invalid value is dropped alone. The kept declarations
 * are written as "prop: value; prop: value" (a repeated property keeps its first place and its last valid value);
 * nothing left drops the attribute. Sanitizing its own output returns it unchanged. A list of font families keeps
 * only those (the theme's fonts); null keeps every family of valid syntax.
 */
final class StyleAttributeSanitizer implements AttributeSanitizerInterface
{
    public const int MAX_LENGTH = 1024;

    public const int MAX_DECLARATIONS = 16;

    private const array BORDER = [
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-style', 'border-width',
        'border-top-color', 'border-top-style', 'border-top-width', 'border-right-color', 'border-right-style', 'border-right-width',
        'border-bottom-color', 'border-bottom-style', 'border-bottom-width', 'border-left-color', 'border-left-style', 'border-left-width',
    ];

    /** The properties each element may keep. */
    public const array PROPERTIES = [
        'span' => ['color', 'background-color', 'font-family'],
        'figure' => ['width', 'height'],
        'img' => ['width', 'height', 'aspect-ratio'],
        'table' => ['background-color', ...self::BORDER],
        'td' => ['width', 'height', 'padding', 'text-align', 'vertical-align', 'background-color', ...self::BORDER],
        'th' => ['width', 'height', 'padding', 'text-align', 'vertical-align', 'background-color', ...self::BORDER],
        'col' => ['width'],
    ];

    /** What no stored style may contain (the HTML parser has decoded entities already): escapes, comments, at-rules, "!", braces, angle brackets, control characters other than whitespace and every function that loads or computes. */
    private const string REFUSED = '~\\\\|/\*|\*/|[<>@!{}]|(?![\t\n\f\r])\p{Cc}|url\(|expression|image-set|var\(|attr\(|env\(|calc\(|src\(~iu';

    /** @var array<string, true>|null */
    private readonly ?array $fontFamilies;

    /**
     * @param list<string>|null $fontFamilies the font-family values to keep (normalized); null keeps any valid one
     * @param array<string, list<string>> $properties the properties each element may keep (a stricter profile passes fewer)
     */
    public function __construct(?array $fontFamilies, private readonly array $properties = self::PROPERTIES)
    {
        $this->fontFamilies = $fontFamilies === null ? null : array_fill_keys($fontFamilies, true);
    }

    public function getSupportedElements(): array
    {
        return array_keys($this->properties);
    }

    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $allowed = $this->properties[$element] ?? [];
        if ($allowed === [] || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH || preg_match(self::REFUSED, $value) !== 0) {
            return null;
        }
        $kept = [];
        foreach (explode(';', $value) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            if (preg_match('/^[a-z-]+$/D', $property) !== 1 || !in_array($property, $allowed, true)) {
                continue;
            }
            $normalized = CssValue::normalize($property, substr($declaration, $colon + 1));
            if ($normalized === null || ($property === 'font-family' && $this->fontFamilies !== null && !isset($this->fontFamilies[$normalized]))) {
                continue;
            }
            if (isset($kept[$property]) || count($kept) < self::MAX_DECLARATIONS) {
                $kept[$property] = $normalized;
            }
        }
        $declarations = [];
        foreach ($kept as $property => $normalized) {
            $declarations[] = $property . ': ' . $normalized;
        }

        return $declarations === [] ? null : implode('; ', $declarations);
    }
}
