<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\EntryInput;
use AtarCms\Content\Term;
use AtarCms\Content\TermInput;
use AtarCms\Tests\Support\Cast;

/**
 * "stories" are routed "/{sections}/{slug}": every story has exactly one section, and a section that gives stories
 * their address cannot be deleted.
 */
final class SectionAssignmentTest extends ContentTestCase
{
    public function testAStoryNeedsExactlyOneSection(): void
    {
        $stories = $this->collections->get('stories');
        $guides = $this->section('Guides');
        $notes = $this->section('Notes');

        self::assertValidation(
            ['terms.sections' => 'validation.field.required'],
            fn() => $this->service()->create($stories, 'en', new EntryInput('No section'), null, null),
        );
        self::assertValidation(
            ['terms.sections' => 'validation.field.required'],
            fn() => $this->service()->create($stories, 'en', new EntryInput('Empty', terms: ['sections' => []]), null, null),
        );
        self::assertValidation(
            ['terms.sections' => 'validation.terms.single'],
            fn() => $this->service()->create($stories, 'en', new EntryInput('Two', terms: ['sections' => [$guides->id, $notes->id]]), null, null),
        );

        $story = $this->service()->create($stories, 'en', new EntryInput('One', terms: ['sections' => [$guides->id]]), null, null);
        self::assertSame([$guides->id], $this->entryTerms()->ids($story->id));
        $kept = $this->service()->update($story, new EntryInput('One again'), null, null);
        self::assertSame([$guides->id], $this->entryTerms()->ids($kept->id), 'no terms in the input keep the section');
        self::assertValidation(
            ['terms.sections' => 'validation.field.required'],
            fn() => $this->service()->update($kept, new EntryInput('One', terms: ['sections' => []]), null, null),
        );
    }

    public function testASectionThatAddressesStoriesCannotBeDeleted(): void
    {
        $stories = $this->collections->get('stories');
        $guides = $this->section('Guides');
        $notes = $this->section('Notes');
        $story = $this->service()->create($stories, 'en', new EntryInput('One', terms: ['sections' => [$guides->id]]), null, null);

        self::assertValidation(['term' => 'validation.term.has_entries'], fn() => $this->termService()->delete($guides, null, null));

        $this->service()->update($story, new EntryInput('One', terms: ['sections' => [$notes->id]]), null, null);
        $this->termService()->delete($guides, null, null);
        self::assertNull($this->terms->find($guides->id));

        $php = $this->termService()->create($this->collections->taxonomies->get('topics'), 'en', new TermInput('PHP'), null, null);
        $this->service()->create($this->collections->get('articles'), 'en', new EntryInput('Article', terms: ['topics' => [$php->id]]), null, null);
        $this->termService()->delete($php, null, null);
        self::assertNull($this->terms->find($php->id), 'other taxonomies keep deleting terms in use');
    }

    /**
     * deleteBlockers() names the sections delete() would refuse, for the whole list in one query: a section with a
     * subsection, one that stories take their address from. Entries of a taxonomy no collection is routed by never
     * block a delete.
     */
    public function testDeleteBlockersNameTheSectionsDeleteRefusesInOneQuery(): void
    {
        $stories = $this->collections->get('stories');
        $guides = $this->section('Guides');
        $sub = $this->section('Sub', $guides);
        $notes = $this->section('Notes');
        $empty = $this->section('Empty');
        $this->service()->create($stories, 'en', new EntryInput('One', terms: ['sections' => [$notes->id]]), null, null);
        $this->service()->create($stories, 'en', new EntryInput('Two', terms: ['sections' => [$sub->id]]), null, null);
        $sections = $this->terms->forTaxonomy('sections', 'en');
        $selects = fn(): int => Cast::int($this->db->selectOne("SHOW SESSION STATUS LIKE 'Com_select'")['Value'] ?? 0);

        $before = $selects();
        $blockers = $this->termService()->deleteBlockers($sections);
        self::assertSame(1, $selects() - $before, 'one query for the whole list');

        self::assertCount(3, $blockers);
        self::assertSame('validation.term.has_children', $blockers[$guides->id] ?? null);
        self::assertSame('validation.term.has_entries', $blockers[$sub->id] ?? null);
        self::assertSame('validation.term.has_entries', $blockers[$notes->id] ?? null);
        self::assertArrayNotHasKey($empty->id, $blockers);
        foreach ($blockers as $id => $key) {
            $term = $this->terms->find($id) ?? self::fail('Missing term');
            self::assertValidation(['term' => $key], fn() => $this->termService()->delete($term, null, null));
        }

        $php = $this->termService()->create($this->collections->taxonomies->get('topics'), 'en', new TermInput('PHP'), null, null);
        $this->service()->create($this->collections->get('articles'), 'en', new EntryInput('Article', terms: ['topics' => [$php->id]]), null, null);
        self::assertSame([], $this->termService()->deleteBlockers([$php]));
        self::assertSame([], $this->termService()->deleteBlockers([]));
    }

    private function section(string $name, ?Term $parent = null): Term
    {
        return $this->termService()->create($this->collections->taxonomies->get('sections'), 'en', new TermInput($name, parentId: $parent?->id), null, null);
    }
}
