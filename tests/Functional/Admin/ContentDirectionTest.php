<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Media\MediaService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use Nyholm\Psr7\UploadedFile;

/**
 * Inputs holding content carry the lang and dir of the content's language, whatever the interface speaks: a Hebrew
 * entry, term, menu item or setting is written right to left in an English admin. Slugs, addresses and site-wide
 * values keep the interface's. Per-language screens name their language with a pill by the title.
 */
final class ContentDirectionTest extends ContentFunctionalTestCase
{
    private const string HE = ' lang="he" dir="rtl"';

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Locales::class, new Locales('en', ['en', 'he']));
    }

    public function testAHebrewEntryIsWrittenRightToLeft(): void
    {
        $shalom = $this->entry('stories', 'שלום', ['locale' => 'he', 'slug' => 'shalom', 'terms' => ['sections' => [$this->term('sections', 'חדשות', ['locale' => 'he', 'slug' => 'hadashot'])->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/stories/' . $shalom->id)->getBody();

        self::assertStringContainsString('<html lang="en"', $form, 'the interface stays English');
        self::assertStringContainsString('<input id="title" name="title" required maxlength="255" value="שלום"' . self::HE . '>', $form);
        self::assertMatchesRegularExpression('~<textarea id="field-body" name="fields\[body\]" rows="12" data-rich-text data-editor="[^"]*"' . self::HE . '>~', $form);
        self::assertMatchesRegularExpression('~<input id="field-tags" name="fields\[tags\]" value="" autocomplete="off" class="admin-tags-value" data-tags-value' . self::HE . '>~', $form);
        self::assertStringContainsString('<input id="slug" name="slug" maxlength="190" dir="ltr" value="shalom">', $form, 'an address is not text of the language');
        self::assertStringContainsString('data-content-locale="he"', $form, 'editor.js gives CKEditor the content language');
        self::assertStringContainsString('<h1>שלום <span class="admin-locale-badge" title="Content language: עברית" aria-hidden="true">HE</span>', $form);
    }

    public function testTermsMenuItemsSettingsAndAltTextsCarryTheirLanguage(): void
    {
        $news = $this->term('topics', 'חדשות', ['locale' => 'he', 'slug' => 'news']);
        $home = $this->menuItem('main', 'בית', ['url' => '/', 'locale' => 'he']);
        $this->actingAs($this->createUser('admin@example.com'));

        self::assertStringContainsString('value="חדשות"' . self::HE . '>', (string) $this->browser->get('/admin/taxonomies/topics/' . $news->id)->getBody());
        self::assertStringContainsString('<input id="label" name="label" maxlength="200" value="בית"' . self::HE . '>', (string) $this->browser->get('/admin/menus/main/' . $home->id)->getBody());

        $settings = (string) $this->browser->get('/admin/settings?locale=he')->getBody();
        self::assertMatchesRegularExpression('~<input id="site_name" name="site_name" maxlength="100" value=""' . self::HE . '>~', $settings);
        self::assertMatchesRegularExpression('~<input id="yandex_verification" name="yandex_verification"[^>]*value=""[^>]*>~', $settings);
        self::assertDoesNotMatchRegularExpression('~<input id="yandex_verification"[^>]*lang=~', $settings, 'a site-wide value');

        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $file = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents((string) $file, $png);
        $media = $this->app->container()->service(MediaService::class)->upload(new UploadedFile((string) $file, strlen($png), UPLOAD_ERR_OK, 'dot.png', 'image/png'), null, null);
        $edit = (string) $this->browser->get('/admin/media/' . $media->id)->getBody();
        self::assertMatchesRegularExpression('~<label for="alt-en"><svg[^>]*>.*?</svg>Text<span class="admin-locale-badge" title="English" aria-hidden="true">EN</span>~', $edit);
        self::assertStringContainsString('name="alt[en]" maxlength="255" value="" lang="en" dir="ltr">', $edit);
        self::assertStringContainsString('name="alt[he]" maxlength="255" value=""' . self::HE . '>', $edit);
    }

    public function testListsNameTheirLanguageWithAPill(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        self::assertStringContainsString('<h1>Articles <span class="admin-locale-badge" title="Content language: עברית" aria-hidden="true">HE</span>', (string) $this->browser->get('/admin/content/articles?locale=he')->getBody());
        self::assertMatchesRegularExpression('~<h1>[^<]+</h1>~', (string) $this->browser->get('/admin/content/notes')->getBody(), 'a collection in one language has no pill');
        self::assertStringContainsString('<h1>Topics <span class="admin-locale-badge"', (string) $this->browser->get('/admin/taxonomies/topics')->getBody());
        self::assertStringContainsString('<summary class="admin-menu-trigger" title="Content language: עברית" aria-label="Content language: עברית">', (string) $this->browser->get('/admin')->getBody());
    }
}
