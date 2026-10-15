<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Media;

use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderService;
use AtarCms\Media\MediaRepository;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * Browsing the library like a file manager: the current folder's subfolders open the results (tiles in the grid,
 * rows in the list, navigation only, the picker too); a search, the unused filter or a later page hides them. A
 * video's item page plays it.
 */
final class MediaBrowseTest extends ContentFunctionalTestCase
{
    public function testTheResultsStartWithTheCurrentFoldersSubfolders(): void
    {
        $demo = $this->folder('Demo');
        $this->insertMedia('waves.mp4', 'video/mp4');
        $this->insertMedia('inside.jpg', 'image/jpeg', $demo->id);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $grid = (string) $this->browser->get('/admin/media')->getBody();
        self::assertMatchesRegularExpression(
            '~<a class="admin-media-card admin-media-folder-tile" href="[^"]*folder=' . $demo->id . '[^"]*">\s*<span class="admin-media-thumb admin-media-file admin-media-folder-thumb">~',
            $grid,
            'the Demo folder is a tile before the files',
        );
        self::assertStringContainsString('waves.mp4', $grid);
        self::assertStringNotContainsString('inside.jpg', $grid, 'the subfolder\'s files stay inside it');

        $list = (string) $this->browser->get('/admin/media?view=list')->getBody();
        self::assertMatchesRegularExpression(
            '~<a class="admin-media-name admin-media-folder-name" href="[^"]*folder=' . $demo->id . '[^"]*">Demo</a>~',
            $list,
            'the list view shows the folder as a row',
        );
        self::assertMatchesRegularExpression('~<td[^>]*>Folder</td>~', $list);

        $open = (string) $this->browser->get('/admin/media?folder=' . $demo->id)->getBody();
        self::assertStringNotContainsString('admin-media-folder-tile', $open, 'Demo has no subfolders');
        self::assertStringContainsString('inside.jpg', $open);
    }

    public function testASearchTheUnusedFilterAndALaterPageHideTheFolders(): void
    {
        $this->folder('Demo');
        $this->insertMedia('waves.mp4', 'video/mp4');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $search = (string) $this->browser->get('/admin/media?q=waves')->getBody();
        self::assertStringNotContainsString('admin-media-folder-tile', $search, 'a search already covers the subfolders');
        self::assertStringContainsString('waves.mp4', $search);

        self::assertStringNotContainsString(
            'admin-media-folder-tile',
            (string) $this->browser->get('/admin/media?unused=1')->getBody(),
            'the unused filter lists files only',
        );
        self::assertStringNotContainsString(
            'admin-media-folder-tile',
            (string) $this->browser->get('/admin/media?page=2')->getBody(),
            'only the first page starts with the folders',
        );
    }

    public function testThePickerShowsTheFoldersForNavigationOnly(): void
    {
        $demo = $this->folder('Demo');
        $this->insertMedia('waves.mp4', 'video/mp4');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $picker = (string) $this->browser->get('/admin/media/picker')->getBody();
        self::assertMatchesRegularExpression(
            '~<a class="admin-media-card admin-media-folder-tile" href="[^"]*folder=' . $demo->id . '[^"]*" hx-get="[^"]*" hx-target="closest \[data-media-picker\]" hx-swap="outerHTML">~',
            $picker,
            'the folder tile navigates the picker through htmx',
        );
        self::assertSame(1, substr_count($picker, 'data-media-pick='), 'the folder is no choice: only the file has a checkbox');
    }

    public function testAVideoItemPageHasAPlayerAndAnImagePageHasNone(): void
    {
        $video = $this->insertMedia('waves.mp4', 'video/mp4');
        $image = $this->insertMedia('photo.jpg', 'image/jpeg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $page = (string) $this->browser->get('/admin/media/' . $video)->getBody();
        self::assertMatchesRegularExpression(
            '~<video class="admin-media-player" controls preload="metadata" src="/uploads/[^"]+\.mp4"></video>~',
            $page,
            'the video plays on its own page',
        );

        self::assertStringNotContainsString('<video', (string) $this->browser->get('/admin/media/' . $image)->getBody());
    }

    private function folder(string $name): MediaFolder
    {
        return $this->app->container()->service(MediaFolderService::class)->create($name, null, null, null);
    }

    private function insertMedia(string $name, string $mime, ?int $folder = null): int
    {
        $now = $this->clock->now();
        $extension = ['video/mp4' => '.mp4', 'application/pdf' => '.pdf'][$mime] ?? '.jpg';
        $image = str_starts_with($mime, 'image/');

        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . $extension,
            'original_name' => $name,
            'mime' => $mime,
            'size' => 100,
            'width' => $image ? 64 : null,
            'height' => $image ? 48 : null,
            'alt' => [],
            'uploaded_by' => null,
            'folder_id' => $folder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
