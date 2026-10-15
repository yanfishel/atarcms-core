<?php

declare(strict_types=1);

namespace AtarCms\Messages;

use AtarCms\Cli\Command\MailTestCommand;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Container;
use AtarCms\Kernel\Module;
use AtarCms\Messages\Form\FormDefinition;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormViews;
use AtarCms\Messages\Http\ContactFormController;
use AtarCms\Messages\Http\FormController;
use LogicException;

/**
 * The site's forms: visitors' messages are stored (messages table), listed on the admin's Messages screen
 * (AdminModule) and e-mailed when mail is set up (MAIL_DSN). The contact form posts to the address of the page that
 * shows it (config content.contact_form, ContactForm); every other form of config/forms.php (FormDefinitions) has
 * its page and endpoint at /forms/{key} (FormController; routes "forms.show.{key}" and "forms.send.{key}", locale
 * prefixed like every public page). Each form is a public form without the session. Registered before ContentModule,
 * so the slug "forms" is reserved under "/{slug}" while a form exists (ReservedSlugs).
 */
final class MessagesModule extends Module
{
    private ?Container $container = null;

    public function services(Container $container): void
    {
        // routes() only receives the router; the container is kept to read the forms and the contact form's page there.
        $this->container = $container;
        $container->factory(MailTransport::class, static fn(Container $c): MailTransport => MailTransport::fromConfig($c->service(Config::class)));
        $container->factory(ContactForm::class, static fn(Container $c): ContactForm => ContactForm::fromConfig($c->service(Config::class), $c->service(Collections::class)));
        $container->factory(FormDefinitions::class, static fn(Container $c): FormDefinitions => FormDefinitions::fromConfig($c->service(Config::class)));
    }

    public function routes(Router $router): void
    {
        $container = $this->container ?? throw new LogicException('MessagesModule::services() must run before routes()');
        // Built here, at boot: a mistake in config/forms.php stops the site at once.
        foreach ($container->service(FormDefinitions::class)->all() as $form) {
            if ($form->isContact()) {
                continue;
            }
            $path = '/forms/' . $form->key;
            $router->get($path, [FormController::class, 'show'], FormViews::showRoute($form->key))
                ->attribute(FormDefinition::class, $form->key);
            $router->post($path, [FormController::class, 'send'], FormViews::sendRoute($form->key))
                ->publicForm($form->key)
                ->attribute(FormDefinition::class, $form->key);
        }
        $page = $container->service(ContactForm::class)->page;
        if ($page !== null) {
            $router->post((string) $page->route, [ContactFormController::class, 'send'], ContactForm::ROUTE)
                ->publicForm(FormDefinitions::CONTACT)
                ->attribute(Collection::class, $page->name);
        }
    }

    public function commands(): array
    {
        return [MailTestCommand::class];
    }
}
