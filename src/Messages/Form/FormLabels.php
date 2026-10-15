<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use AtarCms\I18n\Translator;
use AtarCms\Kernel\SiteTime;

/**
 * The words of a site form from the theme catalog, by convention: forms.{key}.title, forms.{key}.{field} and
 * forms.{key}.{field}.{option}. A missing label falls back to the given core key, else to the raw key, so a message of
 * a form or field later removed from config/forms.php still reads. Values are shown for people: an option by its
 * label, a checked checkbox as $yes, a date in the language's own order (a wall-clock date, never converted).
 */
final readonly class FormLabels
{
    public function __construct(
        private Translator $translator,
        private SiteTime $time,
    ) {}

    /**
     * The form's name (forms.{key}.title), else its key.
     */
    public function title(string $locale, string $form): string
    {
        return $this->first($locale, ['forms.' . $form . '.title']) ?? $form;
    }

    /**
     * The field's label: the keys of $preferred first (the contact form's core labels), then forms.{form}.{field},
     * then the keys of $fallback, else the field's name.
     *
     * @param list<string> $preferred
     * @param list<string> $fallback
     */
    public function field(string $locale, string $form, string $field, array $preferred = [], array $fallback = []): string
    {
        return $this->first($locale, [...$preferred, 'forms.' . $form . '.' . $field, ...$fallback]) ?? $field;
    }

    /**
     * $value of the field for people: an option by its label (else its key), a checked checkbox as the translation of
     * $yesKey, a date in $locale's order, anything else (and a field no longer configured) as it is.
     */
    public function value(string $locale, string $form, ?FormField $field, string $value, string $yesKey): string
    {
        if ($field === null) {
            return $value;
        }

        return match ($field->type) {
            FieldType::Select, FieldType::Radio => $this->first($locale, ['forms.' . $form . '.' . $field->name . '.' . $value]) ?? $value,
            FieldType::Checkbox => $this->translator->translate($locale, $yesKey),
            FieldType::Date => $this->time->readableWallDate($value, $locale),
            default => $value,
        };
    }

    /**
     * @param list<string> $keys
     */
    private function first(string $locale, array $keys): ?string
    {
        foreach ($keys as $key) {
            if ($this->translator->canTranslate($locale, $key)) {
                return $this->translator->translate($locale, $key);
            }
        }

        return null;
    }
}
