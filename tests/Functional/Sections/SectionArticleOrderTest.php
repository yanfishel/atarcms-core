<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Sections;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Term;
use AtarCms\Content\TermEntryOrder;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * A section page lists its articles in their manual order (TermEntryOrder), else newest first; the fixture lists two
 * per page.
 */
final class SectionArticleOrderTest extends ContentFunctionalTestCase
{
    public function testAnUnsortedSectionListsItsArticlesNewestFirst(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->story('Old', $guides, '-3 days');
        $this->story('Middle', $guides, '-2 days');
        $this->story('New', $guides, '-1 day');

        self::assertSame(['New', 'Middle'], $this->titles('/guides'));
        self::assertSame(['Old'], $this->titles('/guides?page=2'));
    }

    public function testASortedSectionFollowsItsOrderAcrossPagesWithNewAndMovedInArticlesFirst(): void
    {
        $guides = $this->term('sections', 'Guides');
        $notes = $this->term('sections', 'Notes');
        $old = $this->story('Old', $guides, '-3 days');
        $middle = $this->story('Middle', $guides, '-2 days');
        $new = $this->story('New', $guides, '-1 day');
        $draft = $this->story('Draft', $guides, null, EntryStatus::Draft);
        $order = $this->app->container()->service(TermEntryOrder::class);

        $order->reorder($guides, [$old->id, $draft->id, $new->id, $middle->id], TermEntryOrder::version($order->entries($guides)), null, null);

        self::assertSame(['Old', 'New'], $this->titles('/guides'), 'a draft keeps its place but is not shown');
        self::assertSame(['Middle'], $this->titles('/guides?page=2'));

        $service = $this->app->container()->service(EntryService::class);
        $service->update($middle, new EntryInput('Middle', status: EntryStatus::Published, publishedAt: $middle->publishedAt, terms: ['sections' => [$guides->id]]), null, null);
        self::assertSame(['Middle'], $this->titles('/guides?page=2'), 'saving an article keeps its place');

        $this->story('Fresh', $guides, '-1 hour');
        self::assertSame(['Fresh', 'Old'], $this->titles('/guides'), 'a new article comes first');

        $moved = $this->story('Moved', $notes, '-5 days');
        $service->update($moved, new EntryInput('Moved', status: EntryStatus::Published, publishedAt: $moved->publishedAt, terms: ['sections' => [$guides->id]]), null, null);
        self::assertSame(['Fresh', 'Moved'], $this->titles('/guides'), 'an article moved in comes first, among the new ones by date');
        self::assertSame(['Old', 'New'], $this->titles('/guides?page=2'));
    }

    private function story(string $title, Term $section, ?string $published, EntryStatus $status = EntryStatus::Published): Entry
    {
        return $this->entry('stories', $title, [
            'status' => $status,
            ...($published === null ? [] : ['publishedAt' => $this->clock->now()->modify($published)]),
            'terms' => ['sections' => [$section->id]],
        ]);
    }

    /**
     * @return list<string> the titles of the stories on a section page (the fixture theme's <h2>s)
     */
    private function titles(string $path): array
    {
        $response = $this->browser->get($path);
        self::assertSame(200, $response->getStatusCode(), $path);
        preg_match_all('~<h2>(?:<a [^>]*>)?([^<]*)~', (string) $response->getBody(), $matches);

        return $matches[1];
    }
}
