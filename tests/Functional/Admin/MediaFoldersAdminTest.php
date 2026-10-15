<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaFolderService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

final class MediaFoldersAdminTest extends ContentFunctionalTestCase
{
    public function testCreatesAFolderInsideTheGivenParent(): void
    {
        $photos = $this->folder('Photos');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $form = (string) $this->browser->get('/admin/media/folders/new?parent=' . $photos->id)->getBody();
        self::assertStringContainsString('action="/admin/media/folders"', $form);
        self::assertStringContainsString('<option value="">All files</option>', $form);
        self::assertStringContainsString('<option value="' . $photos->id . '" selected>— Photos</option>', $form);
        self::assertStringContainsString('<a href="/admin/media?folder=' . $photos->id . '">Photos</a>', $form);

        $response = $this->browser->post('/admin/media/folders', ['_csrf' => $this->csrfToken(), 'name' => ' Summer ', 'parent_id' => (string) $photos->id]);

        $summer = $this->folders()->all()[1] ?? self::fail('not created');
        self::assertSame(['Summer', $photos->id], [$summer->name, $summer->parentId]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/media?folder=' . $summer->id, $response->getHeaderLine('Location'));
        self::assertStringContainsString('Folder “Summer” created.', (string) $this->browser->get('/admin/media')->getBody());
        self::assertSame(['media_folder.created', 'media_folder.created'], $this->auditActions());
    }

    public function testShowsFieldErrorsAtTheName(): void
    {
        $this->folder('Photos');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $response = $this->browser->post('/admin/media/folders', ['_csrf' => $this->csrfToken(), 'name' => 'photos', 'parent_id' => '']);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('id="folder-name" name="name" required maxlength="100" autofocus value="photos" aria-invalid="true" aria-describedby="folder-name-error"', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="folder-name-error">This folder already has a folder with this name.</span>', $body);
        self::assertStringNotContainsString('admin-toast-error', $body);
        self::assertCount(1, $this->folders()->all());
    }

    public function testRenamesAndMovesWithTheParentSelectDisablingDescendants(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B', $a->id);
        $c = $this->folder('C', $b->id);
        $other = $this->folder('Other');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $form = (string) $this->browser->get('/admin/media/folders/' . $b->id)->getBody();
        self::assertStringContainsString('<option value="' . $a->id . '" selected>— A</option>', $form);
        self::assertStringContainsString('<option value="' . $b->id . '" disabled>— — B</option>', $form);
        self::assertStringContainsString('<option value="' . $c->id . '" disabled>— — — C</option>', $form);
        self::assertStringContainsString('name="version" value="' . MediaFolderService::version($b) . '"', $form);
        self::assertStringNotContainsString('/delete"', $form, 'an author cannot delete');

        $response = $this->browser->post('/admin/media/folders/' . $b->id, ['_csrf' => $this->csrfToken(), 'name' => 'B2', 'parent_id' => (string) $other->id, 'version' => MediaFolderService::version($b)]);
        self::assertSame('/admin/media?folder=' . $b->id, $response->getHeaderLine('Location'));
        self::assertSame(['B2', $other->id], [$this->folders()->find($b->id)?->name, $this->folders()->find($b->id)?->parentId]);

        $cycle = $this->browser->post('/admin/media/folders/' . $other->id, ['_csrf' => $this->csrfToken(), 'name' => 'Other', 'parent_id' => (string) $c->id, 'version' => MediaFolderService::version($other)]);
        self::assertSame(422, $cycle->getStatusCode());
        self::assertStringContainsString('id="folder-parent-error">A folder cannot go inside itself or one of its own folders.</span>', (string) $cycle->getBody());

        $stale = $this->browser->post('/admin/media/folders/' . $other->id, ['_csrf' => $this->csrfToken(), 'name' => 'New', 'parent_id' => '', 'version' => '2020-01-01 00:00:00']);
        $body = (string) $stale->getBody();
        self::assertSame(422, $stale->getStatusCode());
        self::assertMatchesRegularExpression('~admin-toast-error[^>]*>.*Someone else changed this folder~s', $body);
        self::assertStringNotContainsString('admin-alert', $body, 'a refusal without a field is a toast');
    }

    public function testRefusedDeleteIsAnErrorToast(): void
    {
        $a = $this->folder('A');
        $this->folder('B', $a->id);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $confirm = (string) $this->browser->get('/admin/media/folders/' . $a->id . '/delete')->getBody();
        self::assertStringContainsString('Delete the folder “A”?', $confirm);
        $response = $this->browser->post('/admin/media/folders/' . $a->id . '/delete', ['_csrf' => $this->csrfToken()]);

        self::assertSame('/admin/media/folders/' . $a->id, $response->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('~admin-toast-error.*Only an empty folder can be deleted\. This one holds 1 folder and 0 files~s', (string) $this->browser->get('/admin/media/folders/' . $a->id)->getBody());
        self::assertCount(2, $this->folders()->all());
    }

    public function testDeletesAnEmptyFolderAndReturnsToItsParent(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B', $a->id);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/media/folders/' . $b->id . '/delete', ['_csrf' => $this->csrfToken()]);

        self::assertSame('/admin/media?folder=' . $a->id, $response->getHeaderLine('Location'));
        self::assertNull($this->folders()->find($b->id));
        self::assertStringContainsString('Folder “B” deleted.', (string) $this->browser->get('/admin/media')->getBody());
    }

    public function testDeleteNeedsTheDeletePermissionAndWritesNeedTheToken(): void
    {
        $a = $this->folder('A');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        self::assertSame(403, $this->browser->get('/admin/media/folders/' . $a->id . '/delete')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/media/folders/' . $a->id . '/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertSame(419, $this->browser->post('/admin/media/folders', ['name' => 'X'])->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/media/folders/999999')->getStatusCode());
        self::assertCount(1, $this->folders()->all());
    }

    public function testTheFolderPagesOpenInTheLibrarysDialog(): void
    {
        $photos = $this->folder('Photos');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $hx = ['HX-Request' => 'true'];

        $library = (string) $this->browser->get('/admin/media?folder=' . $photos->id)->getBody();
        self::assertStringContainsString('href="/admin/media/folders/new?parent=' . $photos->id . '" data-dialog-open', $library);
        self::assertStringContainsString('<a class="admin-media-folder-edit" href="/admin/media/folders/' . $photos->id . '" title="Rename or move “Photos”" data-dialog-open>', $library, 'the pencil of the folder tree');
        self::assertStringNotContainsString('/delete"', $library, 'Delete lives in the folder form');

        $empty = (string) $this->browser->get('/admin/media/folders/' . $photos->id, $hx)->getBody();
        self::assertStringContainsString('<a class="admin-button admin-button-link admin-button-danger" href="/admin/media/folders/' . $photos->id . '/delete" data-dialog-open>', $empty);
        $this->insertFile($photos->id);
        $full = (string) $this->browser->get('/admin/media/folders/' . $photos->id, $hx)->getBody();
        $reason = 'Only an empty folder can be deleted. This one holds 0 folders and 1 file: move or delete them first.';
        self::assertMatchesRegularExpression('~<button type="button" class="admin-button admin-button-link admin-button-danger admin-locked" aria-disabled="true" title="' . preg_quote($reason, '~') . '" aria-describedby="folder-delete-reason"><svg[^>]*>.*?</svg>Delete…</button><span class="admin-visually-hidden" id="folder-delete-reason">' . preg_quote($reason, '~') . '</span>~', $full, 'locked, not disabled: keyboard users reach it and hear why');
        self::assertStringContainsString('aria-disabled="true" title="Only an empty folder can be deleted.', (string) $this->browser->get('/admin/media/folders/' . $photos->id)->getBody(), 'the full page too');
        $this->db->execute('DELETE FROM media');
        self::assertStringContainsString('<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">', $library);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/dialog\.js\?v=~', $library);

        $form = (string) $this->browser->get('/admin/media/folders/new?parent=' . $photos->id, $hx)->getBody();
        self::assertStringNotContainsString('<html', $form);
        self::assertStringContainsString('<h2 id="dialog-title">New folder</h2>', $form);
        self::assertStringContainsString('hx-post="/admin/media/folders" hx-target="closest [data-dialog-content]" hx-swap="innerHTML"', $form);

        $refused = $this->browser->post('/admin/media/folders', ['_csrf' => $this->csrfToken(), 'name' => 'photos', 'parent_id' => ''], $hx);
        self::assertSame(200, $refused->getStatusCode(), 'htmx swaps no error response');
        self::assertStringContainsString('id="folder-name-error">This folder already has a folder with this name.</span>', (string) $refused->getBody());

        $saved = $this->browser->post('/admin/media/folders', ['_csrf' => $this->csrfToken(), 'name' => 'Summer', 'parent_id' => (string) $photos->id], $hx);
        $summer = $this->folders()->all()[1] ?? self::fail('not created');
        self::assertSame('/admin/media?folder=' . $summer->id, $saved->getHeaderLine('HX-Redirect'));

        $stale = $this->browser->post('/admin/media/folders/' . $summer->id, ['_csrf' => $this->csrfToken(), 'name' => 'X', 'parent_id' => '', 'version' => '2020-01-01 00:00:00'], $hx);
        self::assertSame('/admin/media?folder=' . $summer->id, $stale->getHeaderLine('HX-Redirect'), 'a refusal without a field reloads with a toast');

        $confirm = (string) $this->browser->get('/admin/media/folders/' . $photos->id . '/delete', $hx)->getBody();
        self::assertStringContainsString('<h2 id="dialog-title">Delete folder</h2>', $confirm);
        self::assertStringContainsString('hx-post="/admin/media/folders/' . $photos->id . '/delete"', $confirm);
        $delete = $this->browser->post('/admin/media/folders/' . $photos->id . '/delete', ['_csrf' => $this->csrfToken()], $hx);
        self::assertSame('/admin/media?folder=' . $photos->id, $delete->getHeaderLine('HX-Redirect'));
        $body = (string) $this->browser->get('/admin/media?folder=' . $photos->id)->getBody();
        self::assertMatchesRegularExpression('~admin-toast-error[^>]*>.*Someone else changed this folder.*Only an empty folder can be deleted~s', $body);
    }

    private function insertFile(int $folder): void
    {
        $now = $this->clock->now();
        $this->app->container()->service(\AtarCms\Media\MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . '.jpg', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1,
            'width' => 1, 'height' => 1, 'alt' => [], 'uploaded_by' => null, 'folder_id' => $folder, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function folders(): MediaFolderRepository
    {
        return $this->app->container()->service(MediaFolderRepository::class);
    }

    private function folder(string $name, ?int $parent = null): MediaFolder
    {
        return $this->app->container()->service(MediaFolderService::class)->create($name, $parent, null, null);
    }
}
