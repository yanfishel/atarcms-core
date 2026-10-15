<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Kernel\Container;
use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderService;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

final class MediaBulkTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testTheLibraryOffersCheckboxesAndTheBulkBar(): void
    {
        $photos = $this->folder('Photos');
        $id = $this->upload('cat.jpg');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $grid = (string) $this->browser->get('/admin/media?sort=name')->getBody();
        self::assertStringContainsString('<input type="checkbox" id="media-check-' . $id . '" name="ids[]" value="' . $id . '" form="media-bulk"><span class="admin-visually-hidden">Select “cat.jpg”</span>', $grid);
        self::assertStringContainsString('<form id="media-bulk" class="admin-action-bar admin-media-bulk" method="post" action="/admin/media/move"', $grid);
        self::assertStringContainsString('<label class="admin-media-bulk-label" for="media-target">Move to</label>', $grid);
        self::assertStringContainsString('<input type="hidden" name="back[sort]" value="name">', $grid);
        self::assertStringContainsString('<option value="' . $photos->id . '">— Photos</option>', $grid);
        self::assertStringNotContainsString('formaction="/admin/media/delete"', $grid, 'an author cannot delete');
        self::assertMatchesRegularExpression('~<label class="admin-media-card" for="media-check-' . $id . '">~', $grid, 'a click on the card checks the file');
        self::assertStringContainsString('<a class="admin-media-open" href="/admin/media/' . $id . '" title="Edit “cat.jpg”" aria-label="Edit “cat.jpg”">', $grid, 'the file page opens from its own icon');
        $list = (string) $this->browser->get('/admin/media?view=list')->getBody();
        self::assertStringContainsString('form="media-bulk"', $list);
        self::assertStringContainsString('<tr class="admin-table-row" data-media-row>', $list, 'a click on the row checks the file (media.js)');
        self::assertStringContainsString('<label class="admin-media-name" title="cat.jpg" for="media-check-' . $id . '">cat.jpg</label>', $list, 'the name checks the file too, without JavaScript');
        self::assertStringContainsString('<td class="admin-media-cell-actions"><a class="admin-media-open" href="/admin/media/' . $id . '"', $list);
        self::assertStringNotContainsString('admin-row-link', $list);
    }

    public function testMovesTheCheckedFilesAndReturnsToTheSameView(): void
    {
        $photos = $this->folder('Photos');
        $a = $this->upload('a.jpg');
        $b = $this->upload('b.jpg');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $response = $this->browser->post('/admin/media/move', ['_csrf' => $this->csrfToken(), 'ids' => [(string) $a, (string) $b, 'x'], 'target' => (string) $photos->id, 'back' => ['sort' => 'name', 'bogus' => 'x']]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/media?sort=name', $response->getHeaderLine('Location'));
        self::assertSame(2, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media WHERE folder_id = ?', [$photos->id])));
        self::assertStringContainsString('2 files moved.', (string) $this->browser->get('/admin/media')->getBody());
        self::assertSame(['media_folder.created', 'media.uploaded', 'media.uploaded', 'media.moved', 'media.moved'], $this->auditActions());

        $this->browser->post('/admin/media/move', ['_csrf' => $this->csrfToken(), 'ids' => [(string) $a], 'target' => '']);
        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media WHERE folder_id IS NULL')));
    }

    public function testAnInvalidOrUnknownTargetIsRefusedNotMovedToTheRoot(): void
    {
        $photos = $this->folder('Photos');
        $id = $this->upload('a.jpg');
        $this->actingAs($this->createUser('au@example.com', 'author'));
        $this->browser->post('/admin/media/move', ['_csrf' => $this->csrfToken(), 'ids' => [(string) $id], 'target' => (string) $photos->id]);

        foreach (['abc', '999'] as $target) {
            $response = $this->browser->post('/admin/media/move', ['_csrf' => $this->csrfToken(), 'ids' => [(string) $id], 'target' => $target, 'back' => ['page' => '3', 'sort' => 'name']]);
            self::assertSame('/admin/media?sort=name', $response->getHeaderLine('Location'), 'never back to a page that may be empty');
            self::assertSame($photos->id, Cast::int($this->db->scalar('SELECT folder_id FROM media')), $target);
            self::assertMatchesRegularExpression('~admin-toast-error[^>]*>.*The chosen folder was deleted in the meantime~s', (string) $this->browser->get('/admin/media')->getBody(), $target);
        }
    }

    public function testAConfirmedDeleteSentTwiceReportsNothingTheSecondTime(): void
    {
        $id = $this->upload('a.jpg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $this->browser->post('/admin/media/delete', ['_csrf' => $this->csrfToken(), 'confirmed' => '1', 'ids' => [(string) $id]]);
        $this->browser->get('/admin/media');
        $again = $this->browser->post('/admin/media/delete', ['_csrf' => $this->csrfToken(), 'confirmed' => '1', 'ids' => [(string) $id]]);

        self::assertSame('/admin/media', $again->getHeaderLine('Location'));
        self::assertStringNotContainsString('Select at least one file first.', (string) $this->browser->get('/admin/media')->getBody());
    }

    public function testMovingNothingIsAnErrorToast(): void
    {
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $this->browser->post('/admin/media/move', ['_csrf' => $this->csrfToken(), 'target' => '']);

        self::assertMatchesRegularExpression('~admin-toast-error[^>]*>.*Select at least one file first\.~s', (string) $this->browser->get('/admin/media')->getBody());
    }

    public function testDeleteAsksForConfirmationAndCountsTheUsedFiles(): void
    {
        $used = $this->upload('used.jpg');
        $free = $this->upload('free <1>.jpg');
        $this->entry('articles', 'Trip', ['slug' => 'trip', 'fields' => ['cover' => $used]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/media/delete', ['_csrf' => $this->csrfToken(), 'ids' => [(string) $used, (string) $free], 'back' => ['folder' => '']]);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Delete these 2 files? This cannot be undone.', $body);
        self::assertStringContainsString('<li>free &lt;1&gt;.jpg</li>', $body);
        self::assertStringContainsString('1 of them is used and will be kept.', $body);
        self::assertStringContainsString('<input type="hidden" name="confirmed" value="1">', $body);
        self::assertSame(2, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')), 'nothing is deleted before the confirmation');
    }

    public function testConfirmedDeleteSkipsUsedFilesAndReportsBothCounts(): void
    {
        $used = $this->upload('used.jpg');
        $sharing = $this->upload('logo.jpg');
        $free = $this->upload('free.jpg');
        $this->entry('articles', 'Trip', ['slug' => 'trip', 'fields' => ['cover' => $used]]);
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(ogImageId: $sharing), null, null);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/media/delete', ['_csrf' => $this->csrfToken(), 'confirmed' => '1', 'ids' => [(string) $used, (string) $sharing, (string) $free, '999']]);
        $body = (string) $this->browser->get('/admin/media')->getBody();

        self::assertSame('/admin/media', $response->getHeaderLine('Location'));
        self::assertSame([$used, $sharing], array_map(Cast::int(...), array_column($this->db->select('SELECT id FROM media ORDER BY id'), 'id')));
        self::assertStringContainsString('1 file deleted.', $body);
        self::assertMatchesRegularExpression('~admin-toast-error[^>]*>.*2 files were kept: entries or the site settings use them\.~s', $body);
    }

    public function testBulkDeleteNeedsTheDeletePermissionAndTheToken(): void
    {
        $id = $this->upload('a.jpg');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        self::assertSame(403, $this->browser->post('/admin/media/delete', ['_csrf' => $this->csrfToken(), 'confirmed' => '1', 'ids' => [(string) $id]])->getStatusCode());
        self::assertSame(419, $this->browser->post('/admin/media/move', ['ids' => [(string) $id], 'target' => ''])->getStatusCode());
        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
    }

    private function folder(string $name): MediaFolder
    {
        return $this->app->container()->service(MediaFolderService::class)->create($name, null, null, null);
    }

    private function upload(string $name): int
    {
        return $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(), $name, 'image/jpeg'), null, null)->id;
    }
}
