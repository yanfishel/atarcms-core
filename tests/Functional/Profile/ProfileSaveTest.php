<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Profile;

use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Settings\AdminAccent;
use AtarCms\Tests\Functional\FunctionalTestCase;

/**
 * Saving the Profile (POST /admin/profile): the interface language and the admin colour together, a toast, back to
 * the Profile; a refusal stays on the Profile at its field and stores nothing. Personal preferences are not audited.
 */
final class ProfileSaveTest extends FunctionalTestCase
{
    public function testSavingTheLanguageAndTheColour(): void
    {
        $ann = $this->createUser('ann@example.com', 'author');
        $this->actingAs($ann);

        $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'accent' => 'cherry']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/profile', $response->getHeaderLine('Location'));
        self::assertSame(['ru', AdminAccent::Cherry], [$this->fresh($ann)->locale, $this->fresh($ann)->accent]);
        $profile = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="cherry">', $profile);
        self::assertSame(1, substr_count($profile, 'Настройки сохранены.'), 'the toast, once');
        self::assertStringNotContainsString('Настройки сохранены.', (string) $this->browser->get('/admin/profile')->getBody());
        self::assertStringContainsString('data-accent="cherry"', (string) $this->browser->get('/admin')->getBody(), 'every admin page');
        self::assertSame([], $this->auditActions(), 'personal preferences are not audited');
    }

    public function testTheSiteColourChoiceClearsTheColour(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->app->container()->service(UserRepository::class)->setAccent($ann->id, AdminAccent::Blue);
        $this->actingAs($ann);

        $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'accent' => '']);

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->fresh($ann)->accent);
        self::assertStringContainsString('data-accent="green"', (string) $this->browser->get('/admin/profile')->getBody());
    }

    public function testARefusalStaysAtItsFieldAndStoresNothing(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'accent' => 'pink']);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertMatchesRegularExpression('~<div class="admin-alert" role="alert"><svg[^>]*>.*?</svg><p>[^<]+</p></div>\s*<div class="admin-entry-form">~s', $body, 'the summary alert');
        self::assertStringContainsString('<span class="admin-field-error" id="accent-error">Choose one of the colours.</span>', $body);
        self::assertStringContainsString('name="accent" value="" checked aria-invalid="true" aria-describedby="accent-help accent-error">', $body);
        self::assertStringContainsString('data-tab="interface" data-errors="1"', $body);
        self::assertMatchesRegularExpression('~<input type="radio" class="admin-visually-hidden" name="locale" value="ru" checked aria-describedby="locale-help">~', $body, 'the posted language is kept');
        self::assertSame([null, null], [$this->fresh($ann)->locale, $this->fresh($ann)->accent]);

        $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'it', 'accent' => 'teal']);
        $body = (string) $response->getBody();
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<span class="admin-field-error" id="locale-error">', $body);
        self::assertStringContainsString('name="locale" value="en" checked aria-invalid="true" aria-describedby="locale-help locale-error">', $body, 'an unknown language marks the first');
        self::assertSame([null, null], [$this->fresh($ann)->locale, $this->fresh($ann)->accent]);
    }

    public function testTheOldRouteIsGoneAndTheTokenIsRequired(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);
        $this->browser->get('/admin/profile');

        self::assertSame(419, $this->browser->post('/admin/profile', ['locale' => 'ru'])->getStatusCode());
        self::assertContains($this->browser->post('/admin/preferences/locale', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getStatusCode(), [404, 405]);
        self::assertNull($this->fresh($ann)->locale);
    }

    private function fresh(User $user): User
    {
        return $this->app->container()->service(UserRepository::class)->find($user->id) ?? self::fail('gone');
    }
}
