<?php

declare(strict_types=1);

namespace AtarCms\Messages\Http;

use AtarCms\Content\Http\ContentController;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Config;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormInput;
use AtarCms\Messages\Form\FormService;
use AtarCms\Messages\Form\FormState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Receives the contact form (a public form: no session, Route::publicForm()). A sent or discarded message answers
 * 303 to the page with "?sent=1#contact"; a refused one renders the page again (ContentController::single()) with
 * the typed values and the errors: 422, or 429 when the sender has sent too many.
 */
final readonly class ContactFormController
{
    public function __construct(
        private FormService $forms,
        private FormDefinitions $definitions,
        private ContentController $content,
        private Responses $responses,
        private Config $config,
    ) {}

    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $form = $this->definitions->find(FormDefinitions::CONTACT) ?? throw new \LogicException('The contact form is built in');
        $body = $request->getParsedBody();
        $input = FormInput::fromBody($form, is_array($body) ? $body : []);
        $path = $request->getUri()->getPath();
        try {
            $this->forms->submit($form, $input, RequestLocale::of($request)->code, $this->config->appUrl() . $path, ClientIp::of($request));
        } catch (ValidationFailed $failed) {
            $response = $this->content->single($request->withAttribute(
                FormState::class,
                new FormState($form->key, $input->values, $failed->errors, $failed->params),
            ));
            $tooMany = ($failed->errors[FormService::FORM_ERROR] ?? null) === 'validation.message.too_many';

            return $response->withStatus($tooMany ? 429 : 422)->withHeader('Cache-Control', 'no-store, private');
        }

        return $this->responses->redirect($path . '?sent=1#contact', 303);
    }
}
