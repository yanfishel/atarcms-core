<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Sections;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Term;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * "Order articles" (/admin/sections/{id}/order): the articles of a section without subsections, in its language and
 * any status, in the tree editor as a flat list; saved like every tree (arrows without JavaScript, a drag in the
 * background). Needs "taxonomies.manage", like the Sections screen.
 */
final class SectionArticlesAdminTest extends ContentFunctionalTestCase
{
    public function testOnlyLeafSectionsAndTheirManagersGetTheScreen(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $topic = $this->term('topics', 'Topic');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        self::assertSame(403, $this->browser->get('/admin/sections/' . $php->id . '/order')->getStatusCode());
        self::assertSame(403, $this->save($php, '[]', str_repeat('0', 40))->getStatusCode());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame(200, $this->browser->get('/admin/sections/' . $php->id . '/order')->getStatusCode());
        foreach ([$guides->id => 'a section with subsections', $topic->id => 'a term of another taxonomy', 99999 => 'no such section'] as $id => $case) {
            self::assertSame(404, $this->browser->get('/admin/sections/' . $id . '/order')->getStatusCode(), $case);
        }
        self::assertSame(404, $this->save($guides, '[]', str_repeat('0', 40))->getStatusCode());
    }

    public function testTheSectionsScreenOffersTheOrderOnSectionsWithoutSubsections(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP <b>', ['parent' => $guides]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $page = (string) $this->browser->get('/admin/sections?locale=en')->getBody();

        self::assertMatchesRegularExpression('~<span class="admin-row-actions">\s*<a class="admin-row-action" href="/admin/sections/' . $php->id . '/order" title="Order the articles of “PHP &lt;b&gt;”" aria-label="Order the articles of “PHP &lt;b&gt;”"><svg[^>]*><use href="[^"]+#arrow-down-wide-narrow"></use></svg></a>\s*<a class="admin-row-action" href="/admin/sections/new~', $page, 'the first slot, a full page (no dialog)');
        $reason = 'Articles belong to the sections without subsections: order them there.';
        self::assertMatchesRegularExpression('~<span class="admin-row-actions">\s*<button type="button" class="admin-row-action admin-locked" aria-disabled="true" title="' . $reason . '" aria-label="Order the articles of “Guides”" aria-describedby="tree-' . $guides->id . '-order-reason"><svg[^>]*><use href="[^"]+#arrow-down-wide-narrow"></use></svg></button>~', $page);
        self::assertStringNotContainsString('/admin/sections/' . $guides->id . '/order', $page);
    }

    public function testShowsTheArticlesOfAnyStatusInTheirOrderAndSavesWithoutJavaScript(): void
    {
        $guides = $this->term('sections', 'Guides');
        $old = $this->story('Old <b>', $guides, '-2 days');
        $new = $this->story('New', $guides, '-1 day');
        $draft = $this->story('Draft', $guides, null, EntryStatus::Draft);
        $later = $this->story('Later', $guides, '+1 day', EntryStatus::Scheduled);
        $this->story('Elsewhere', $this->term('sections', 'Notes'), '-1 hour');
        $this->story('Русская', $this->term('sections', 'Гайды', ['locale' => 'ru']), '-1 hour', locale: 'ru');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/sections/' . $guides->id . '/order')->getBody();

        self::assertStringContainsString('<h1>Order of the articles in “Guides” <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>', $body);
        self::assertMatchesRegularExpression('~<li><a href="/admin/sections\?locale=en">Sections</a></li>\s*<li><span aria-current="page">Guides</span></li>~', $body);
        self::assertSame([$later->id, $new->id, $old->id, $draft->id], self::ids($body), 'newest first until sorted, drafts last');
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/stories/' . $old->id . '">Old &lt;b&gt;</a>', $body);
        self::assertStringContainsString('<span class="admin-chip admin-chip-draft">Draft</span>', self::row($body, $draft));
        self::assertStringContainsString('<span class="admin-chip admin-chip-scheduled">Scheduled</span>', self::row($body, $later));
        self::assertStringContainsString('<span class="admin-chip admin-chip-published">Published</span>', self::row($body, $old));
        self::assertStringNotContainsString('Elsewhere', $body);
        self::assertStringNotContainsString('Русская', $body);
        self::assertStringContainsString('data-max-depth="1"', $body);
        self::assertStringContainsString('name="move" value="' . $old->id . ':up"', $body);
        self::assertStringNotContainsString(':indent"', $body, 'a flat list: up and down only');
        self::assertStringNotContainsString(':outdent"', $body);
        self::assertStringNotContainsString('data-tree-toggle', $body);
        self::assertSame(1, substr_count($body, 'data-tree-list'), 'no nested lists to drop into');
        self::assertStringContainsString('New articles and articles moved into this section come first.', $body);

        $moved = $this->browser->post('/admin/sections/' . $guides->id . '/order', [
            '_csrf' => $this->csrfToken(),
            'version' => self::version($body),
            'tree' => sprintf('[{"id":%d},{"id":%d},{"id":%d},{"id":%d}]', $later->id, $new->id, $old->id, $draft->id),
            'move' => $old->id . ':up',
        ]);

        self::assertSame(303, $moved->getStatusCode());
        self::assertSame('/admin/sections/' . $guides->id . '/order', $moved->getHeaderLine('Location'));
        $body = (string) $this->browser->get('/admin/sections/' . $guides->id . '/order')->getBody();
        self::assertStringContainsString('Order saved.', $body);
        self::assertSame([$later->id, $old->id, $new->id, $draft->id], self::ids($body));
        self::assertContains('term.entries_reordered', $this->auditActions());
    }

