<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Content\ValidationErrors;
use AtarCms\Database\Connection;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Clock;
use AtarCms\Settings\SiteSettings;
use PDOException;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * The only writer of media rows: uploads, alternative texts and deletion, each audited. A file that entries use
 * (entry_media) cannot be deleted: the row is locked, its usages are counted, and the foreign
 * keys (ON DELETE RESTRICT) refuse the delete if a reference appears in the meantime.
 */
final class MediaService
{
    public const string UPLOAD = 'media.upload';

    public const string DELETE = 'media.delete';

    public const int MAX_ALT = 255;

    public function __construct(
        private readonly Connection $db,
        private readonly MediaRepository $media,
        private readonly MediaUploader $uploader,
        private readonly MediaStorage $storage,
        private readonly Locales $locales,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly SiteSettings $settings,
        private readonly MediaFolderRepository $folders,
    ) {}

    /**
     * @param int|null $folderId the folder to put the file in; null for none ("All files")
     *
     * @throws ValidationFailed on the key "file" (see MediaUploader; validation.media.folder_gone for a deleted folder)
     */
    public function upload(UploadedFileInterface $file, ?User $actor, ?string $ip, ?int $folderId = null): Media
    {
        $stored = $this->uploader->store($file);

        try {
            return $this->db->transaction(function () use ($stored, $actor, $ip, $folderId): Media {
                $now = $this->clock->now();
                $id = $this->media->insert([
                    'path' => $stored->path,
                    'original_name' => $stored->originalName,
                    'mime' => $stored->mime,
                    'size' => $stored->size,
                    'width' => $stored->width,
                    'height' => $stored->height,
                    'alt' => [],
                    'uploaded_by' => $actor?->id,
                    'folder_id' => $folderId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->audit->record('media.uploaded', $actor?->id, 'media', $id, [
                    'name' => $stored->originalName,
                    'mime' => $stored->mime,
                    'size' => $stored->size,
                ], $ip);

                return $this->media->find($id) ?? throw new NotFound(sprintf('Media #%d no longer exists', $id));
            });
        } catch (Throwable $e) {
            $this->removeFiles($stored->path);
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1452) {
                throw new ValidationFailed(['file' => 'validation.media.folder_gone'], ['file' => ['name' => $stored->originalName]]);
            }

            throw $e;
        }
    }

    /**
     * Moves files into a folder (null: none); unknown ids and files already there are skipped, each moved file is
     * audited. The file paths, and so the addresses, do not change.
     *
     * @param list<int> $ids
     *
     * @return int the number of files moved
     *
     * @throws ValidationFailed on "folder" when the folder is gone
     */
    public function move(array $ids, ?int $folderId, ?User $actor, ?string $ip): int
    {
        return $this->db->transaction(function () use ($ids, $folderId, $actor, $ip): int {
            if ($folderId !== null && $this->folders->find($folderId, true) === null) {
                throw new ValidationFailed(['folder' => 'validation.folder.gone']);
            }
            $moving = array_values(array_filter($this->media->byIds($ids), static fn(Media $media): bool => $media->folderId !== $folderId));
            $this->media->moveTo(array_map(static fn(Media $media): int => $media->id, $moving), $folderId);
            foreach ($moving as $media) {
                $this->audit->record('media.moved', $actor?->id, 'media', $media->id, ['name' => $media->originalName, 'from' => $media->folderId, 'to' => $folderId], $ip);
            }

            return count($moving);
        });
    }

    /**
     * Replaces the alternative texts: $alt maps locales to texts; unsupported locales are ignored, empty texts
     * removed. $version is the updated_at the form was opened with (validation.media.stale when it changed).
     *
     * @param array<mixed> $alt
     *
     * @throws ValidationFailed
     */
    public function updateAlt(Media $media, array $alt, string $version, ?User $actor, ?string $ip): Media
    {
        $errors = new ValidationErrors();
        $texts = [];
        foreach ($this->locales->all() as $locale) {
            $text = $alt[$locale] ?? '';
            if (!is_string($text) || !mb_check_encoding($text, 'UTF-8')) {
                $errors->add('alt.' . $locale, 'validation.field.invalid');

                continue;
            }
            $text = trim((string) preg_replace(['/[\x00-\x1F\x7F]+/', '/\s+/u'], ' ', $text));
            if (mb_strlen($text) > self::MAX_ALT) {
                $errors->add('alt.' . $locale, 'validation.field.too_long', ['max' => self::MAX_ALT]);
            }
            if ($text !== '') {
                $texts[$locale] = $text;
            }
        }
        $errors->throwIfAny();

        return $this->db->transaction(function () use ($media, $texts, $version, $actor, $ip): Media {
            $current = $this->media->find($media->id, true) ?? throw new NotFound(sprintf('Media #%d no longer exists', $media->id));
            if (!hash_equals(self::version($current), $version)) {
                throw new ValidationFailed(['media' => 'validation.media.stale']);
            }
            $changed = [];
            foreach (array_unique([...array_keys($current->alt), ...array_keys($texts)]) as $locale) {
                if (($current->alt[$locale] ?? '') !== ($texts[$locale] ?? '')) {
                    $changed[] = $locale;
                }
            }
            if ($changed === []) {
                return $current;
            }
            $this->media->update($current->id, ['alt' => $texts, 'updated_at' => $this->clock->now()]);
            sort($changed);
            $this->audit->record('media.updated', $actor?->id, 'media', $current->id, ['name' => $current->originalName, 'alt' => $changed], $ip);

            return $this->media->find($current->id) ?? throw new NotFound(sprintf('Media #%d no longer exists', $current->id));
        });
    }

    /**
     * @throws ValidationFailed while entries use the file, or while it is the site's sharing image (a setting
     *                          without a foreign key; a settings save racing this delete is accepted)
     * @throws NotFound when the file was deleted in the meantime
     */
    public function delete(Media $media, ?User $actor, ?string $ip): void
    {
        try {
            $this->db->transaction(function () use ($media, $actor, $ip): void {
                // The row lock makes a concurrent entry save wait (its foreign key check needs a shared lock on this
                // row) or makes us wait for it, so the count below sees every committed reference.
                $locked = $this->media->find($media->id, true) ?? throw new NotFound(sprintf('Media #%d no longer exists', $media->id));
                $count = $this->media->usageCount($locked->id);
                if ($count > 0) {
                    throw new ValidationFailed(['media' => 'validation.media.in_use'], ['media' => ['count' => $count]]);
                }
                if (isset($this->settings->mediaUses()[$locked->id])) {
                    throw new ValidationFailed(['media' => 'validation.media.in_use_settings']);
                }
                $this->media->delete($locked->id);
                $this->audit->record('media.deleted', $actor?->id, 'media', $locked->id, ['name' => $locked->originalName, 'path' => $locked->path], $ip);
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                throw new ValidationFailed(['media' => 'validation.media.in_use'], ['media' => ['count' => max(1, $this->media->usageCount($media->id))]]);
            }

            throw $e;
        }
        $this->removeFiles($media->path);
    }

    /**
     * Files that cannot be removed stay behind as orphans: that must neither mask the error that led here nor
     * turn a committed delete into a failure.
     */
    private function removeFiles(string $path): void
    {
        try {
            $this->storage->delete($path);
        } catch (Throwable) {
            // Orphaned files are harmless (random names, never referenced).
        }
    }

    public static function version(Media $media): string
    {
        return $media->updatedAt->format('Y-m-d H:i:s');
    }
}
