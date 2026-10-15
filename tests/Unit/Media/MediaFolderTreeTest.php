<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderTree;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MediaFolderTreeTest extends TestCase
{
    public function testTrailOptionsAndChildrenFollowTheGivenOrder(): void
    {
        $tree = new MediaFolderTree([
            self::folder(3, 'Archive', null),
            self::folder(1, 'Photos', null),
            self::folder(4, 'Old', 1),
            self::folder(2, 'Summer', 1),
            self::folder(5, 'Beach', 2),
        ]);

        self::assertSame([3, 1], self::ids($tree->children(null)));
        self::assertSame([4, 2], self::ids($tree->children(1)));
        self::assertSame([1, 2, 5], self::ids($tree->trail(5)));
        self::assertSame([], $tree->trail(null));
        self::assertSame([], $tree->trail(99));
        self::assertSame(
            [[3, 0], [1, 0], [4, 1], [2, 1], [5, 2]],
            array_map(static fn(array $option): array => [$option['folder']->id, $option['depth']], $tree->options()),
        );
        self::assertSame([1, 4, 2, 5], $tree->withDescendants(1));
        self::assertNull($tree->find(99));
        self::assertFalse($tree->isEmpty());
        self::assertTrue(new MediaFolderTree([])->isEmpty());
    }

    public function testCorruptCyclesNeverRecurseForever(): void
    {
        $tree = new MediaFolderTree([self::folder(1, 'A', 2), self::folder(2, 'B', 1)]);

        self::assertSame([1, 2], $tree->withDescendants(1));
        self::assertSame([2, 1], array_map(static fn(MediaFolder $folder): int => $folder->id, $tree->trail(1)));
    }

    /**
     * @param list<MediaFolder> $folders
     *
     * @return list<int>
     */
    private static function ids(array $folders): array
    {
        return array_map(static fn(MediaFolder $folder): int => $folder->id, $folders);
    }

    private static function folder(int $id, string $name, ?int $parent): MediaFolder
    {
        $now = new DateTimeImmutable('2026-10-03 12:00:00');

        return new MediaFolder($id, $parent, $name, $now, $now);
    }
}
