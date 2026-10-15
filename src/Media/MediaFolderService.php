<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Content\ValidationErrors;
use AtarCms\Database\Connection;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Kernel\Clock;
use PDOException;

/**
 * The only writer of media folders: create, rename and move (one update), delete; each audited. Sibling names are
 * unique ignoring case and accents (the column's collation, backed by a unique index), a folder never moves into
 * itself or a subfolder, and only an empty folder (no subfolders, no files) can be deleted.
 */
final class MediaFolderService
{
    public const int MAX_NAME = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly MediaFolderRepository $folders,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws ValidationFailed on "name" or "parent_id"
     */
    public function create(mixed $name, ?int $parentId, ?User $actor, ?string $ip): MediaFolder
    {
        $errors = new ValidationErrors();
        $name = self::name($name, $errors);
        $errors->throwIfAny();

        return $this->unique(fn(): MediaFolder => $this->db->transaction(function () use ($name, $parentId, $actor, $ip): MediaFolder {
            if ($parentId !== null && $this->folders->find($parentId, true) === null) {
                throw new ValidationFailed(['parent_id' => 'validation.folder.gone']);
            }
            if ($this->folders->nameTaken($parentId, $name)) {
                throw new ValidationFailed(['name' => 'validation.folder.taken']);
            }
            $now = $this->clock->now();
            $id = $this->folders->insert(['parent_id' => $parentId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            $this->audit->record('media_folder.created', $actor?->id, 'media_folder', $id, ['name' => $name, 'parent' => $parentId], $ip);

            return $this->folders->find($id) ?? throw new NotFound(sprintf('Media folder #%d no longer exists', $id));
        }));
    }

    /**
     * Renames and moves a folder; $version is the updated_at the form was opened with.
     *
     * @throws ValidationFailed on "name", "parent_id" or "folder" (stale)
     * @throws NotFound when the folder is gone
     */
    public function update(MediaFolder $folder, mixed $name, ?int $parentId, string $version, ?User $actor, ?string $ip): MediaFolder
    {
        $errors = new ValidationErrors();
        $name = self::name($name, $errors);
        $errors->throwIfAny();

        return $this->unique(fn(): MediaFolder => $this->db->transaction(function () use ($folder, $name, $parentId, $version, $actor, $ip): MediaFolder {
            $current = $this->folders->find($folder->id, true) ?? throw new NotFound(sprintf('Media folder #%d no longer exists', $folder->id));
            if (!hash_equals(self::version($current), $version)) {
                throw new ValidationFailed(['folder' => 'validation.folder.stale']);
            }
            // Walk up from the new parent with locking reads: each step reads the latest committed row and keeps it,
            // so a concurrent move cannot build a cycle past this check. A cycle already in the data ends the walk.
            $step = $parentId;
            $seen = [];
            while ($step !== null && !isset($seen[$step])) {
                if ($step === $current->id) {
                    throw new ValidationFailed(['parent_id' => 'validation.folder.cycle']);
                }
                $seen[$step] = true;
                $ancestor = $this->folders->find($step, true);
                if ($ancestor === null && $step === $parentId) {
                    throw new ValidationFailed(['parent_id' => 'validation.folder.gone']);
                }
                $step = $ancestor?->parentId;
            }
            if ($this->folders->nameTaken($parentId, $name, $current->id)) {
                throw new ValidationFailed(['name' => 'validation.folder.taken']);
            }
            $changed = [];
            if ($name !== $current->name) {
                $changed['name'] = $name;
            }
            if ($parentId !== $current->parentId) {
                $changed['parent_id'] = $parentId;
            }
            if ($changed === []) {
                return $current;
            }
            $this->folders->update($current->id, [...$changed, 'updated_at' => $this->clock->now()]);
            $meta = ['name' => $name];
            if (isset($changed['name'])) {
                $meta['from'] = $current->name;
            }
            if (array_key_exists('parent_id', $changed)) {
                $meta['parent'] = $parentId;
                $meta['from_parent'] = $current->parentId;
            }
            $this->audit->record('media_folder.updated', $actor?->id, 'media_folder', $current->id, $meta, $ip);

            return $this->folders->find($current->id) ?? throw new NotFound(sprintf('Media folder #%d no longer exists', $current->id));
        }));
    }

    /**
     * @throws ValidationFailed on "folder" while it holds subfolders or files
     * @throws NotFound when the folder is gone
     */
    public function delete(MediaFolder $folder, ?User $actor, ?string $ip): void
    {
        try {
            $this->db->transaction(function () use ($folder, $actor, $ip): void {
                $locked = $this->folders->find($folder->id, true) ?? throw new NotFound(sprintf('Media folder #%d no longer exists', $folder->id));
                $this->refuseIfNotEmpty($locked->id);
                $this->folders->delete($locked->id);
                $this->audit->record('media_folder.deleted', $actor?->id, 'media_folder', $locked->id, ['name' => $locked->name], $ip);
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                $this->refuseIfNotEmpty($folder->id);
            }

            throw $e;
        }
    }

    public static function version(MediaFolder $folder): string
    {
        return $folder->updatedAt->format('Y-m-d H:i:s');
    }

    private function refuseIfNotEmpty(int $id): void
    {
        $folders = $this->folders->childCount($id);
        $files = $this->folders->fileCount($id);
        if ($folders > 0 || $files > 0) {
            throw new ValidationFailed(['folder' => 'validation.folder.not_empty'], ['folder' => ['folders' => $folders, 'files' => $files]]);
        }
    }

    /**
     * A duplicate name that slipped past the check (a concurrent save) hits the unique index: the same message.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function unique(callable $write): mixed
    {
        try {
            return $write();
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ValidationFailed(['name' => 'validation.folder.taken']);
            }

            throw $e;
        }
    }

    private static function name(mixed $name, ValidationErrors $errors): string
    {
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            $errors->add('name', 'validation.field.invalid');

            return '';
        }
        $name = trim((string) preg_replace(['/[\x00-\x1F\x7F]+/', '/\s+/u'], ' ', $name));
        if ($name === '') {
            $errors->add('name', 'validation.field.required');
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors->add('name', 'validation.field.too_long', ['max' => self::MAX_NAME]);
        }

        return $name;
    }
}
