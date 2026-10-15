<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use AtarCms\Http\Security\PublicFormToken;

/**
 * A site form as a theme draws it ($this->form('booking'), $this->contactForm()): post to $action (the modal posts
 * there too, asking for JSON) with the fields of $fields, the hidden $tokenField = $token and the honeypot $honeypot
 * (an input people never see: hide it with CSS, tabindex="-1" and autocomplete="off"; never type="hidden", which bots
 * skip). $url is the form's own page (the trigger link's href; for the contact form the contact page). After a
 * refusal the fields hold what was typed and their errors ($formError: an expired form or too many messages); after
 * a successful post without JavaScript the form's page is shown again with $sent. $onItsPage: this request is the
 * form's page (a dialog of the same form there would repeat its ids). Every value is the visitor's text: escape it.
 */
final readonly class FormView
{
    public string $tokenField;

    public string $honeypot;

    /**
     * @param list<FormFieldView> $fields
     */
    public function __construct(
        public string $key,
        public string $action,
        public string $url,
        public string $token,
        public array $fields,
        public ?string $formError = null,
        public bool $sent = false,
        public bool $onItsPage = false,
    ) {
        $this->tokenField = PublicFormToken::FIELD;
        $this->honeypot = FormService::HONEYPOT;
    }

    public function hasErrors(): bool
    {
        if ($this->formError !== null) {
            return true;
        }
        foreach ($this->fields as $field) {
            if ($field->error !== null) {
                return true;
            }
        }

        return false;
    }

    public function field(string $name): ?FormFieldView
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
