<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Content\Preset\PresetPages;
use AtarCms\Tests\Support\Regex;

/**
 * The skeleton links the admin's languages (locales.admin): a Russian list speaks Russian, its title carries no flag,
 * its Languages column shows plain flags (no links), and the top bar's switch keeps the theme's flag.
 */
final class SkeletonLinkedLanguageTest extends SkeletonTestCase
{
    public function testARussianListSpeaksRussianWithoutLanguageMarks(): void
    {
        $this->app->container()->service(PresetPages::class)->sync();
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/content/pages?locale=ru')->getBody();

        self::assertStringContainsString('<html lang="ru" dir="ltr"', $page);
        self::assertStringContainsString('<h1>Страницы</h1>', $page, 'no flag by the title');
        $cell = Regex::match('~<td data-label="Языки">(.*?)</td>~su', $page, 'the Languages column stays');
        self::assertMatchesRegularExpression('~^<span class="admin-flags"><img class="admin-flag" src="/theme-assets/flags/[a-z]{2}\.png\?v=[0-9a-f]{12}"~', $cell[1], 'the theme\'s round flags');
        self::assertStringNotContainsString('href', $cell[1], 'plain flags, never links');
        self::assertMatchesRegularExpression('~<summary class="admin-menu-trigger" title="Язык: Русский" aria-label="Язык: Русский"><img class="admin-locale-flag admin-locale-flag-large" src="/theme-assets/flags/ru\.png\?v=[0-9a-f]{12}" alt="" width="22" height="22"></summary>~u', $page);

        $hebrew = (string) $this->browser->get('/admin/content/pages?locale=he')->getBody();
        self::assertStringContainsString('<html lang="he" dir="rtl"', $hebrew);
    }
}
