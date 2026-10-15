<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\Google\GoogleIdentityProvider;
use AtarCms\Auth\Google\LeagueGoogleIdentityProvider;
use AtarCms\Kernel\Container;

/**
 * The login card of a site without Google sign-in: it tells visitors to ask for a login link, and names the console
 * command that creates one only outside production.
 */
final class LoginPageTest extends FunctionalTestCase
{
    protected function tearDown(): void
    {
        putenv('APP_ENV');
        parent::tearDown();
    }

    public function testInProductionTheCardDoesNotNameTheConsoleCommand(): void
    {
        $body = (string) $this->browser->get('/admin/login')->getBody();

        self::assertStringContainsString('<p class="admin-muted">Google sign-in is not configured. Ask an administrator for a login link.</p>', $body);
        self::assertStringNotContainsString('bin/atar', $body);
        self::assertStringNotContainsString('href="/admin/login/google"', $body);
    }

    public function testOutsideProductionTheCardNamesTheConsoleCommand(): void
    {
        putenv('APP_ENV=local');
        $this->bootApp();

        $body = (string) $this->browser->get('/admin/login')->getBody();

        self::assertStringContainsString('<p class="admin-muted">Google sign-in is not configured. Ask an administrator for a login link.</p>', $body);
        self::assertStringContainsString('<p class="admin-muted">On the server, this command creates one: <code>bin/atar user:login-link</code></p>', $body);
    }

    protected function configureContainer(Container $container): void
    {
        $container->set(GoogleIdentityProvider::class, new LeagueGoogleIdentityProvider('', '', 'http://localhost/admin/login/google/callback'));
    }
}
