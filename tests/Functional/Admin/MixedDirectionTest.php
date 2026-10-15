<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\UserRepository;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * A Hebrew interface shows content of any language: content inputs carry their own language (ContentDirectionTest),
 * names take their direction from their own text (admin.css), and addresses, emails, phones and codes are always
 * written left to right.
 */
final class MixedDirectionTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Locales::class, new Locales('en', ['en', 'he']));
    }

    public function testAHebrewInterfaceEditsEnglishContent(): void
    {
        $hello = $this->entry('stories', 'Hello!', ['slug' => 'hello', 'terms' => ['sections' => [$this->term('sections', 'World', ['slug' => 'world'])->id]]]);
        $admin = $this->createUser('admin@example.com');
        $this->app->container()->service(UserRepository::class)->setLocale($admin->id, 'he');
        $this->actingAs($admin);

        $form = (string) $this->browser->get('/admin/content/stories/' . $hello->id . '?locale=en')->getBody();

        self::assertStringContainsString('<html lang="he" dir="rtl"', $form);
        self::assertStringContainsString('value="Hello!" lang="en" dir="ltr">', $form, 'the title is English');
        self::assertStringContainsString('<input id="slug" name="slug" maxlength="190" dir="ltr" value="hello">', $form, 'an address is always left to right');

        // A numeric date and time ("2026-10-03 23:17") would read "23:17 2026-10-03" in a right-to-left cell.
        $list = (string) $this->browser->get('/admin/content/stories?locale=en')->getBody();
        self::assertMatchesRegularExpression('~<td data-label="עודכן"><span class="admin-table-date">[^<]*\d{4}[^<]*</span><span class="admin-table-time">\d\d:\d\d</span></td>~u', $list, 'a date in words follows the interface language, not a numeric date forced left to right');
        self::assertMatchesRegularExpression('~<span dir="ltr">\d{4}-\d\d-\d\d \d\d:\d\d:\d\d</span>~', (string) $this->browser->get('/admin/audit')->getBody());
        $this->app->container()->service(UserRepository::class)->recordLogin($admin->id);
        self::assertMatchesRegularExpression('~<td data-label="כניסה אחרונה"><span class="admin-table-date">[^<]*\d{4}[^<]*</span><span class="admin-table-time">\d\d:\d\d</span></td>~u', (string) $this->browser->get('/admin/users')->getBody(), 'the last sign-in is a table date in words too');
    }

    public function testAddressesEmailsPhonesAndCodesAreLeftToRight(): void
    {
        $news = $this->term('topics', 'News', ['slug' => 'news']);
        $home = $this->menuItem('main', 'Home', ['url' => '/']);
        $admin = $this->createUser('admin@example.com');
        $this->app->container()->service(UserRepository::class)->setLocale($admin->id, 'he');
        $this->actingAs($admin);

        self::assertStringContainsString('<input id="slug" name="slug" maxlength="190" dir="ltr" value="news">', (string) $this->browser->get('/admin/taxonomies/topics/' . $news->id)->getBody());
        self::assertStringContainsString('<input id="url" name="url" maxlength="2048" dir="ltr" value="/">', (string) $this->browser->get('/admin/menus/main/' . $home->id)->getBody());
        self::assertStringContainsString('<input id="email" name="email" type="email" required maxlength="254" dir="ltr" value="">', (string) $this->browser->get('/admin/users/new')->getBody());
        self::assertStringContainsString('<input id="email" type="email" dir="ltr" value="admin@example.com" disabled>', (string) $this->browser->get('/admin/users/' . $admin->id)->getBody());

        $settings = (string) $this->browser->get('/admin/settings')->getBody();
        foreach (['yandex_verification', 'google_verification'] as $name) {
            self::assertMatchesRegularExpression('~<input[^>]* id="' . $name . '" [^>]*dir="ltr"[^>]*>~', $settings, $name);
        }
        self::assertDoesNotMatchRegularExpression('~<input[^>]* id="title_pattern" [^>]*dir=~', $settings, 'a pattern of words keeps the page direction');
    }
}
