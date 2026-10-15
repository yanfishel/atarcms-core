<?php

declare(strict_types=1);

namespace AtarCms\Media;

/**
 * Every folder of the library, loaded at once (MediaFolderRepository::all()): children per parent in name order,
 * trails for breadcrumbs and the options of a folder select.
 */
final readonly class MediaFolderTree
{
    /** @var array<int, MediaFolder> */
    private array $folders;

    /** @var array<int, list<MediaFolder>> parent id (0 = the root) => children by name */
    private array $children;

    /**
     * @param list<MediaFolder> $folders ordered by name
     */
    public function __construct(array $folders)
    {
        $byId = [];
        $children = [];
        foreach ($folders as $folder) {
            $byId[$folder->id] = $folder;
            $children[$folder->parentId ?? 0][] = $folder;
        }
        $this->folders = $byId;
        $this->children = $children;
    }

    public function find(?int $id): ?MediaFolder
    {
        return $id === null ? null : ($this->folders[$id] ?? null);
    }

    /**
     * @param int|null $id null for the root
     *
     * @return list<MediaFolder>
     */
    public function children(?int $id): array
    {
        return $this->children[$id ?? 0] ?? [];
    }

    /**
     * @return list<MediaFolder> the folder's ancestors from the top level down, then the folder; [] for the root or an
     *                           unknown id
     */
    public function trail(?int $id): array
    {
        $trail = [];
        $seen = [];
        $folder = $this->find($id);
        while ($folder !== null && !isset($seen[$folder->id])) {
            $seen[$folder->id] = true;
            array_unshift($trail, $folder);
            $folder = $this->find($folder->parentId);
        }

        return $trail;
    }

    /**
     * @return list<array{folder: MediaFolder, depth: int}> every folder depth-first, children by name
     */
    public function options(): array
    {
        $options = [];
        $walk = function (?int $parent, int $depth) use (&$walk, &$options): void {
            foreach ($this->children($parent) as $folder) {
                $options[] = ['folder' => $folder, 'depth' => $depth];
                $walk($folder->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $options;
    }

    /**
     * @return list<int> $id and the ids of its subfolders at every level
     */
    public function withDescendants(int $id): array
    {
        $ids = [];
        $seen = [];
        $walk = function (int $id) use (&$walk, &$ids, &$seen): void {
            if (isset($seen[$id])) {
                return; // corrupt data (a cycle): never recurse forever
            }
            $seen[$id] = true;
            $ids[] = $id;
            foreach ($this->children($id) as $child) {
                $walk($child->id);
            }
        };
        $walk($id);

        return $ids;
    }

    public function isEmpty(): bool
    {
        return $this->folders === [];
    }
}
