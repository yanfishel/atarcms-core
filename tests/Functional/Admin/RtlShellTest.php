<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\UserRepository;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\FunctionalTestCase;

/**
 * Every admin page names its interface language and its direction on <html>: a Hebrew interface is laid out right
 * to left, the others left to right. The login page and the admin error pages share the layout.
 */
final class RtlShellTest extends FunctionalTestCase
{
    private ?Locales $locales = null;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        if ($this->locales !== null) {
            $container->set(Locales::class, $this->locales);
        }
    }

    public function testAHebrewInterfaceIsRightToLeft(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'he');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin')->getBody();

        self::assertStringContainsString('<html lang="he" dir="rtl" data-accent="green">', $dashboard);
        self::assertStringContainsString("שלום, \u{2068}Ann\u{2069}!", $dashboard);
    }

    public function testOtherInterfacesAreLeftToRight(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="green">', (string) $this->browser->get('/admin')->getBody());
    }

    public function testTheLoginPageOfAHebrewSiteIsRightToLeft(): void
    {
        $this->locales = new Locales('he', ['he', 'en']);
        $this->bootApp();

        $page = (string) $this->browser->get('/admin/login')->getBody();

        self::assertStringContainsString('<html lang="he" dir="rtl"', $page);
        self::assertStringContainsString('<h1>כניסה</h1>', $page);
    }

    public function testAdminErrorPagesFollowTheDirection(): void
    {
        $guest = $this->browser->get('/admin/nope', ['Accept-Language' => 'he-IL,he;q=0.9']);
        self::assertSame(404, $guest->getStatusCode());
        self::assertStringContainsString('<html lang="he" dir="rtl"', (string) $guest->getBody());

        $ann = $this->createUser('ann@example.com');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'he');
        $this->actingAs($ann);
        $signedIn = (string) $this->browser->get('/admin/nope')->getBody();
        self::assertStringContainsString('<html lang="he" dir="rtl"', $signedIn);
        self::assertStringContainsString('הדף לא נמצא', $signedIn);
    }
}
