<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\TermInput;
use AtarCms\Tests\Support\QueryCounter;

/**
 * The fixture's "stories" are routed "/{sections}/{slug}": their URL starts with the slug of their section term.
 */
final class SectionedUrlsTest extends ContentTestCase
{
    public function testTheUrlStartsWithTheSectionSlug(): void
    {
        $guides = $this->termService()->create($this->collections->taxonomies->get('sections'), 'en', new TermInput('Guides'), null, null);
        $story = $this->story('Hello', [$guides->id]);
        $urls = $this->entryUrls();

        self::assertSame('/guides/hello', $urls->path($story));
        self::assertSame('https://example.com/guides/hello', $urls->absolute($story));
        self::assertSame($guides->id, $urls->routeTerm($story)?->id);
        self::assertSame(0, QueryCounter::measure($this->db, static fn(): ?string => $urls->path($story, [], $guides)), 'a known term costs no query');
    }

    public function testAStoryWithoutASectionHasNoUrl(): void
    {
        $story = $this->lost();

        self::assertNull($this->entryUrls()->path($story));
        self::assertNull($this->entryUrls()->routeTerm($story));
    }

    public function testPathsLoadEveryTermInOneQuery(): void
    {
        $sections = $this->collections->taxonomies->get('sections');
        $guides = $this->termService()->create($sections, 'en', new TermInput('Guides'), null, null);
        $news = $this->termService()->create($sections, 'en', new TermInput('Notes'), null, null);
        $stories = [$this->story('One', [$guides->id]), $this->story('Two', [$news->id]), $this->lost()];
        $page = $this->service()->create($this->collections->get('info'), 'en', new EntryInput('About', status: EntryStatus::Published), null, null);
        $urls = $this->entryUrls();

        $paths = [];
        $queries = QueryCounter::measure($this->db, function () use ($urls, $stories, $page, &$paths): void {
            $paths = $urls->paths([...$stories, $page]);
        });

        self::assertSame(1, $queries);
        self::assertSame([$stories[0]->id => '/guides/one', $stories[1]->id => '/notes/two', $page->id => '/about'], $paths);
        self::assertSame(0, QueryCounter::measure($this->db, static fn(): array => $urls->paths([$page])), 'no sectioned entry, no query');
    }

    /**
     * @param list<int> $sections
     */
    private function story(string $title, array $sections): Entry
    {
        return $this->service()->create(
            $this->collections->get('stories'),
            'en',
            new EntryInput($title, status: EntryStatus::Published, terms: ['sections' => $sections]),
            null,
            null,
        );
    }

    /**
     * A published story whose section row is gone (written around EntryService, which requires one).
     */
    private function lost(): Entry
    {
        $section = $this->termService()->create($this->collections->taxonomies->get('sections'), 'en', new TermInput('Lost and found'), null, null);
        $story = $this->story('Lost', [$section->id]);
        $this->db->execute('DELETE FROM entry_terms WHERE entry_id = ?', [$story->id]);

        return $story;
    }
}
