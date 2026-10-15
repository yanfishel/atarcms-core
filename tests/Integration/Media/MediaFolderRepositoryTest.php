<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Media;

use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaQuery;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use PDOException;

final class MediaFolderRepositoryTest extends ContentTestCase
{
    public function testListsFoldersByNameAndCountsChildren(): void
    {
        $repository = new MediaFolderRepository($this->db);
        $a = $this->folder('A');
        $b = $this->folder('B', $a);
        $this->folder('C', $b);
        $this->folder('D', $a);
        $this->folder('Other');

        self::assertSame(['A', 'B', 'C', 'D', 'Other'], array_map(static fn(MediaFolder $f): string => $f->name, $repository->all()));
        self::assertSame($a, $repository->find($b)?->parentId);
        self::assertSame(2, $repository->childCount($a));
    }

    public function testSiblingNamesAreUniqueIgnoringCaseAndAccents(): void
    {
        $repository = new MediaFolderRepository($this->db);
        $a = $this->folder('Café');
        self::assertTrue($repository->nameTaken(null, 'cafe'));
        self::assertFalse($repository->nameTaken(null, 'cafe', $a), 'the folder itself does not count');
        self::assertFalse($repository->nameTaken($a, 'Café'));
        $this->folder('Café', $a);

        $this->expectException(PDOException::class);
        $this->folder('CAFE');
    }

    public function testAFolderWithFilesOrSubfoldersCannotBeDeletedByTheDatabase(): void
    {
        $repository = new MediaFolderRepository($this->db);
        $a = $this->folder('A');
        $this->insertMedia(['folder_id' => $a]);
        self::assertSame(1, $repository->fileCount($a));
        self::assertSame($a, $this->mediaRepository()->page(new MediaQuery())->items[0]->folderId);

        try {
            $repository->delete($a);
            self::fail('A folder with a file was deleted');
        } catch (PDOException $e) {
            self::assertSame(1451, $e->errorInfo[1] ?? null);
        }
        $b = $this->folder('B');
        $this->folder('C', $b);
        try {
            $repository->delete($b);
            self::fail('A folder with a subfolder was deleted');
        } catch (PDOException $e) {
            self::assertSame(1451, $e->errorInfo[1] ?? null);
        }
    }

    private function folder(string $name, ?int $parent = null): int
    {
        $now = $this->clock->now();

        return new MediaFolderRepository($this->db)->insert(['parent_id' => $parent, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
    }
}
