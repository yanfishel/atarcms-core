<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

/**
 * One field of a FormView, ready to draw: $id ("{form}-{field}", unique on a page with several forms) for the input
 * and its label, $errorId for the error paragraph (aria-describedby), $type one of FieldType's values, $options the
 * option keys of a select or radio field (labels: forms.{form}.{field}.{option}), $value what the visitor typed
 * (after a refusal), $error a translation key with its ICU $params, and $min/$max the bounds as HTML attribute values
 * for the site's today ("2026-10-15", "09:00"). Every value is the visitor's text: escape it.
 */
final readonly class FormFieldView
{
    public string $errorId;

    /**
     * @param list<string> $options
     * @param array<string, int|float|string> $params
     */
    public function __construct(
        public string $name,
        public string $id,
        public string $type,
        public bool $required,
        public array $options = [],
        public string $value = '',
        public ?string $error = null,
        public array $params = [],
        public ?string $min = null,
        public ?string $max = null,
    ) {
        $this->errorId = $id . '-error';
    }
}
