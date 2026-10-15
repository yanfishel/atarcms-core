<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Auth\AuditLog;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\ReservedSlugs;
use AtarCms\Content\SlugHistoryRepository;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\Tests\Support\Cast;
use InvalidArgumentException;
use stdClass;

final class EntryReorderTest extends ContentTestCase
{
    public function testMovesPagesAndTouchesOnlyTheMovedOnes(): void
    {
        $service = $this->service();
        $pages = $this->collections->get('pages');
        $about = $service->create($pages, 'en', new EntryInput('About', position: 0), null, null);
        $team = $service->create($pages, 'en', new EntryInput('Team', parentId: $about->id), null, null);
        $contact = $service->create($pages, 'en', new EntryInput('Contact', position: 1), null, null);
        $service->create($pages, 'ru', new EntryInput('О нас'), null, null);
        $revisions = Cast::int($this->db->scalar('SELECT COUNT(*) FROM entry_revisions'));
        $tree = TreeOrder::parse(sprintf('[{"id":%d},{"id":%d,"children":[{"id":%d}]}]', $about->id, $contact->id, $team->id));

        $this->clock->advance('+1 minute');
        $moved = $service->reorder($pages, 'en', $tree, $this->version(), static fn(Entry $entry): bool => true, $this->user(), '10.0.0.1');

        self::assertSame(1, $moved);
        $stored = $this->entries->find($team->id);
        self::assertSame([$contact->id, 0, '12:01'], [$stored?->parentId, $stored?->position, $stored?->updatedAt->format('H:i')]);
        self::assertSame('12:00', $this->entries->find($about->id)?->updatedAt->format('H:i'));
        self::assertSame($revisions, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entry_revisions')), 'moving writes no revisions');
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'entry.reordered'")), true);
        self::assertSame(['collection' => 'pages', 'locale' => 'en', 'moved' => 1], $meta);

        self::assertSame(0, $service->reorder($pages, 'en', $tree, $this->version(), static fn(Entry $entry): bool => true, null, null), 'the same order again');
        self::assertSame(1, Cast::int($this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'entry.reordered'")));
    }

    public function testRejectsTamperedAndStaleTreesWithoutChangingAnything(): void
    {
        $service = $this->service();
        $pages = $this->collections->get('pages');
        $about = $service->create($pages, 'en', new EntryInput('About'), null, null);
        $team = $service->create($pages, 'en', new EntryInput('Team', parentId: $about->id), null, null);
        $article = $this->insertEntry();
        $russian = $service->create($pages, 'ru', new EntryInput('О нас'), null, null);
        $version = $this->version();
        $before = $this->db->select('SELECT id, parent_id, position, updated_at FROM entries ORDER BY id');
        $any = static fn(Entry $entry): bool => true;

        foreach ([
            sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $about->id, $team->id, $article),
            sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $about->id, $team->id, $russian->id),
            sprintf('[{"id":%d}]', $about->id),
            sprintf('[{"id":%d,"children":[{"id":%d,"children":[{"id":%d}]}]}]', $about->id, $team->id, $about->id),
        ] as $json) {
            self::assertValidation(['tree' => 'validation.tree.invalid'], fn() => $service->reorder($pages, 'en', TreeOrder::parse($json), $version, $any, null, null));
        }
        $swapped = TreeOrder::parse(sprintf('[{"id":%d},{"id":%d}]', $team->id, $about->id));
        self::assertValidation(['tree' => 'validation.tree.stale'], fn() => $service->reorder($pages, 'en', $swapped, sha1('old'), $any, null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->reorder($pages, 'de', [], $version, $any, null, null));

        self::assertSame($before, $this->db->select('SELECT id, parent_id, position, updated_at FROM entries ORDER BY id'));
        self::assertNotContains('entry.reordered', $this->auditActions());
    }

    public function testEveryMovedEntryMustBeMovable(): void
    {
        $service = $this->service();
        $pages = $this->collections->get('pages');
        $about = $service->create($pages, 'en', new EntryInput('About', position: 0), null, null);
        $team = $service->create($pages, 'en', new EntryInput('Team', parentId: $about->id), null, null);
        $contact = $service->create($pages, 'en', new EntryInput('Contact', position: 1), null, null);
        $onlyTeam = static fn(Entry $entry): bool => $entry->slug === 'team';

        self::assertValidation(
            ['tree' => 'validation.tree.not_allowed'],
            fn() => $service->reorder($pages, 'en', TreeOrder::parse(sprintf('[{"id":%d},{"id":%d,"children":[{"id":%d}]}]', $contact->id, $about->id, $team->id)), $this->version(), $onlyTeam, null, null),
            ['tree' => ['title' => 'About']],
        );
        self::assertSame(0, $this->entries->find($about->id)?->position);

        self::assertSame(1, $service->reorder($pages, 'en', TreeOrder::parse(sprintf('[{"id":%d},{"id":%d,"children":[{"id":%d}]}]', $about->id, $contact->id, $team->id)), $this->version(), $onlyTeam, null, null));
        self::assertSame($contact->id, $this->entries->find($team->id)?->parentId);
    }

    public function testPagesMovingToTheTopLevelOfARootRouteAvoidReservedSlugs(): void
    {
        $collections = new Collections([Collection::make('pages')->route('/{slug}')->hierarchical()->translatable()]);
        $router = new Router();
        $router->get('/admin', [stdClass::class, 'x'], 'admin.dashboard');
        $locales = new Locales('en', ['en', 'ru']);
        $entries = new EntryRepository($this->db, $collections);
        $service = new EntryService(
            $this->db,
            $entries,
            $this->revisions(),
            $locales,
            new AuditLog($this->db, $this->clock),
            $this->clock,
            new ReservedSlugs($router, $locales),
            $this->entryTerms(),
            $this->entryMedia(),
            $this->entryAccess(),
            $collections,
            $this->searchIndexer($collections),
            $this->richTextTheme(),
            $this->translator(),
            $this->shareImageService(),
            new SlugHistoryRepository($this->db),
        );
        $pages = $collections->get('pages');
        $docs = $service->create($pages, 'en', new EntryInput('Docs'), null, null);
        $guide = $service->create($pages, 'en', new EntryInput('Admin guide', slug: 'admin', parentId: $docs->id), null, null);
        $version = TreeOrder::version($entries->forTree('pages', 'en'));

        self::assertValidation(
            ['tree' => 'validation.tree.reserved'],
            fn() => $service->reorder($pages, 'en', TreeOrder::parse(sprintf('[{"id":%d},{"id":%d}]', $docs->id, $guide->id)), $version, static fn(Entry $entry): bool => true, null, null),
            ['tree' => ['title' => 'Admin guide']],
        );
        self::assertSame($docs->id, $entries->find($guide->id)?->parentId);
    }

    public function testOnlyHierarchicalCollectionsHaveATree(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->reorder($this->collections->get('articles'), 'en', [], '', static fn(Entry $entry): bool => true, null, null);
    }

    private function version(): string
    {
        return TreeOrder::version($this->entries->forTree('pages', 'en'));
    }
}
