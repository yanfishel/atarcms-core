<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use AtarCms\Http\Security\PublicFormToken;

/**
 * A submitted site form, before validation: the posted text of each configured field (anything else posted is
 * ignored; a value that is no string, like "service[]=x", counts as empty), the honeypot $website (a field people never
 * see: a bot fills it) and the form's PublicFormToken.
 */
final readonly class FormInput
{
    /**
     * @param array<string, string> $values by field name, in the form's order
     */
    public function __construct(
        public array $values,
        public string $website = '',
        public mixed $token = null,
    ) {}

    /**
     * @param array<mixed> $body the parsed POST body
     */
    public static function fromBody(FormDefinition $form, array $body): self
    {
        $string = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        $values = [];
        foreach ($form->fields as $field) {
            $values[$field->name] = $string($field->name);
        }

        return new self($values, $string(FormService::HONEYPOT), $body[PublicFormToken::FIELD] ?? null);
    }

    public function value(string $name): string
    {
        return $this->values[$name] ?? '';
    }
}
