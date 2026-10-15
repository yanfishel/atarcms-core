<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\LinkedLanguage;

use AtarCms\Admin\LanguageMode;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * The fixture site in the linked mode (the core default; the fixture's config pins "separate" for the older suites).
 * A test may give the site other languages before bootApp().
 */
abstract class LinkedLanguageTestCase extends ContentFunctionalTestCase
{
    protected ?Locales $locales = null;

    protected LanguageMode $mode = LanguageMode::Linked;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(LanguageMode::class, $this->mode);
        if ($this->locales !== null) {
            $container->set(Locales::class, $this->locales);
        }
    }

    protected function users(): UserRepository
    {
        return $this->app->container()->service(UserRepository::class);
    }

    protected function fresh(User $user): User
    {
        return $this->users()->find($user->id) ?? self::fail('The user is gone');
    }

    protected function switchTo(string $locale, string $return): string
    {
        $response = $this->browser->post('/admin/preferences/content-locale', ['_csrf' => $this->csrfToken(), 'locale' => $locale, 'return' => $return]);
        self::assertSame(303, $response->getStatusCode());

        return $response->getHeaderLine('Location');
    }
}