    public function testABackgroundSaveAnswersWithTheNewVersionAndRefusalsAreToasts(): void
    {
        $guides = $this->term('sections', 'Guides');
        $one = $this->story('One', $guides, '-2 days');
        $two = $this->story('Two', $guides, '-1 day');
        $elsewhere = $this->story('Elsewhere', $this->term('sections', 'Notes'), '-1 day');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $loaded = self::version((string) $this->browser->get('/admin/sections/' . $guides->id . '/order')->getBody());
        $json = ['Accept' => 'application/json'];
        $tree = sprintf('[{"id":%d},{"id":%d}]', $one->id, $two->id);

        foreach ([
            sprintf('[{"id":%d}]', $one->id),
            sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $one->id, $two->id, $elsewhere->id),
            sprintf('[{"id":%d,"children":[{"id":%d}]}]', $one->id, $two->id),
            'not json',
        ] as $wrong) {
            $refused = $this->save($guides, $wrong, $loaded);
            self::assertSame(422, $refused->getStatusCode(), $wrong);
            self::assertStringContainsString('The submitted order does not match the current items.', (string) $refused->getBody(), $wrong);
            self::assertStringContainsString('admin-toast', (string) $refused->getBody());
        }

        $saved = $this->save($guides, $tree, $loaded, $json);
        self::assertSame(200, $saved->getStatusCode());
        $body = (string) $this->browser->get('/admin/sections/' . $guides->id . '/order')->getBody();
        self::assertSame(['version' => self::version($body)], json_decode((string) $saved->getBody(), true));
        self::assertSame([$one->id, $two->id], self::ids($body));
        self::assertSame(['One', 'Two'], $this->publicTitles('/guides'));

        $stale = $this->save($guides, sprintf('[{"id":%d},{"id":%d}]', $two->id, $one->id), $loaded, $json);
        self::assertSame(422, $stale->getStatusCode());
        self::assertSame(['error' => 'Someone else changed the order after you opened this page, so yours was not saved. Reload the page to see the current order.'], json_decode((string) $stale->getBody(), true));
        $stalePage = $this->save($guides, sprintf('[{"id":%d},{"id":%d}]', $two->id, $one->id), $loaded);
        self::assertSame(422, $stalePage->getStatusCode());
        self::assertStringContainsString('Someone else changed the order after you opened this page.', (string) $stalePage->getBody());
        self::assertSame(['One', 'Two'], $this->publicTitles('/guides'));
    }

    public function testAnEmptySectionShowsAnEmptyState(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/sections/' . $guides->id . '/order')->getBody();

        self::assertStringContainsString('<p class="admin-empty-title">No articles in this section yet.</p>', $body);
        self::assertStringNotContainsString('data-tree-form', $body);
    }

    public function testSwitchingTheContentLanguageReturnsToTheSections(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $switched = $this->browser->post('/admin/preferences/content-locale', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'return' => '/admin/sections/' . $guides->id . '/order']);

        self::assertSame('/admin/sections?locale=ru', $switched->getHeaderLine('Location'));
    }

    private function story(string $title, Term $section, ?string $published, EntryStatus $status = EntryStatus::Published, string $locale = 'en'): Entry
    {
        return $this->entry('stories', $title, [
            'locale' => $locale,
            'status' => $status,
            ...($published === null ? [] : ['publishedAt' => $this->clock->now()->modify($published)]),
            'terms' => ['sections' => [$section->id]],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function save(Term $section, string $tree, string $version, array $headers = []): ResponseInterface
    {
        return $this->browser->post('/admin/sections/' . $section->id . '/order', [
            '_csrf' => $this->csrfToken(),
            'version' => $version,
            'tree' => $tree,
        ], $headers);
    }

    /**
     * @return list<string>
     */
    private function publicTitles(string $path): array
    {
        preg_match_all('~<h2>(?:<a [^>]*>)?([^<]*)~', (string) $this->browser->get($path)->getBody(), $matches);

        return $matches[1];
    }

    /**
     * @return list<int> the ids of the rows, in order
     */
    private static function ids(string $body): array
    {
        preg_match_all('~<li class="admin-tree-item" data-id="(\d+)"~', $body, $matches);

        return array_map(intval(...), $matches[1]);
    }

    private static function version(string $body): string
    {
        return preg_match('~name="version" value="([0-9a-f]{40})"~', $body, $match) === 1 ? $match[1] : self::fail('No version on the page');
    }

    private static function row(string $body, Entry $entry): string
    {
        return preg_match('~data-id="' . $entry->id . '">\s*<div class="admin-tree-row">(.*?)</div>~s', $body, $match) === 1
            ? $match[1]
            : self::fail('No row for entry #' . $entry->id);
    }
}
