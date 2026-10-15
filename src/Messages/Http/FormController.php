<?php

declare(strict_types=1);

namespace AtarCms\Messages\Http;

use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Security\PublicFormToken;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Config;
use AtarCms\Messages\Form\FormDefinition;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormInput;
use AtarCms\Messages\Form\FormService;
use AtarCms\Messages\Form\FormState;
use AtarCms\Messages\Form\FormViews;
use AtarCms\View\Renderer;
use AtarCms\View\ThemeResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The pages and endpoints of the site's forms (config/forms.php; the contact form has its own page):
 * GET /forms/{key} renders the theme's form.php with "form" (a FormView), never indexed; POST /forms/{key} (a public
 * form: no session, Route::publicForm(), only an urlencoded form body) answers the dialog's fetch (Accept:
 * application/json) with {"ok": true, "message": …} or {"ok": false, "errors": {field: text}, "form": text|null,
 * "token": a fresh token} (422, 429), the texts translated in the page's language and never the visitor's input;
 * without JSON a sent form
 * redirects to the page with ?sent=1 and a refused one shows the page again with the typed values and the errors.
 * The message's page is the same-origin Referer (where the dialog was opened), else the form's page.
 */
final readonly class FormController
{
    public function __construct(
        private FormDefinitions $forms,
        private FormService $service,
        private FormViews $views,
        private ThemeResolver $themes,
        private Renderer $view,
        private Responses $responses,
        private Translator $translator,
        private Config $config,
        private PublicFormToken $tokens,
        private Clock $clock,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $form = $this->form($request);

        return $this->page($request, $form)->withHeader('X-Robots-Tag', 'noindex');
    }

    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $form = $this->form($request);
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($type !== 'application/x-www-form-urlencoded') {
            return $this->responses->text('Send the form as application/x-www-form-urlencoded.', 415);
        }
        $json = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        $locale = RequestLocale::of($request)->code;
        $body = $request->getParsedBody();
        $input = FormInput::fromBody($form, is_array($body) ? $body : []);
        $page = $this->views->view($form, $request)->url;
        try {
            $this->service->submit($form, $input, $locale, $this->pageUrl($request, $page), ClientIp::of($request));
        } catch (ValidationFailed $failed) {
            $status = ($failed->errors[FormService::FORM_ERROR] ?? null) === 'validation.message.too_many' ? 429 : 422;
            if ($json) {
                return $this->refusal($form, $locale, $failed, $status);
            }
            $response = $this->page($request->withAttribute(FormState::class, new FormState($form->key, $input->values, $failed->errors, $failed->params)), $form);

            return $response->withStatus($status)->withHeader('Cache-Control', 'no-store, private');
        }

        if ($json) {
            return $this->responses->json(['ok' => true, 'message' => $this->translator->translate($locale, 'forms.' . $form->key . '.sent')])
                ->withHeader('Cache-Control', 'no-store');
        }

        return $this->responses->redirect($page . '?sent=1', 303);
    }

    private function form(ServerRequestInterface $request): FormDefinition
    {
        $key = $request->getAttribute(FormDefinition::class);

        return (is_string($key) ? $this->forms->find($key) : null) ?? throw new NotFound('No such form');
    }

    private function page(ServerRequestInterface $request, FormDefinition $form): ResponseInterface
    {
        if ($this->themes->find('form') === null) {
            throw new NotFound('The theme has no form.php template');
        }

        return $this->responses->html($this->view->render('form', ['form' => $this->views->view($form, $request)], $request));
    }

    /**
     * The JSON refusal, with a fresh token for the dialog to send the form again (an expired one stays expired); like
     * the page after a refusal, it may be sent at once.
     */
    private function refusal(FormDefinition $form, string $locale, ValidationFailed $failed, int $status): ResponseInterface
    {
        $errors = [];
        foreach ($failed->errors as $field => $key) {
            if ($field !== FormService::FORM_ERROR) {
                $errors[$field] = $this->translator->translate($locale, $key, $failed->params[$field] ?? []);
            }
        }
        $formError = $failed->errors[FormService::FORM_ERROR] ?? null;

        return $this->responses->json([
            'ok' => false,
            'errors' => (object) $errors,
            'form' => $formError === null ? null : $this->translator->translate($locale, $formError, $failed->params[FormService::FORM_ERROR] ?? []),
            'token' => $this->tokens->issue($form->key, $this->clock->now()->modify(sprintf('-%d seconds', PublicFormToken::MIN_SECONDS))),
        ], $status)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The absolute URL of the page the form was sent from: the Referer when it is on this site (the page the dialog
     * was opened on; same scheme, host and port, no userinfo), else the form's own page.
     */
    private function pageUrl(ServerRequestInterface $request, string $page): string
    {
        $site = $this->config->appUrl();
        $referer = $request->getHeaderLine('Referer');
        $origin = static function (string $url): ?string {
            $parts = parse_url($url);
            // A URL with userinfo ("https://evil.example@localhost/") is never taken for the site's.
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }

            return strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        };
        if ($referer !== '' && $origin($referer) !== null && $origin($referer) === $origin($site) && preg_match('/[\x00-\x20\x7f]/', $referer) !== 1) {
            return $referer;
        }

        return $site . $page;
    }
}
