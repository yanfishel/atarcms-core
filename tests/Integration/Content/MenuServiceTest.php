<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Menu\MenuItemInput;
use AtarCms\Content\Menu\MenuTarget;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Tests\Support\Cast;
use InvalidArgumentException;

final class MenuServiceTest extends ContentTestCase
{
    public function testCreatesItemsAtTheEndOfTheTopLevel(): void
    {
        $service = $this->menuService();
        $ann = $this->user();
        $hello = $this->insertEntry();

        $home = $service->create('main', 'en', new MenuItemInput("  Home \t page ", MenuTarget::Url, url: ' / '), $ann, '10.0.0.1');
        $article = $service->create('main', 'en', new MenuItemInput('', MenuTarget::Entry, entryId: $hello, url: 'ignored'), $ann, null);
        $contact = $service->create('footer', 'en', new MenuItemInput('Contact', MenuTarget::Url, url: 'mailto:team@example.com', newTab: true), null, null);

        self::assertSame(['Home page', MenuTarget::Url, '/', null, 0], [$home->label, $home->target, $home->url, $home->parentId, $home->position]);
        self::assertSame(['', MenuTarget::Entry, $hello, null, 1], [$article->label, $article->target, $article->entryId, $article->url, $article->position]);
        self::assertSame([0, true], [$contact->position, $contact->newTab]);
        self::assertSame(['menu_item.created', 'menu_item.created', 'menu_item.created'], $this->auditActions());
    }

    public function testCreatesItemsInsideAParentAfterItsLastChild(): void
    {
        $service = $this->menuService();
        $url = static fn(string $label, ?int $parent = null): MenuItemInput => new MenuItemInput($label, MenuTarget::Url, url: '/' . strtolower($label), parentId: $parent);
        $top = $service->create('main', 'en', $url('Top'), null, null);
        $later = $service->create('main', 'en', $url('Later'), null, null);
        $first = $service->create('main', 'en', $url('First', $top->id), null, null);
        $second = $service->create('main', 'en', $url('Second', $top->id), null, null);
        $deep = $service->create('main', 'en', $url('Deep', $second->id), null, null);
        $next = $service->create('main', 'en', $url('Next'), null, null);

        self::assertSame([$top->id, 0], [$first->parentId, $first->position]);
        self::assertSame([$top->id, 1], [$second->parentId, $second->position]);
        self::assertSame([$second->id, 0], [$deep->parentId, $deep->position]);
        self::assertSame([null, 2], [$next->parentId, $next->position], 'children do not move the top level on');
        self::assertSame([null, 1], [$later->parentId, $later->position]);
    }

    public function testRefusesAParentOfAnotherMenuOrLanguageAndOneTooDeep(): void
    {
        $service = $this->menuService();
        $url = static fn(string $label, ?int $parent = null): MenuItemInput => new MenuItemInput($label, MenuTarget::Url, url: '/x', parentId: $parent);
        $top = $service->create('main', 'en', $url('Top'), null, null);
        $child = $service->create('main', 'en', $url('Child', $top->id), null, null);
        $grandchild = $service->create('main', 'en', $url('Grandchild', $child->id), null, null);
        $footer = $service->create('footer', 'en', $url('Footer'), null, null);
        $russian = $service->create('main', 'ru', $url('Верх'), null, null);

        self::assertValidation(['parent_id' => 'validation.menu.parent'], fn() => $service->create('main', 'en', $url('X', $footer->id), null, null));
        self::assertValidation(['parent_id' => 'validation.menu.parent'], fn() => $service->create('main', 'en', $url('X', $russian->id), null, null));
        self::assertValidation(['parent_id' => 'validation.menu.parent'], fn() => $service->create('main', 'en', $url('X', 999999), null, null));
        self::assertValidation(
            ['parent_id' => 'validation.tree.depth'],
            fn() => $service->create('main', 'en', $url('X', $grandchild->id), null, null),
            ['parent_id' => ['max' => 3]],
        );
        self::assertSame(5, Cast::int($this->db->scalar('SELECT COUNT(*) FROM menu_items')));
    }

