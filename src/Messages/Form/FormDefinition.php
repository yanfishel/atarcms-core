<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

/**
 * One site form (an entry of config/forms.php, or the built-in contact form): its key, the address its messages are
 * mailed to (null: the site settings' e-mail) and its fields in config order, the order of the form, the mail and the
 * admin.
 */
final readonly class FormDefinition
{
    /**
     * @param list<FormField> $fields
     */
    public function __construct(
        public string $key,
        public ?string $recipient,
        public array $fields,
    ) {}

    public function field(string $name): ?FormField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    public function isContact(): bool
    {
        return $this->key === FormDefinitions::CONTACT;
    }
}
