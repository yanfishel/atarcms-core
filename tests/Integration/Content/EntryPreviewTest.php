<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\RichText;
use AtarCms\Content\Term;
use AtarCms\Content\TermInput;
use AtarCms\Tests\Support\Cast;

/**
 * EntryService::preview() builds the entry a form would save, in memory: normalised like a save, best effort on
 * invalid values, and nothing is written.
 */
final class EntryPreviewTest extends ContentTestCase
{
    private const array TABLES = ['entries', 'entry_revisions', 'entry_terms', 'terms', 'search_index', 'audit_log', 'entry_media'];

    public function testANewEntryIsBuiltInMemoryAndNothingIsWritten(): void
    {
        $articles = $this->collections->get('articles');
        $php = $this->termService()->create($this->collections->taxonomies->get('topics'), 'en', new TermInput('PHP'), null, null);
        $existingTag = $this->termService()->create($this->collections->taxonomies->get('tags'), 'en', new TermInput('Known'), null, null);
        $cover = $this->insertMedia();
        $before = $this->counts();

        $preview = $this->service()->preview($articles, 'en', new EntryInput(
            'Draft title',
            status: EntryStatus::Draft,
            fields: ['summary' => 'Short', 'body' => '<p>Hi<script>alert(1)</script></p>', 'cover' => (string) $cover, 'tags' => 'known, Brand new'],
            terms: ['topics' => [$php->id], 'tags' => ['known', 'Brand new']],
        ), null, $this->user());

        self::assertSame($before, $this->counts(), 'a preview writes nothing');
        self::assertTrue($preview->valid);
        $entry = $preview->entry;
        self::assertSame(0, $entry->id);
        self::assertSame('Draft title', $entry->title);
        self::assertSame('draft-title', $entry->slug);
        self::assertSame(EntryStatus::Draft, $entry->status);
        self::assertSame('en', $entry->locale);
        $body = $entry->field('body');
        self::assertInstanceOf(RichText::class, $body);
        self::assertSame('<p>Hi</p>', $body->html, 'rich text is sanitised like a save');
        self::assertSame($cover, $entry->field('cover'));
        self::assertSame([$php->id], array_map(static fn(Term $t): int => $t->id, $preview->terms('topics')));
        $tags = $preview->terms('tags');
        self::assertSame(['Known', 'Brand new'], array_map(static fn(Term $t): string => $t->name, $tags));
        self::assertSame([$existingTag->id, 0], array_map(static fn(Term $t): int => $t->id, $tags), 'a new tag name is an unsaved term');
        self::assertSame('brand-new', $tags[1]->slug);
    }

    public function testAnExistingEntryKeepsItsIdAndStoredState(): void
    {
        $articles = $this->collections->get('articles');
        $php = $this->termService()->create($this->collections->taxonomies->get('topics'), 'en', new TermInput('PHP'), null, null);
        $stored = $this->service()->create($articles, 'en', new EntryInput('Stored', status: EntryStatus::Published, terms: ['topics' => [$php->id]]), null, null);
        $before = $this->counts();

        $preview = $this->service()->preview($articles, 'en', new EntryInput('Changed', fields: ['summary' => 'New']), $stored, null);

        self::assertSame($before, $this->counts());
        self::assertSame($stored->id, $preview->entry->id);
        self::assertSame('Changed', $preview->entry->title);
        self::assertSame('stored', $preview->entry->slug, 'an empty slug keeps the slug of a published entry');
        self::assertSame($stored->translationGroup, $preview->entry->translationGroup);
        self::assertSame([$php->id], array_map(static fn(Term $t): int => $t->id, $preview->terms('topics')), 'no terms in the input keep the stored ones');
        self::assertSame('Stored', $this->entries->find($stored->id)?->title);
    }

    public function testInvalidValuesAreLeftOutInsteadOfFailing(): void
    {
        $articles = $this->collections->get('articles');
        $stored = $this->service()->create($articles, 'en', new EntryInput('Stored', fields: ['rating' => '3']), null, null);

        $preview = $this->service()->preview($articles, 'en', new EntryInput('', fields: ['rating' => '99', 'summary' => 'Fine']), $stored, null);

        self::assertFalse($preview->valid);
        self::assertSame('Stored', $preview->entry->title, 'an empty title previews the stored one');
        self::assertNull($preview->entry->field('rating'), 'an invalid value is left out');
        self::assertSame('Fine', $preview->entry->field('summary'));

        $new = $this->service()->preview($articles, 'en', new EntryInput('   '), null, null);
        self::assertSame('Articles', $new->entry->title, 'a new entry without a title previews the collection label');
    }

    public function testAStoryUsesItsChosenSectionAndAPageItsChosenParentChain(): void
    {
        $sections = $this->collections->taxonomies->get('sections');
        $guides = $this->termService()->create($sections, 'en', new TermInput('Guides'), null, null);
        $sub = $this->termService()->create($sections, 'en', new TermInput('Sub', parentId: $guides->id), null, null);
        $story = $this->service()->preview($this->collections->get('stories'), 'en', new EntryInput('Story', terms: ['sections' => [$sub->id]]), null, null);
        self::assertSame($sub->id, $story->routeTerm()?->id);
        self::assertSame('/guides/sub/story', $this->entryUrls()->path($story->entry, $story->parents, $story->routeTerm()));

        $pages = $this->collections->get('pages');
        $top = $this->service()->create($pages, 'en', new EntryInput('Top'), null, null);
        $middle = $this->service()->create($pages, 'en', new EntryInput('Middle', parentId: $top->id), null, null);
        $page = $this->service()->preview($pages, 'en', new EntryInput('Leaf', parentId: $middle->id), null, null);
        self::assertEqualsCanonicalizing([$top->id, $middle->id], array_keys($page->parents));
        self::assertSame('/pages/top/middle/leaf', $this->entryUrls()->path($page->entry, $page->parents));
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = Cast::int($this->db->selectOne('SELECT COUNT(*) AS n FROM ' . $table)['n'] ?? 0);
        }

        return $counts;
    }
}