    public function testUpdateMovesAnItemToTheEndOfAnotherParentOrOfTheTopLevel(): void
    {
        $service = $this->menuService();
        $url = static fn(string $label, ?int $parent = null): MenuItemInput => new MenuItemInput($label, MenuTarget::Url, url: '/x', parentId: $parent);
        $news = $service->create('main', 'en', $url('News'), null, null);
        $about = $service->create('main', 'en', $url('About'), null, null);
        $team = $service->create('main', 'en', $url('Team', $about->id), null, null);
        $jobs = $service->create('main', 'en', $url('Jobs', $news->id), null, null);
        $people = $service->create('main', 'en', $url('People', $jobs->id), null, null);

        $moved = $service->update($jobs, $url('Jobs', $about->id), null, null);
        self::assertSame([$about->id, 1], [$moved->parentId, $moved->position], 'last among the new parent\'s children');
        self::assertSame($jobs->id, $this->menuRepository()->find($people->id)?->parentId, 'its subitems move with it');
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'menu_item.updated' ORDER BY id DESC LIMIT 1")), true);
        self::assertIsArray($meta);
        self::assertSame($about->id, $meta['parent_id'] ?? null, 'a move names the new parent');

        $kept = $service->update($moved, $url('Careers', $about->id), null, null);
        self::assertSame([$about->id, 1, 'Careers'], [$kept->parentId, $kept->position, $kept->label], 'an unchanged parent keeps the place');
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'menu_item.updated' ORDER BY id DESC LIMIT 1")), true);
        self::assertIsArray($meta);
        self::assertArrayNotHasKey('parent_id', $meta);

        $top = $service->update($team, $url('Team'), null, null);
        self::assertSame([null, 2], [$top->parentId, $top->position], 'last at the top level');
    }

    public function testUpdateRefusesItselfItsSubitemsAnotherMenuAndATooDeepPlace(): void
    {
        $service = $this->menuService();
        $url = static fn(string $label, ?int $parent = null): MenuItemInput => new MenuItemInput($label, MenuTarget::Url, url: '/x', parentId: $parent);
        $top = $service->create('main', 'en', $url('Top'), null, null);
        $child = $service->create('main', 'en', $url('Child', $top->id), null, null);
        $grandchild = $service->create('main', 'en', $url('Grandchild', $child->id), null, null);
        $other = $service->create('main', 'en', $url('Other'), null, null);
        $otherChild = $service->create('main', 'en', $url('Other child', $other->id), null, null);
        $footer = $service->create('footer', 'en', $url('Footer'), null, null);
        $russian = $service->create('main', 'ru', $url('Верх'), null, null);

        foreach ([$top->id, $child->id, $grandchild->id, $footer->id, $russian->id, 999999] as $parent) {
            self::assertValidation(['parent_id' => 'validation.menu.parent'], fn() => $service->update($top, $url('Top', $parent), null, null));
        }
        // Child holds a level below it: under Other's child it would reach a fourth level.
        self::assertValidation(
            ['parent_id' => 'validation.tree.depth'],
            fn() => $service->update($child, $url('Child', $otherChild->id), null, null),
            ['parent_id' => ['max' => 3]],
        );
        self::assertSame($top->id, $this->menuRepository()->find($child->id)?->parentId);
        self::assertNull($this->menuRepository()->find($top->id)?->parentId);
    }

