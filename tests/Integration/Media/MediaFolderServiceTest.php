<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Media;

use AtarCms\Auth\AuditLog;
use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaFolderService;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use AtarCms\Tests\Support\Cast;

final class MediaFolderServiceTest extends ContentTestCase
{
    private MediaFolderService $service;

    private MediaFolderRepository $folders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folders = new MediaFolderRepository($this->db);
        $this->service = new MediaFolderService($this->db, $this->folders, new AuditLog($this->db, $this->clock), $this->clock);
    }

    public function testCreatesAFolderAndAuditsIt(): void
    {
        $user = $this->user();
        $photos = $this->service->create("  Photos \t of\nsummer ", null, $user, '203.0.113.7');
        $inner = $this->service->create('Beach', $photos->id, null, null);

        self::assertSame(['Photos of summer', null], [$photos->name, $photos->parentId]);
        self::assertSame($photos->id, $inner->parentId);
        self::assertSame(['media_folder.created', 'media_folder.created'], $this->auditActions());
        $audit = $this->db->selectOne('SELECT user_id, subject_type, subject_id, meta, ip FROM audit_log ORDER BY id LIMIT 1');
        self::assertNotNull($audit);
        self::assertSame([(string) $user->id, 'media_folder', (string) $photos->id, '203.0.113.7'], [Cast::string($audit['user_id']), $audit['subject_type'], $audit['subject_id'], $audit['ip']]);
        self::assertSame(['name' => 'Photos of summer', 'parent' => null], json_decode(Cast::string($audit['meta']), true));
    }

    public function testRefusesEmptyLongAndDuplicateNames(): void
    {
        $cafe = $this->service->create('Café', null, null, null);

        self::assertValidation(['name' => 'validation.field.required'], fn() => $this->service->create("  \n", null, null, null));
        self::assertValidation(['name' => 'validation.field.invalid'], fn() => $this->service->create(['x'], null, null, null));
        self::assertValidation(['name' => 'validation.field.too_long'], fn() => $this->service->create(str_repeat('я', 101), null, null, null), ['name' => ['max' => 100]]);
        self::assertValidation(['name' => 'validation.folder.taken'], fn() => $this->service->create('CAFE', null, null, null));
        self::assertValidation(['parent_id' => 'validation.folder.gone'], fn() => $this->service->create('X', 999, null, null));
        self::assertSame('Café', $this->service->create('Café', $cafe->id, null, null)->name, 'another parent may use the name');
        self::assertCount(2, $this->folders->all());
    }

    public function testRenamesAndMovesInOneUpdate(): void
    {
        $a = $this->service->create('A', null, null, null);
        $b = $this->service->create('B', null, null, null);
        $this->clock->advance('+1 minute');

        $moved = $this->service->update($b, 'B2', $a->id, MediaFolderService::version($b), null, null);

        self::assertSame(['B2', $a->id], [$moved->name, $moved->parentId]);
        self::assertSame(['name' => 'B2', 'from' => 'B', 'parent' => $a->id, 'from_parent' => null], json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'media_folder.updated'")), true));

        $same = $this->service->update($moved, 'B2', $a->id, MediaFolderService::version($moved), null, null);
        self::assertEquals($moved, $same);
        self::assertSame(1, Cast::int($this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'media_folder.updated'")), 'an unchanged save is not audited');

        $back = $this->service->update($moved, 'b2', null, MediaFolderService::version($moved), null, null);
        self::assertSame(['b2', null], [$back->name, $back->parentId], 'a change of case alone is a rename');
    }

    public function testRefusesMovingAFolderIntoItselfOrADescendant(): void
    {
        $a = $this->service->create('A', null, null, null);
        $b = $this->service->create('B', $a->id, null, null);
        $c = $this->service->create('C', $b->id, null, null);
        $this->service->create('C', null, null, null);

        self::assertValidation(['parent_id' => 'validation.folder.cycle'], fn() => $this->service->update($a, 'A', $a->id, MediaFolderService::version($a), null, null));
        self::assertValidation(['parent_id' => 'validation.folder.cycle'], fn() => $this->service->update($a, 'A', $c->id, MediaFolderService::version($a), null, null));
        self::assertValidation(['name' => 'validation.folder.taken'], fn() => $this->service->update($c, 'C', null, MediaFolderService::version($c), null, null));
        self::assertSame($b->id, $this->folders->find($c->id)?->parentId);
    }

    /**
     * The new parent's ancestors are read with row locks, each step the latest committed row, so a concurrent move
     * cannot slip a cycle past the check; a cycle already in the data (not through the moved folder) ends the walk.
     */
    public function testTheAncestorWalkRefusesCyclesAndEndsOnCorruptData(): void
    {
        $a = $this->service->create('A', null, null, null);
        $b = $this->service->create('B', $a->id, null, null);
        $x = $this->service->create('X', null, null, null);
        $y = $this->service->create('Y', $x->id, null, null);
        $this->db->execute('UPDATE media_folders SET parent_id = ? WHERE id = ?', [$y->id, $x->id]);
        $moved = $this->service->create('M', null, null, null);

        self::assertValidation(['parent_id' => 'validation.folder.cycle'], fn() => $this->service->update($a, 'A', $b->id, MediaFolderService::version($a), null, null));
        self::assertSame($y->id, $this->service->update($moved, 'M', $y->id, MediaFolderService::version($moved), null, null)->parentId, 'the walk stops at the corrupt cycle');
    }

    public function testRefusesAStaleVersion(): void
    {
        $a = $this->service->create('A', null, null, null);

        self::assertValidation(['folder' => 'validation.folder.stale'], fn() => $this->service->update($a, 'B', null, '2026-01-01 00:00:00', null, null));
    }

    public function testRefusesDeletingAFolderWithFilesOrSubfolders(): void
    {
        $a = $this->service->create('A', null, null, null);
        $b = $this->service->create('B', $a->id, null, null);
        $this->insertMedia(['folder_id' => $b->id]);
        $this->insertMedia(['folder_id' => $b->id]);

        self::assertValidation(['folder' => 'validation.folder.not_empty'], fn() => $this->service->delete($a, null, null), ['folder' => ['folders' => 1, 'files' => 0]]);
        self::assertValidation(['folder' => 'validation.folder.not_empty'], fn() => $this->service->delete($b, null, null), ['folder' => ['folders' => 0, 'files' => 2]]);
        self::assertCount(2, $this->folders->all());
    }

    public function testDeletesAnEmptyFolder(): void
    {
        $a = $this->service->create('A', null, null, null);

        $this->service->delete($a, null, null);

        self::assertSame([], $this->folders->all());
        self::assertSame(['media_folder.created', 'media_folder.deleted'], $this->auditActions());
        self::assertSame(['name' => 'A'], json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'media_folder.deleted'")), true));
        $this->expectException(\AtarCms\Http\Exception\NotFound::class);
        $this->service->delete(new MediaFolder($a->id, null, 'A', $a->createdAt, $a->updatedAt), null, null);
    }
}
