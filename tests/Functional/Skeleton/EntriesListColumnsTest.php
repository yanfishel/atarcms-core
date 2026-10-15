<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Admin\LanguageMode;
use AtarCms\Content\Preset\PresetPages;
use AtarCms\Kernel\Container;

/**
 * The skeleton's language marks, which only the separate mode shows, and its Languages column (in the linked mode,
 * which the skeleton itself uses, the entry lists keep it: SkeletonLinkedLanguageTest).
 */
final class EntriesListColumnsTest extends SkeletonTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(LanguageMode::class, LanguageMode::Separate);
    }

    public function testTheContentLanguageIsAFlagByTheTitleAndInTheTopBar(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/content/pages?locale=ru')->getBody();

        self::assertMatchesRegularExpression('~<h1>Pages <img class="admin-locale-flag" src="/theme-assets/flags/ru\.png\?v=[0-9a-f]{12}" alt="" title="Content language: Русский" aria-hidden="true" width="20" height="20"><span class="admin-visually-hidden">\(Content language: Русский\)</span></h1>~u', $page, 'the flag instead of the pill');
        self::assertMatchesRegularExpression('~<summary class="admin-menu-trigger" title="Content language: Русский" aria-label="Content language: Русский"><img class="admin-locale-flag admin-locale-flag-large" src="/theme-assets/flags/ru\.png\?v=[0-9a-f]{12}" alt="" width="22" height="22"></summary>~u', $page, 'the switch: the flag alone, as the other top-bar triggers');
        foreach (['en' => 'English', 'he' => 'עברית', 'ru' => 'Русский'] as $code => $name) {
            self::assertMatchesRegularExpression('~<button type="submit" class="admin-menu-item"[^>]*><img class="admin-locale-flag" src="/theme-assets/flags/' . $code . '\.png\?v=[0-9a-f]{12}" alt="" width="20" height="20"><span lang="' . $code . '"[^>]*>' . $name . '</span>~u', $page, $code);
        }
    }

    public function testTheStatusComesBeforeTheLanguagesShownAsRoundFlags(): void
    {
        $this->app->container()->service(PresetPages::class)->sync();
        $this->actingAs($this->createUser('admin@example.com'));

        $list = (string) $this->browser->get('/admin/content/pages')->getBody();

        preg_match_all('~<th(?:\s[^>]*)?>(?:<a[^>]*>)?([^<]+)~', $list, $headings);
        self::assertSame(['Title', 'Media', 'Published', 'Updated', 'Author', 'Status', 'Languages'], array_map('trim', $headings[1]), 'the status next to last, before the languages; the pages have a gallery');
        self::assertMatchesRegularExpression('~<td data-label="Status"><span class="admin-chip admin-chip-published">Published</span></td>\s*<td data-label="Languages"><span class="admin-flags">~', $list);
        foreach (['en' => 'English', 'he' => 'עברית', 'ru' => 'Русский'] as $code => $name) {
            self::assertMatchesRegularExpression('~<img class="admin-flag" src="/theme-assets/flags/' . $code . '\.png\?v=[0-9a-f]{12}" alt="' . $name . '" title="' . $name . '" width="20" height="20">~u', $list, $code);
        }
    }
}
