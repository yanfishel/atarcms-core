<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Languages;

use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * A site with one language shows nothing about languages in the admin: no content language switch, no locale marks,
 * no Languages columns, no Translations line, no language of a message, no "(English)" in delete confirmations.
 * (The interface language on the Profile page is the admin's own and stays.)
 */
final class SingleLanguageAdminTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Locales::class, new Locales('en', ['en']));
    }

    public function testNoScreenMentionsTheSitesLanguage(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $code = $this->term('topics', 'Code');
        $item = $this->menuItem('main', 'Home', ['url' => '/']);
        $message = $this->app->container()->service(MessageRepository::class)->insert([
            'form' => 'contact',
            'locale' => 'en',
            'name' => 'Anna',
            'email' => 'anna@example.com',
            'phone' => null,
            'body' => 'Hello',
            'page_url' => 'https://localhost/contacts',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => new \DateTimeImmutable('-1 hour'),
            'read_at' => null,
        ]);
        $this->actingAs($this->createUser('admin@example.com'));

        $pages = [
            '/admin',
            '/admin/content/articles',
            '/admin/content/articles/' . $hello->id,
            '/admin/content/articles/' . $hello->id . '/delete',
            '/admin/content/articles/' . $hello->id . '/revisions',
            '/admin/content/articles/new',
            '/admin/content/pages/order',
            '/admin/taxonomies/topics/new',
            '/admin/sections',
            '/admin/media',
            '/admin/taxonomies/topics',
            '/admin/taxonomies/topics/' . $code->id,
            '/admin/taxonomies/topics/' . $code->id . '/delete',
            '/admin/menus/main',
            '/admin/menus/main/' . $item->id . '/delete',
            '/admin/messages',
            '/admin/messages/' . $message,
            '/admin/settings',
        ];
        foreach ($pages as $path) {
            $response = $this->browser->get($path);
            $body = (string) $response->getBody();
            self::assertSame(200, $response->getStatusCode(), $path);
            foreach (['admin-content-locale', 'admin-locale-badge', 'admin-locale-flag', 'admin-flags', 'admin-flag-code', 'admin-translations', 'data-label="Languages"', 'data-label="Language"', '(English)', 'Content language'] as $mark) {
                self::assertStringNotContainsString($mark, $body, $path . ': ' . $mark);
            }
        }
    }

    public function testTheDashboardHasNoTranslationsCard(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'status' => \AtarCms\Content\EntryStatus::Draft]);
        $this->actingAs($this->createUser('admin@example.com'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertStringContainsString('id="dashboard-content"', $body);
        self::assertStringNotContainsString('id="dashboard-translations"', $body);
        self::assertStringNotContainsString('Articles · EN', $body, 'no language code in the lists');
        self::assertStringContainsString('<span class="admin-panel-meta">Articles</span>', $body);
    }
}