    public function testValidatesLabelsAndTargets(): void
    {
        $service = $this->menuService();
        $russianArticle = $this->insertEntry(['locale' => 'ru', 'slug' => 'privet']);
        $note = $this->insertEntry(['collection' => 'notes', 'slug' => 'memo']);
        $event = $this->insertEntry(['collection' => 'events', 'slug' => 'meetup']);
        $russianTopic = $this->insertTerm(['locale' => 'ru', 'slug' => 'kod', 'name' => 'Код']);
        $region = $this->insertTerm(['taxonomy' => 'regions', 'slug' => 'north', 'name' => 'North']);
        $url = static fn(string $label, string $url): MenuItemInput => new MenuItemInput($label, MenuTarget::Url, url: $url);
        $entry = static fn(?int $id): MenuItemInput => new MenuItemInput('X', MenuTarget::Entry, entryId: $id);
        $term = static fn(?int $id): MenuItemInput => new MenuItemInput('X', MenuTarget::Term, termId: $id);

        self::assertValidation(['label' => 'validation.field.required'], fn() => $service->create('main', 'en', $url(" \n ", '/'), null, null));
        self::assertValidation(
            ['label' => 'validation.field.too_long'],
            fn() => $service->create('main', 'en', $url(str_repeat('x', 201), '/'), null, null),
            ['label' => ['max' => 200]],
        );
        self::assertValidation(['url' => 'validation.menu.url'], fn() => $service->create('main', 'en', $url('Evil', 'javascript:alert(1)'), null, null));
        self::assertValidation(['entry_id' => 'validation.menu.entry'], fn() => $service->create('main', 'en', $entry(null), null, null));
        self::assertValidation(['entry_id' => 'validation.menu.entry'], fn() => $service->create('main', 'en', $entry(999999), null, null));
        self::assertValidation(['entry_id' => 'validation.menu.entry'], fn() => $service->create('main', 'en', $entry($russianArticle), null, null));
        self::assertValidation(['entry_id' => 'validation.menu.entry'], fn() => $service->create('main', 'en', $entry($note), null, null));
        self::assertValidation(['term_id' => 'validation.menu.term'], fn() => $service->create('main', 'en', $term(null), null, null));
        self::assertValidation(['term_id' => 'validation.menu.term'], fn() => $service->create('main', 'en', $term($russianTopic), null, null));
        self::assertValidation(['term_id' => 'validation.menu.term'], fn() => $service->create('main', 'en', $term($region), null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->create('main', 'de', $url('Home', '/'), null, null));
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM menu_items')));

        // A non-translatable collection keeps its entries in the default locale; every locale's menu may link them.
        self::assertSame($event, $service->create('main', 'ru', $entry($event), null, null)->entryId);
        self::assertSame($russianTopic, $service->create('main', 'ru', $term($russianTopic), null, null)->termId);
    }

    public function testUpdatesAnItemAndKeepsOnlyTheChosenTarget(): void
    {
        $service = $this->menuService();
        $topic = $this->insertTerm();
        $item = $service->create('main', 'en', new MenuItemInput('Docs', MenuTarget::Url, url: 'https://example.com/docs', newTab: true), null, null);

        $this->clock->advance('+1 minute');
        $updated = $service->update($item, new MenuItemInput('PHP', MenuTarget::Term, termId: $topic, url: 'https://example.com/docs'), null, null);

        self::assertSame(
            ['PHP', MenuTarget::Term, $topic, null, null, false],
            [$updated->label, $updated->target, $updated->termId, $updated->entryId, $updated->url, $updated->newTab],
        );
        self::assertSame('2026-09-28 12:01:00', $updated->updatedAt->format('Y-m-d H:i:s'));
        self::assertValidation(['url' => 'validation.menu.url'], fn() => $service->update($updated, new MenuItemInput('X', MenuTarget::Url, url: '//evil.example'), null, null));
        self::assertSame(MenuTarget::Term, $this->menuRepository()->find($item->id)?->target);
        self::assertSame(['menu_item.created', 'menu_item.updated'], $this->auditActions());
    }

    public function testDeletesAnItemTogetherWithItsSubitems(): void
    {
        $service = $this->menuService();
        $parent = $this->insertMenuItem(['label' => 'Parent']);
        $child = $this->insertMenuItem(['label' => 'Child', 'parent_id' => $parent]);
        $this->insertMenuItem(['label' => 'Grandchild', 'parent_id' => $child]);
        $this->insertMenuItem(['label' => 'Other', 'position' => 1]);

        self::assertSame(2, $service->delete($this->menuRepository()->find($parent) ?? self::fail('No parent'), $this->user(), null));

        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM menu_items')));
        self::assertSame(['menu_item.deleted'], $this->auditActions());
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'menu_item.deleted'")), true);
        self::assertSame(['location' => 'main', 'locale' => 'en', 'label' => 'Parent', 'target' => 'url', 'subitems' => 2], $meta);
    }

    public function testReordersTheWholeTreeAndTouchesOnlyTheMovedItems(): void
    {
        $service = $this->menuService();
        $a = $this->insertMenuItem(['label' => 'A', 'position' => 0]);
        $b = $this->insertMenuItem(['label' => 'B', 'position' => 1]);
        $c = $this->insertMenuItem(['label' => 'C', 'position' => 2]);
        $tree = sprintf('[{"id":%d,"children":[{"id":%d}]},{"id":%d}]', $c, $a, $b);

        $this->clock->advance('+1 minute');
        $moved = $service->reorder('main', 'en', TreeOrder::parse($tree), $this->version(), $this->user(), '10.0.0.1');

        self::assertSame(2, $moved);
        $placed = [];
        foreach ($this->menuRepository()->forLocation('main', 'en') as $item) {
            $placed[$item->label] = [$item->parentId, $item->position, $item->updatedAt->format('H:i')];
        }
        self::assertSame(['A' => [$c, 0, '12:01'], 'C' => [null, 0, '12:01'], 'B' => [null, 1, '12:00']], $placed);
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'menu.reordered'")), true);
        self::assertSame(['location' => 'main', 'locale' => 'en', 'moved' => 2], $meta);

        self::assertSame(0, $service->reorder('main', 'en', TreeOrder::parse($tree), $this->version(), null, null), 'the same order again');
        self::assertSame(['menu.reordered'], $this->auditActions());
    }

    public function testRejectsTamperedAndStaleTreesWithoutChangingAnything(): void
    {
        $service = $this->menuService();
        $a = $this->insertMenuItem(['label' => 'A', 'position' => 0]);
        $b = $this->insertMenuItem(['label' => 'B', 'position' => 1]);
        $footer = $this->insertMenuItem(['label' => 'F', 'location' => 'footer']);
        $russian = $this->insertMenuItem(['label' => 'R', 'locale' => 'ru']);
        $c = $this->insertMenuItem(['label' => 'C', 'position' => 2]);
        $d = $this->insertMenuItem(['label' => 'D', 'position' => 3]);
        $version = $this->version();
        $before = $this->db->select('SELECT id, parent_id, position, updated_at FROM menu_items ORDER BY id');

        $cases = [
            'an item of another location' => [sprintf('[{"id":%d},{"id":%d},{"id":%d},{"id":%d},{"id":%d}]', $a, $b, $c, $d, $footer), 'validation.tree.invalid'],
            'an item of another locale' => [sprintf('[{"id":%d},{"id":%d},{"id":%d},{"id":%d},{"id":%d}]', $a, $b, $c, $d, $russian), 'validation.tree.invalid'],
            'a missing item' => [sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $a, $b, $c), 'validation.tree.invalid'],
            'a duplicate' => [sprintf('[{"id":%d,"children":[{"id":%d}]},{"id":%d},{"id":%d},{"id":%d}]', $a, $a, $b, $c, $d), 'validation.tree.invalid'],
            'four levels' => [sprintf('[{"id":%d,"children":[{"id":%d,"children":[{"id":%d,"children":[{"id":%d}]}]}]}]', $a, $b, $c, $d), 'validation.tree.depth'],
        ];
        foreach ($cases as $name => [$json, $key]) {
            try {
                $service->reorder('main', 'en', TreeOrder::parse($json), $version, null, null);
                self::fail('Accepted ' . $name);
            } catch (ValidationFailed $e) {
                self::assertSame(['tree' => $key], $e->errors, $name);
            }
        }
        $swapped = TreeOrder::parse(sprintf('[{"id":%d},{"id":%d},{"id":%d},{"id":%d}]', $b, $a, $c, $d));
        self::assertValidation(['tree' => 'validation.tree.stale'], fn() => $service->reorder('main', 'en', $swapped, sha1('old'), null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->reorder('main', 'de', [], $version, null, null));

        self::assertSame($before, $this->db->select('SELECT id, parent_id, position, updated_at FROM menu_items ORDER BY id'));
        self::assertSame([], $this->auditActions());
    }

    public function testUnknownLocationsAreProgrammingErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown menu location "sidebar"');

        $this->menuService()->create('sidebar', 'en', new MenuItemInput('X', MenuTarget::Url, url: '/'), null, null);
    }

    private function version(): string
    {
        return TreeOrder::version($this->menuRepository()->forLocation('main', 'en'));
    }
}
