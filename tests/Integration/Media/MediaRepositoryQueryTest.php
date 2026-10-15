<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Media;

use AtarCms\Media\Media;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaQuery;
use AtarCms\Tests\Integration\Content\ContentTestCase;

final class MediaRepositoryQueryTest extends ContentTestCase
{
    public function testFolderScopeAndDescendants(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B', $a);
        $root = $this->insertMedia();
        $inA = $this->insertMedia(['folder_id' => $a]);
        $inB = $this->insertMedia(['folder_id' => $b]);

        self::assertSame([$root], $this->ids(MediaQuery::inFolder(null)));
        self::assertSame([$inA], $this->ids(MediaQuery::inFolder($a)));
        self::assertSame([$inB, $inA], $this->ids(new MediaQuery(folderIds: [$a, $b])));
        self::assertSame([$inB, $root], $this->ids(new MediaQuery(folderIds: [$b], root: true)));
        self::assertSame([], $this->ids(new MediaQuery(folderIds: [])));
    }

    public function testSearchEverywhereIgnoresTheFolder(): void
    {
        $a = $this->folder('A');
        $sea = $this->insertMedia(['original_name' => 'sea.jpg', 'folder_id' => $a]);
        $sea2 = $this->insertMedia(['original_name' => 'sea2.jpg']);
        $this->insertMedia(['original_name' => 'cat.jpg']);

        self::assertSame([$sea2, $sea], $this->ids(new MediaQuery(search: 'sea')));
        self::assertSame([$sea], $this->ids(new MediaQuery(folderIds: [$a], search: 'sea')));
    }

    public function testTypeFilterSplitsImagesAndDocuments(): void
    {
        $pdf = $this->insertMedia(['mime' => 'application/pdf', 'width' => null, 'height' => null]);
        $jpg = $this->insertMedia();
        $mp4 = $this->insertMedia(['path' => '2026/09/' . bin2hex(random_bytes(16)) . '.mp4', 'mime' => 'video/mp4', 'width' => null, 'height' => null]);

        self::assertSame([$jpg], $this->ids(new MediaQuery(type: MediaQuery::TYPE_IMAGE)));
        self::assertSame([$mp4], $this->ids(new MediaQuery(type: MediaQuery::TYPE_VIDEO)));
        self::assertSame([$pdf], $this->ids(new MediaQuery(type: MediaQuery::TYPE_DOCUMENT)), 'a video is not a document');
        self::assertEqualsCanonicalizing([$jpg, $pdf, $mp4], $this->ids(new MediaQuery(type: MediaQuery::TYPE_ALL)));
    }

    public function testSortsByNewestOldestNameAndSize(): void
    {
        $b = $this->insertMedia(['original_name' => 'b.jpg', 'size' => 300]);
        $a = $this->insertMedia(['original_name' => 'A.jpg', 'size' => 100]);
        $c = $this->insertMedia(['original_name' => 'c.jpg', 'size' => 200]);

        self::assertSame([$c, $a, $b], $this->ids(new MediaQuery(sort: 'newest')));
        self::assertSame([$b, $a, $c], $this->ids(new MediaQuery(sort: 'oldest')));
        self::assertSame([$a, $b, $c], $this->ids(new MediaQuery(sort: 'name')), 'names compare ignoring case');
        self::assertSame([$b, $c, $a], $this->ids(new MediaQuery(sort: 'size')), 'largest first');
    }

    public function testUnusedExcludesReferencedAndSettingsImages(): void
    {
        $field = $this->insertMedia();
        $settings = $this->insertMedia();
        $free = $this->insertMedia();
        $this->mediaRepository()->replaceEntryReferences($this->insertEntry(['slug' => 'one']), [['field' => 'cover', 'mediaId' => $field]]);

        self::assertSame([$free, $settings], $this->ids(new MediaQuery(unused: true)));
        self::assertSame([$free], $this->ids(new MediaQuery(unused: true, alsoUsed: [$settings])));
    }

    public function testUsageCountsForAPageInOneQuery(): void
    {
        $photo = $this->insertMedia();
        $other = $this->insertMedia();
        $entry = $this->insertEntry(['slug' => 'one']);
        $this->mediaRepository()->replaceEntryReferences($entry, [['field' => 'cover', 'mediaId' => $photo], ['field' => 'images', 'mediaId' => $photo]]);

        self::assertSame([$photo => 2, $other => 0], $this->mediaRepository()->usageCounts([$photo, $other, $photo]));
        self::assertSame([], $this->mediaRepository()->usageCounts([]));
    }

    /**
     * @return list<int>
     */
    private function ids(MediaQuery $query): array
    {
        return array_map(static fn(Media $media): int => $media->id, $this->mediaRepository()->page($query)->items);
    }

    private function folder(string $name, ?int $parent = null): int
    {
        $now = $this->clock->now();

        return new MediaFolderRepository($this->db)->insert(['parent_id' => $parent, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
    }
}
