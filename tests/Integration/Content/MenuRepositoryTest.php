<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Menu\MenuItem;
use AtarCms\Content\Menu\MenuTarget;

final class MenuRepositoryTest extends ContentTestCase
{
    public function testReadsTheItemsOfOneLocationAndLocaleInSiblingOrder(): void
    {
        $menus = $this->menuRepository();
        $second = $this->insertMenuItem(['label' => 'Second', 'position' => 1]);
        $first = $this->insertMenuItem(['label' => 'First <b>', 'position' => 0, 'url' => 'https://example.com', 'new_tab' => true]);
        $this->insertMenuItem(['label' => 'Footer', 'location' => 'footer']);
        $this->insertMenuItem(['label' => 'Russian', 'locale' => 'ru']);
        $child = $this->insertMenuItem(['label' => 'Child', 'parent_id' => $first]);

        self::assertSame(['First <b>', 'Child', 'Second'], self::labels($menus->forLocation('main', 'en')));
        self::assertSame(['First <b>', 'Child', 'Second'], self::labels($menus->forLocation('main', 'en', true)));
        $item = $menus->find($first) ?? self::fail('No item');
        self::assertSame(
            ['main', 'en', null, 0, MenuTarget::Url, 'https://example.com', true, null, null],
            [$item->location, $item->locale, $item->parentId, $item->position, $item->target, $item->url, $item->newTab, $item->entryId, $item->termId],
        );
        self::assertSame($first, $menus->find($child)?->parentId);
        self::assertNull($menus->find(999999));
        self::assertSame(2, $menus->nextPosition('main', 'en'));
        self::assertSame(0, $menus->nextPosition('main', 'de'));

        $this->clock->advance('+1 hour');
        $menus->update($second, ['label' => 'Renamed', 'updated_at' => $this->clock->now()]);
        self::assertSame('Renamed', $menus->find($second)?->label);
        self::assertSame('2026-09-28 13:00:00', $menus->find($second)->updatedAt->format('Y-m-d H:i:s'));
    }

    public function testDeletingAnItemDeletesItsSubitems(): void
    {
        $menus = $this->menuRepository();
        $parent = $this->insertMenuItem(['label' => 'Parent']);
        $child = $this->insertMenuItem(['label' => 'Child', 'parent_id' => $parent]);
        $this->insertMenuItem(['label' => 'Grandchild', 'parent_id' => $child]);
        $other = $this->insertMenuItem(['label' => 'Other', 'position' => 1]);

        self::assertSame(2, $menus->descendantCount($menus->find($parent) ?? self::fail('No parent')));
        self::assertSame(0, $menus->descendantCount($menus->find($other) ?? self::fail('No other')));

        $menus->delete($parent);

        self::assertSame(['Other'], self::labels($menus->forLocation('main', 'en')));
    }

    public function testItemsOutliveTheEntriesAndTermsTheyLinkTo(): void
    {
        $menus = $this->menuRepository();
        $entryId = $this->insertEntry();
        $termId = $this->insertTerm();
        $toEntry = $this->insertMenuItem(['label' => '', 'target_type' => 'entry', 'entry_id' => $entryId, 'url' => null]);
        $toTerm = $this->insertMenuItem(['label' => '', 'target_type' => 'term', 'term_id' => $termId, 'url' => null]);

        $this->db->execute('DELETE FROM entries WHERE id = ?', [$entryId]);
        $this->db->execute('DELETE FROM terms WHERE id = ?', [$termId]);

        self::assertSame([MenuTarget::Entry, null], [$menus->find($toEntry)?->target, $menus->find($toEntry)?->entryId]);
        self::assertSame([MenuTarget::Term, null], [$menus->find($toTerm)?->target, $menus->find($toTerm)?->termId]);
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<string>
     */
    private static function labels(array $items): array
    {
        return array_map(static fn(MenuItem $item): string => $item->label, $items);
    }
}
