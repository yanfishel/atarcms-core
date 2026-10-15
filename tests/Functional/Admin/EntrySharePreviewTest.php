<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Kernel\Container;
use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

/**
 * The top of the entry form's SEO tab: the saved entry's generated share image with one line under it, and nothing at
 * all without one (user decisions after the Plan 20 check: no heading, no link preview card, no fallback picture).
 */
final class EntrySharePreviewTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testTheGeneratedImageIsTheFirstThingInTheSeoTab(): void
    {
        $entry = $this->entry('articles', 'Trip', ['slug' => 'trip', 'metaTitle' => 'Рыбалка', 'fields' => ['cover' => $this->upload()->id]]);

        $body = $this->form('/admin/content/articles/' . $entry->id);

        self::assertMatchesRegularExpression('~id="panel-seo" aria-labelledby="tab-seo">\s*<h2 class="admin-tab-heading">SEO</h2>\s*<div class="admin-share-preview">\s*<img class="admin-share-image" src="/uploads/share/' . preg_quote((string) $entry->shareImage, '~') . '" alt="" width="1200" height="630">\s*<p class="admin-help">Made from the main photo and the title for search engines, or the entry title when that is empty</p>\s*</div>\s*<div class="admin-field">\s*<label for="meta_title">~', $body);
        self::assertStringNotContainsString('id="tab-social"', $body);
        self::assertStringNotContainsString('Link preview', $body);
    }

    public function testWithoutATitleForSearchEnginesTheCardComesFromTheTitle(): void
    {
        $entry = $this->entry('articles', 'Trip', ['slug' => 'trip', 'fields' => ['cover' => $this->upload()->id]]);
        self::assertNotNull($entry->shareImage);

        $body = $this->form('/admin/content/articles/' . $entry->id);

        self::assertStringContainsString('<img class="admin-share-image" src="/uploads/share/' . $entry->shareImage . '"', $body);
        self::assertStringContainsString('<p class="admin-help">Made from the main photo and the title for search engines, or the entry title when that is empty</p>', $body);
    }

    public function testWithoutAGeneratedImageThereIsNothing(): void
    {
        $photo = $this->upload();
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(ogImageId: $photo->id), null, null);
        $noPhoto = $this->entry('articles', 'Other', ['slug' => 'other', 'metaTitle' => 'Рыбалка']);

        foreach (['/admin/content/articles/' . $noPhoto->id, '/admin/content/articles/new', '/admin/content/notes/new'] as $path) {
            $body = $this->form($path);
            self::assertStringNotContainsString('admin-share-', $body, $path);
            self::assertStringNotContainsString('Made from the main photo', $body, $path);
        }
    }

    private function form(string $path): string
    {
        $this->actingAs($this->createUser('ed' . bin2hex(random_bytes(3)) . '@example.com', 'admin'));
        $response = $this->browser->get($path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }

    private function upload(): Media
    {
        return $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(1600, 1000), 'photo.jpg', 'image/jpeg'), null, null);
    }
}
