<?php

declare(strict_types=1);

namespace AtarCms\Messages;

use AtarCms\Kernel\Container;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormView;
use AtarCms\Messages\Form\FormViews;
use AtarCms\View\Renderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Theme helpers form($key) and contactForm(): the form to draw (FormView, with a fresh signed token), or null for an
 * unknown form and for the contact form of a site without a contact page (config content.contact_form). They never
 * touch the session, so a page with a form sets no cookie.
 */
final class MessagesViewHelpers
{
    public static function register(Renderer $renderer, Container $container): void
    {
        $renderer->requestHelper(
            'form',
            static fn(ServerRequestInterface $request, string $key): ?FormView => $container->service(FormViews::class)->find($key, $request),
        );
        $renderer->requestHelper(
            'contactForm',
            static fn(ServerRequestInterface $request): ?FormView => $container->service(FormViews::class)->find(FormDefinitions::CONTACT, $request),
        );
    }
}
