<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use AtarCms\Http\Security\PublicFormToken;
use AtarCms\I18n\LocalizedUrls;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\SiteTime;
use AtarCms\Messages\ContactForm;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the FormView of a form for a request: its addresses in the request's language, a fresh signed token, the
 * typed values and errors of a refusal (FormState) and the "sent" notice (?sent=1 on the form's own page). It never
 * touches the session, so a page with a form sets no cookie.
 */
final readonly class FormViews
{
    public function __construct(
        private FormDefinitions $forms,
        private ContactForm $contact,
        private LocalizedUrls $urls,
        private PublicFormToken $tokens,
        private Clock $clock,
        private SiteTime $time,
    ) {}

    public static function showRoute(string $key): string
    {
        return 'forms.show.' . $key;
    }

    public static function sendRoute(string $key): string
    {
        return 'forms.send.' . $key;
    }

    /**
     * The FormView of form $key, or null for an unknown form and for the contact form of a site without a contact
     * page (config content.contact_form).
     */
    public function find(string $key, ServerRequestInterface $request): ?FormView
    {
        $form = $this->forms->find($key);
        if ($form === null || ($form->isContact() && $this->contact->page === null)) {
            return null;
        }

        return $this->view($form, $request);
    }

    public function view(FormDefinition $form, ServerRequestInterface $request): FormView
    {
        $locale = RequestLocale::of($request)->code;
        $action = $this->urls->path($form->isContact() ? ContactForm::ROUTE : self::sendRoute($form->key), [], $locale);
        $url = $form->isContact() ? $action : $this->urls->path(self::showRoute($form->key), [], $locale);
        $onItsPage = $request->getUri()->getPath() === $url;
        $state = FormState::of($request, $form->key);
        $now = $this->clock->now();
        // A refused form was already filled in by a person: its new token may be sent again at once.
        $token = $this->tokens->issue($form->key, $state === null ? $now : $now->modify(sprintf('-%d seconds', PublicFormToken::MIN_SECONDS)));
        $today = $this->time->today($now);
        $fields = [];
        foreach ($form->fields as $field) {
            $fields[] = new FormFieldView(
                name: $field->name,
                id: $form->key . '-' . $field->name,
                type: $field->type->value,
                required: $field->required,
                options: $field->options,
                value: $state->values[$field->name] ?? '',
                error: $state->errors[$field->name] ?? null,
                params: $state->params[$field->name] ?? [],
                min: $field->minValue($today),
                max: $field->maxValue($today),
            );
        }

        return new FormView(
            key: $form->key,
            action: $action,
            url: $url,
            token: $token,
            fields: $fields,
            formError: $state->errors[FormService::FORM_ERROR] ?? null,
            sent: $state === null && $onItsPage && ($request->getQueryParams()['sent'] ?? null) === '1',
            onItsPage: $onItsPage,
        );
    }
}
