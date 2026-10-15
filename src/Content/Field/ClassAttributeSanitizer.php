<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use AtarCms\View\EditorSettings;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps the class tokens of valid syntax (EditorSettings::CLASS_PATTERN), and of those only the allowed ones when a
 * set is given; duplicates go, at most MAX tokens stay, and an empty result drops the attribute. Classes are not a
 * security boundary: this keeps stored HTML clean of pasted and stale classes.
 */
final class ClassAttributeSanitizer implements AttributeSanitizerInterface
{
    public const int MAX = 8;

    /** @var array<string, true>|null */
    private readonly ?array $allowed;

    /**
     * @param list<string>|null $allowed null keeps every class of valid syntax
     */
    public function __construct(?array $allowed)
    {
        $this->allowed = $allowed === null ? null : array_fill_keys($allowed, true);
    }

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['class'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $kept = [];
        foreach (preg_split('/[\t\n\f\r ]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
            if (preg_match(EditorSettings::CLASS_PATTERN, $class) === 1 && ($this->allowed === null || isset($this->allowed[$class]))) {
                $kept[$class] = true;
            }
        }

        return $kept === [] ? null : implode(' ', array_slice(array_keys($kept), 0, self::MAX));
    }
}
