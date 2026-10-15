<?php

declare(strict_types=1);

namespace AtarCms\Http\Error;

use AtarCms\I18n\Alternates;
use AtarCms\View\Renderer;
use AtarCms\View\TemplateNotFound;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class ThemeErrorRenderer implements ErrorRenderer
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Error pages advertise no language alternates, also when a route matched before the error ("/ru/ghost" under a
     * "/{slug}" route): there is no page to point to.
     */
    public function render(Throwable $error, int $status, ServerRequestInterface $request): string
    {
        try {
            return $this->renderer->render(
                ['errors/' . $status, 'errors/error'],
                ['status' => $status, 'reason' => PlainErrorPage::reason($status)],
                $request->withAttribute(Alternates::class, new Alternates([])),
            );
        } catch (TemplateNotFound) {
            return PlainErrorPage::render($status);
        } catch (Throwable $renderError) {
            $this->logger->error('Error page rendering failed: {message}', [
                'message' => $renderError->getMessage(),
                'exception' => $renderError,
            ]);

            return PlainErrorPage::render($status);
        }
    }
}
