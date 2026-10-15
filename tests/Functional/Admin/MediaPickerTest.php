<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Kernel\Container;
use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderService;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

/**
 * The picker fragment: folders, views and filters through htmx, the remembered view and folder, uploads into the folder.
 */
final class MediaPickerTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testThePickerBrowsesFoldersThroughHtmxAndRemembersTheFolder(): void
    {
        $photos = $this->folder('Photos');
        $this->insertMedia('loose.jpg');
        $this->insertMedia('beach.jpg', $photos->id);
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $root = (string) $this->browser->get('/admin/media/picker?kind=image')->getBody();
        self::assertStringContainsString('loose.jpg', $root);
        self::assertStringNotContainsString('beach.jpg', $root);
        self::assertStringContainsString('<a class="admin-media-folder" href="/admin/media/picker?folder=' . $photos->id . '&amp;kind=image" hx-get="/admin/media/picker?folder=' . $photos->id . '&amp;kind=image" hx-target="closest [data-media-picker]" hx-swap="outerHTML">', $root);
        self::assertStringNotContainsString('admin.media.folders.create', $root);
        self::assertStringNotContainsString('/admin/media/folders/new', $root, 'the picker does not manage folders');

        $response = $this->browser->get('/admin/media/picker?folder=' . $photos->id . '&kind=image');
        self::assertStringContainsString('beach.jpg', (string) $response->getBody());
        self::assertStringContainsString('admin_picker_folder=' . $photos->id . '; Path=/admin;', $response->getHeaderLine('Set-Cookie'));

        $reopened = (string) $this->browser->get('/admin/media/picker?kind=image')->getBody();
        self::assertStringContainsString('beach.jpg', $reopened, 'the picker opens in the last folder');
        self::assertStringContainsString('<span aria-current="page">Photos</span></li>', $reopened);

        $this->browser->get('/admin/media/picker?folder=0&kind=image');
        self::assertStringContainsString('loose.jpg', (string) $this->browser->get('/admin/media/picker?kind=image')->getBody(), 'back at the root');

        $this->browser->setCookie('admin_picker_folder', '999');
        self::assertStringContainsString('loose.jpg', (string) $this->browser->get('/admin/media/picker?kind=image')->getBody(), 'a deleted folder falls back to the root');
    }

    public function testTheViewIsRememberedApartFromTheLibrary(): void
    {
        $this->insertMedia('loose.jpg');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $list = $this->browser->get('/admin/media/picker?kind=image&view=list');
        self::assertStringContainsString('<tr class="admin-table-row" data-media-row data-media-item>', (string) $list->getBody());
        self::assertStringContainsString('admin_picker_view=list;', $list->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('data-media-row', (string) $this->browser->get('/admin/media/picker?kind=image')->getBody());
        self::assertStringContainsString('<ul class="admin-media-grid">', (string) $this->browser->get('/admin/media')->getBody(), 'the library keeps its own view');
    }

    public function testImageKindShowsTheTypeFixedAndThePickerHasTheUnusedToggle(): void
    {
        $used = $this->insertMedia('used.jpg');
        $this->insertMedia('free.jpg');
        $this->entry('articles', 'Trip', ['slug' => 'trip', 'fields' => ['cover' => $used]]);
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $images = (string) $this->browser->get('/admin/media/picker?kind=image')->getBody();
        self::assertStringNotContainsString('name="type"', $images, 'the type is fixed, not a choice');
        self::assertStringContainsString('class="admin-media-dropdown-trigger admin-media-dropdown-fixed" role="img" title="Only images can be chosen here" aria-label="Type: Images. Only images can be chosen here"', $images);
        self::assertStringContainsString('<input type="checkbox" role="switch" id="picker-unused" name="unused" value="1">', $images);
        $unused = (string) $this->browser->get('/admin/media/picker?kind=image&unused=1')->getBody();
        self::assertStringContainsString('free.jpg', $unused);
        self::assertStringNotContainsString('used.jpg', $unused);
        self::assertStringContainsString('name="sort"', $images);
        self::assertStringContainsString('<input type="hidden" name="kind" value="image">', $images);

        $all = (string) $this->browser->get('/admin/media/picker?kind=all&type=document')->getBody();
        self::assertStringContainsString('<input type="radio" class="admin-visually-hidden" name="type" value="document" checked>', $all);
    }

    /**
     * The picker's files look like the library's: a checkbox in a card's corner (the card is its label) or in the first
     * table column (the name is its label).
     */
    public function testFilesAreCheckboxesLikeInTheLibrary(): void
    {
        $id = $this->insertMedia('cat.jpg');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $grid = (string) $this->browser->get('/admin/media/picker?kind=image')->getBody();
        self::assertMatchesRegularExpression('~<li class="admin-media-grid-item" data-media-item>\s*<label class="admin-media-card" for="pick-' . $id . '" data-pick-info>~', $grid);
        self::assertMatchesRegularExpression('~<span class="admin-media-select"><input type="checkbox" id="pick-' . $id . '" data-media-pick="' . $id . '" data-name="cat.jpg" [^>]*></span>~', $grid);

        $list = (string) $this->browser->get('/admin/media/picker?kind=image&view=list')->getBody();
        self::assertSame(1, substr_count($list, 'data-media-pick="' . $id . '"'));
        self::assertStringContainsString('<td class="admin-media-cell-check"><span class="admin-media-select"><input type="checkbox" id="pick-' . $id . '"', $list);
        self::assertStringContainsString('<label class="admin-media-name admin-media-pick-name" title="cat.jpg" for="pick-' . $id . '" data-pick-info>cat.jpg</label>', $list);
        self::assertStringContainsString('<td data-label="Dimensions">64 × 48 px</td>', $list);
    }

    public function testThePickerUploadsIntoItsFolderAndMarksTheNewFiles(): void
    {
        $photos = $this->folder('Photos');
        $old = $this->insertMedia('old.jpg', $photos->id);
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $form = (string) $this->browser->get('/admin/media/picker?folder=' . $photos->id . '&kind=image&locale=ru')->getBody();
        self::assertStringContainsString('<input type="hidden" name="folder" value="' . $photos->id . '">', $form);
        self::assertStringContainsString('action="/admin/media?context=picker&amp;kind=image&amp;locale=ru"', $form);

        $body = (string) $this->browser->upload(
            '/admin/media?context=picker&kind=image',
            ['_csrf' => $this->csrfToken(), 'folder' => (string) $photos->id],
            ['files' => [TestImages::uploaded(TestImages::jpeg(), 'new.jpg', 'image/jpeg'), TestImages::uploaded(TestImages::pdf(), 'doc.pdf', 'application/pdf')]],
            ['HX-Request' => 'true'],
        )->getBody();
        $new = Cast::int($this->db->scalar("SELECT id FROM media WHERE original_name = 'new.jpg'"));

        self::assertSame($photos->id, Cast::int($this->db->scalar('SELECT folder_id FROM media WHERE id = ?', [$new])));
        self::assertMatchesRegularExpression('~data-media-pick="' . $new . '"[^>]* data-uploaded>~', $body);
        self::assertDoesNotMatchRegularExpression('~data-media-pick="' . $old . '"[^>]* data-uploaded>~', $body);
        self::assertStringContainsString('<span aria-current="page">Photos</span></li>', $body);
    }

    private function folder(string $name): MediaFolder
    {
        return $this->app->container()->service(MediaFolderService::class)->create($name, null, null, null);
    }

    private function insertMedia(string $name, ?int $folder = null): int
    {
        $now = $this->clock->now();

        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . '.jpg',
            'original_name' => $name,
            'mime' => 'image/jpeg',
            'size' => 100,
            'width' => 64,
            'height' => 48,
            'alt' => [],
            'uploaded_by' => null,
            'folder_id' => $folder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
