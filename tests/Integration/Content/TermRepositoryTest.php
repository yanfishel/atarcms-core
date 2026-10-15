<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Term;
use AtarCms\Tests\Support\Cast;
use PDOException;

final class TermRepositoryTest extends ContentTestCase
{
    public function testFindsTermsBySlugNameAndIds(): void
    {
        $php = $this->insertTerm();
        $ru = $this->insertTerm(['locale' => 'ru']);
        $this->insertTerm(['taxonomy' => 'tags']);

        self::assertSame($php, $this->terms->findBySlug('topics', 'en', 'php')?->id);
        self::assertSame($ru, $this->terms->findBySlug('topics', 'ru', 'php')?->id);
        self::assertSame($php, $this->terms->findByName('topics', 'en', 'php')?->id);
        self::assertNull($this->terms->findByName('topics', 'en', 'Go'));
        self::assertSame([$php, $ru], array_keys($this->terms->byIds([$ru, $php, 99999])));
        self::assertSame([], $this->terms->byIds([]));
        self::assertTrue($this->terms->slugExists('topics', 'en', 'php'));
        self::assertFalse($this->terms->slugExists('topics', 'en', 'php', $php));
        self::assertFalse($this->terms->slugExists('topics', 'de', 'php'));

        $term = $this->terms->find($php);
        self::assertInstanceOf(Term::class, $term);
        self::assertSame('PHP', (string) $term);
        self::assertSame(['topics', 'en', 'php', null, 0], [$term->taxonomy, $term->locale, $term->slug, $term->parentId, $term->position]);
        self::assertSame('2026-09-28 12:00:00', $term->updatedAt->format('Y-m-d H:i:s'));
    }

    public function testListsATaxonomyInOrderAndItsTranslations(): void
    {
        $group = str_repeat('a', 32);
        $b = $this->insertTerm(['slug' => 'b', 'name' => 'B', 'position' => 1, 'translation_group' => $group]);
        $a = $this->insertTerm(['slug' => 'a', 'name' => 'A', 'position' => 1]);
        $z = $this->insertTerm(['slug' => 'z', 'name' => 'Z', 'position' => 0]);
        $bRu = $this->insertTerm(['slug' => 'b', 'name' => 'Б', 'locale' => 'ru', 'translation_group' => $group]);

        self::assertSame([$z, $a, $b], array_map(static fn(Term $t): int => $t->id, $this->terms->forTaxonomy('topics', 'en')));
        self::assertSame(['en', 'ru'], array_map(static fn(Term $t): string => $t->locale, $this->terms->translations($group)));
        self::assertSame([$group => ['en', 'ru']], $this->terms->localesByGroup([$group]));
        self::assertSame([], $this->terms->localesByGroup([]));
        self::assertSame([$b => $bRu], $this->terms->translationIds([$b, $a], 'ru'));
        self::assertSame([], $this->terms->translationIds([], 'ru'));
        self::assertCount(4, $this->terms->allOf('topics'));
        self::assertSame([], $this->terms->allOf('tags'));
    }

    public function testAssignsTermsToEntriesPerTaxonomy(): void
    {
        $entry = $this->insertEntry();
        $other = $this->insertEntry(['slug' => 'other']);
        $php = $this->insertTerm();
        $go = $this->insertTerm(['slug' => 'go', 'name' => 'Go']);
        $tag = $this->insertTerm(['taxonomy' => 'tags', 'slug' => 'news', 'name' => 'News']);

        $this->terms->assign($entry, 'topics', [$go, $php, $go]);
        $this->terms->assign($entry, 'tags', [$tag]);
        $this->terms->assign($other, 'topics', [$php]);

        self::assertSame([$tag, $go, $php], $this->terms->idsForEntry($entry));
        $byEntry = $this->terms->forEntries([$entry, $other]);
        self::assertSame(['News', 'Go', 'PHP'], array_map(static fn(Term $t): string => $t->name, $byEntry[$entry]));
        self::assertSame(['PHP'], array_map(static fn(Term $t): string => $t->name, $byEntry[$other]));
        self::assertSame([$entry], array_keys($this->terms->forEntries([$entry, $other], 'tags')));
        self::assertSame([], $this->terms->forEntries([]));
        self::assertSame([$php => 2, $go => 1, $tag => 1], $this->terms->entryCounts([$php, $go, $tag]));
        self::assertSame([], $this->terms->entryCounts([]));

        $this->terms->assign($entry, 'topics', []);
        self::assertSame([$tag], $this->terms->idsForEntry($entry));
    }

    /**
     * entry_position (the entry's place among a section's articles) belongs to the pair: an entry that keeps a term
     * keeps it whatever assign() rewrites; a new pair starts at 0.
     */
    public function testAssignKeepsTheEntryPositionOfTheTermsAnEntryKeeps(): void
    {
        $entry = $this->insertEntry();
        $other = $this->insertEntry(['slug' => 'other']);
        $php = $this->insertTerm();
        $go = $this->insertTerm(['slug' => 'go', 'name' => 'Go']);
        $rust = $this->insertTerm(['slug' => 'rust', 'name' => 'Rust']);
        $this->terms->assign($entry, 'topics', [$php, $go]);
        $this->terms->assign($other, 'topics', [$go]);
        self::assertSame([$php => 0, $go => 0], $this->entryPositions($entry));

        $this->terms->setEntryPositions($go, [$other => 1, $entry => 2]);
        $this->terms->setEntryPositions($php, [$entry => 7]);
        $this->terms->assign($entry, 'topics', [$rust, $go]);

        self::assertSame([$rust, $go], $this->terms->idsForEntry($entry), 'the order of the terms is rewritten');
        self::assertSame([$rust => 0, $go => 2], $this->entryPositions($entry));
        self::assertSame([$go => 1], $this->entryPositions($other));

        $this->terms->assign($entry, 'topics', [$php]);
        self::assertSame([$php => 0], $this->entryPositions($entry), 'a term given up and taken again starts over');
    }

    public function testAssignmentsDisappearWithTheirEntryOrTerm(): void
    {
        $entry = $this->insertEntry();
        $php = $this->insertTerm();
        $go = $this->insertTerm(['slug' => 'go', 'name' => 'Go']);
        $this->terms->assign($entry, 'topics', [$php, $go]);

        $this->terms->delete($go);
        self::assertSame([$php], $this->terms->idsForEntry($entry));

        $this->entries->delete($entry);
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entry_terms')));
        self::assertNotNull($this->terms->find($php));
    }

    public function testParentsCannotBeDeletedBeforeTheirChildren(): void
    {
        $parent = $this->insertTerm();
        $child = $this->insertTerm(['slug' => 'child', 'name' => 'Child', 'parent_id' => $parent]);

        self::assertTrue($this->terms->hasChildren($parent));
        self::assertFalse($this->terms->hasChildren($child));

        $this->terms->update($child, ['name' => 'Renamed', 'position' => 3]);
        self::assertSame(['Renamed', 3], [$this->terms->find($child)?->name, $this->terms->find($child)?->position]);

        $this->expectException(PDOException::class);
        $this->terms->delete($parent);
    }

    /**
     * @return array<int, int> term id => the entry's entry_position for it, in the entry's term order
     */
    private function entryPositions(int $entry): array
    {
        $positions = [];
        foreach ($this->db->select('SELECT term_id, entry_position FROM entry_terms WHERE entry_id = ? ORDER BY position', [$entry]) as $row) {
            $positions[Cast::int($row['term_id'])] = Cast::int($row['entry_position']);
        }

        return $positions;
    }
}
