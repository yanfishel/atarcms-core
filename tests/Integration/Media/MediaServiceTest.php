<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Media;

use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\SettingsInput;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

final class MediaServiceTest extends ContentTestCase
{
    use TempDirectory;

    private MediaStorage $storage;

    private MediaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->mediaStorage();
        $this->service = $this->mediaService($this->storage);
    }

    public function testUploadRecordsTheFileAndAudits(): void
    {
        $user = $this->user();
        $media = $this->service->upload(TestImages::uploaded(TestImages::jpeg(120, 80), 'Кот <b>.jpg', 'image/jpeg'), $user, '203.0.113.7');

        self::assertSame(['Кот <b>.jpg', 'image/jpeg', 120, 80, $user->id], [$media->originalName, $media->mime, $media->width, $media->height, $media->uploadedBy]);
        self::assertSame([], $media->alt);
        self::assertFileExists($this->storage->original($media->path));
        self::assertEquals($media, $this->mediaRepository()->find($media->id));
        $audit = $this->db->selectOne('SELECT action, user_id, subject_type, subject_id, meta, ip FROM audit_log');
        self::assertSame('media.uploaded', $audit['action'] ?? null);
        self::assertSame(['media', (string) $media->id, '203.0.113.7'], [$audit['subject_type'], $audit['subject_id'], $audit['ip']]);
        self::assertSame(['name' => 'Кот <b>.jpg', 'mime' => 'image/jpeg', 'size' => $media->size], json_decode(Cast::string($audit['meta']), true));
    }

    public function testARejectedUploadWritesNothing(): void
    {
        try {
            $this->service->upload(TestImages::uploaded('<?php phpinfo();', 'shell.jpg', 'image/jpeg'), null, null);
            self::fail('The script was stored');
        } catch (ValidationFailed $e) {
            self::assertSame(['file' => 'validation.media.type'], $e->errors);
        }

        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
        self::assertSame([], $this->auditActions());
    }

    public function testUpdatesAltTextPerLocale(): void
    {
        $media = $this->service->upload(TestImages::uploaded(TestImages::png(), 'sea.png', 'image/png'), null, null);
        $this->clock->advance('+1 minute');

        $updated = $this->service->updateAlt($media, ['en' => "  The\tsea \n", 'ru' => 'Море', 'de' => 'Meer', 'fr' => ''], MediaService::version($media), null, null);

        self::assertSame(['en' => 'The sea', 'ru' => 'Море'], $updated->alt);
        self::assertSame('2026-09-28 12:01:00', MediaService::version($updated));
        self::assertSame(['media.uploaded', 'media.updated'], $this->auditActions());
        self::assertSame(['name' => 'sea.png', 'alt' => ['en', 'ru']], json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'media.updated'")), true));

        $this->service->updateAlt($updated, ['en' => 'The sea', 'ru' => 'Море'], MediaService::version($updated), null, null);
        self::assertSame(['media.uploaded', 'media.updated'], $this->auditActions(), 'an unchanged save writes no audit row');

        $cleared = $this->service->updateAlt($updated, ['en' => '', 'ru' => 'Море'], MediaService::version($updated), null, null);
        self::assertSame(['ru' => 'Море'], $cleared->alt);
    }

    public function testRejectsLongAltTextsAndStaleForms(): void
    {
        $media = $this->service->upload(TestImages::uploaded(TestImages::png(), 'sea.png', 'image/png'), null, null);

        self::assertValidation(
            ['alt.ru' => 'validation.field.too_long'],
            fn() => $this->service->updateAlt($media, ['ru' => str_repeat('я', 256)], MediaService::version($media), null, null),
            ['alt.ru' => ['max' => 255]],
        );
        self::assertValidation(
            ['media' => 'validation.media.stale'],
            fn() => $this->service->updateAlt($media, ['en' => 'Sea'], '2026-01-01 00:00:00', null, null),
        );
        self::assertSame([], $this->mediaRepository()->find($media->id)?->alt);
    }

    public function testDeletesTheRowTheOriginalAndItsVariants(): void
    {
        $media = $this->service->upload(TestImages::uploaded(TestImages::jpeg(), 'x.jpg', 'image/jpeg'), null, null);
        $this->storage->writeVariant('thumb', $media->path . '.webp', 'variant');

        $this->service->delete($media, null, null);

        self::assertNull($this->mediaRepository()->find($media->id));
        self::assertFileDoesNotExist($this->storage->original($media->path));
        self::assertFileDoesNotExist($this->storage->variant('thumb', $media->path . '.webp'));
        self::assertSame(['media.uploaded', 'media.deleted'], $this->auditActions());
        self::assertSame(['name' => 'x.jpg', 'path' => $media->path], json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'media.deleted'")), true));
    }

    public function testRefusesToDeleteUsedMedia(): void
    {
        $media = $this->service->upload(TestImages::uploaded(TestImages::jpeg(), 'x.jpg', 'image/jpeg'), null, null);
        $first = $this->insertEntry(['slug' => 'first']);
        $this->mediaRepository()->replaceEntryReferences($first, [['field' => 'cover', 'mediaId' => $media->id]]);
        $this->mediaRepository()->replaceEntryReferences($this->insertEntry(['slug' => 'second']), [['field' => 'cover', 'mediaId' => $media->id]]);

        self::assertValidation(['media' => 'validation.media.in_use'], fn() => $this->service->delete($media, null, null), ['media' => ['count' => 2]]);
        self::assertNotNull($this->mediaRepository()->find($media->id));
        self::assertFileExists($this->storage->original($media->path));
        self::assertSame(['media.uploaded'], $this->auditActions());

        $this->mediaRepository()->replaceEntryReferences($first, []);
        self::assertValidation(['media' => 'validation.media.in_use'], fn() => $this->service->delete($media, null, null), ['media' => ['count' => 1]]);
    }

    public function testRefusesDeletingTheSiteSharingImage(): void
    {
        $media = $this->service->upload(TestImages::uploaded(TestImages::jpeg(), 'x.jpg', 'image/jpeg'), null, null);
        $this->settingsService()->save('en', new SettingsInput(ogImageId: $media->id), null, null);

        self::assertValidation(['media' => 'validation.media.in_use_settings'], fn() => $this->service->delete($media, null, null));
        self::assertNotNull($this->mediaRepository()->find($media->id));

        $this->settingsService()->save('en', new SettingsInput(), null, null);
        $this->service->delete($media, null, null);
        self::assertNull($this->mediaRepository()->find($media->id));
    }

    public function testRefusesDeletingTheSiteLogoAndIcon(): void
    {
        $logo = $this->service->upload(TestImages::uploaded(TestImages::jpeg(), 'logo.jpg', 'image/jpeg'), null, null);
        $icon = $this->service->upload(TestImages::uploaded(TestImages::png(512, 512), 'icon.png', 'image/png'), null, null);
        $this->settingsService()->save('en', new SettingsInput(logoId: $logo->id, iconId: $icon->id), null, null);

        self::assertValidation(['media' => 'validation.media.in_use_settings'], fn() => $this->service->delete($logo, null, null));
        self::assertValidation(['media' => 'validation.media.in_use_settings'], fn() => $this->service->delete($icon, null, null));
        self::assertNotNull($this->mediaRepository()->find($logo->id));
        self::assertNotNull($this->mediaRepository()->find($icon->id));
    }

    public function testUploadsIntoAFolder(): void
    {
        $folder = $this->folder('Photos');
        $media = $this->service->upload(TestImages::uploaded(TestImages::jpeg(), 'x.jpg', 'image/jpeg'), null, null, $folder);

        self::assertSame($folder, $media->folderId);
        self::assertValidation(
            ['file' => 'validation.media.folder_gone'],
            fn() => $this->service->upload(TestImages::uploaded(TestImages::jpeg(), 'y.jpg', 'image/jpeg'), null, null, $folder + 1),
            ['file' => ['name' => 'y.jpg']],
        );
        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
    }

    public function testMovesFilesAndAuditsEachMove(): void
    {
        $folder = $this->folder('Photos');
        $a = $this->insertMedia(['original_name' => 'a.jpg']);
        $b = $this->insertMedia(['original_name' => 'b.jpg', 'folder_id' => $folder]);
        $path = $this->mediaRepository()->find($a)?->path;

        self::assertSame(1, $this->service->move([$a, $b, 999], $folder, null, '203.0.113.7'), 'a file already there and an unknown id are skipped');
        self::assertSame($folder, $this->mediaRepository()->find($a)?->folderId);
        self::assertSame($path, $this->mediaRepository()->find($a)->path, 'the address does not change');
        self::assertSame(['media.moved'], $this->auditActions());
        self::assertSame(['name' => 'a.jpg', 'from' => null, 'to' => $folder], json_decode(Cast::string($this->db->scalar('SELECT meta FROM audit_log')), true));

        self::assertSame(2, $this->service->move([$a, $b], null, null, null));
        self::assertNull($this->mediaRepository()->find($b)?->folderId);
        self::assertValidation(['folder' => 'validation.folder.gone'], fn() => $this->service->move([$a], $folder + 1, null, null));
    }

    private function folder(string $name): int
    {
        $now = $this->clock->now();

        return new MediaFolderRepository($this->db)->insert(['parent_id' => null, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
    }
}
