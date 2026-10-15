<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\RevisionRepository;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use Psr\Http\Message\ResponseInterface;

/**
 * The entry form saves a draft by itself (autosave.js): JSON endpoints beside store/update that only ever save drafts,
 * check the version like a manual save and answer without a flash.
 */
final class EntryAutosaveTest extends ContentFunctionalTestCase
{
    public function testTheFirstAutosaveCreatesADraftAndTheNextOnesSaveIt(): void
    {
        $editor = $this->createUser('ed@example.com', 'editor');
        $this->actingAs($editor);

        $created = $this->autosave('/admin/content/articles/autosave', ['locale' => 'en', 'title' => 'Typed', 'status' => 'draft']);

        self::assertSame(200, $created->getStatusCode());
        $json = self::json($created);
        $entry = $this->repository()->findBySlug('articles', 'en', 'typed');
        self::assertNotNull($entry);
        self::assertSame(EntryStatus::Draft, $entry->status);
        self::assertSame($editor->id, $entry->authorId);
        self::assertSame([
            'id' => $entry->id,
            'version' => '2026-09-28 12:00:00',
            'action' => '/admin/content/articles/' . $entry->id,
            'edit' => '/admin/content/articles/' . $entry->id,
            'autosave' => '/admin/content/articles/' . $entry->id . '/autosave',
            'preview' => '/admin/content/articles/' . $entry->id . '/preview',
            'previewTarget' => 'atar-preview-articles-' . $entry->id,
        ], $json);
        self::assertStringNotContainsString('Entry created.', (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody(), 'no flash');

        $this->clock->advance('+1 minute');
        $saved = $this->autosave($json['autosave'], ['version' => $json['version'], 'locale' => 'en', 'title' => 'Typed more', 'status' => 'draft']);
        self::assertSame(200, $saved->getStatusCode());
        self::assertSame('2026-09-28 12:01:00', self::json($saved)['version']);
        self::assertSame('Typed more', $this->repository()->find($entry->id)?->title);
        self::assertCount(1, $this->app->container()->service(RevisionRepository::class)->forEntry($entry->id));
        self::assertSame(['entry.created'], $this->auditActions());
    }

    public function testAnInvalidFormIsSkippedQuietly(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $draft = $this->entry('articles', 'Draft', ['status' => EntryStatus::Draft]);

        $response = $this->autosave('/admin/content/articles/' . $draft->id . '/autosave', ['version' => self::version($draft), 'title' => '', 'status' => 'draft']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['invalid' => true], self::json($response));
        self::assertSame('Draft', $this->repository()->find($draft->id)?->title);

        $new = $this->autosave('/admin/content/articles/autosave', ['locale' => 'en', 'title' => '', 'status' => 'draft']);
        self::assertSame(422, $new->getStatusCode());
        self::assertSame(0, Cast::int($this->db->selectOne("SELECT COUNT(*) AS n FROM entries WHERE collection = 'articles' AND title = ''")['n'] ?? -1));
    }

    public function testOnlyDraftsAreAutosavedAndAStaleVersionIsRefused(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $draft = $this->entry('articles', 'Draft', ['status' => EntryStatus::Draft]);
        $published = $this->entry('articles', 'Published');

        $stale = $this->autosave('/admin/content/articles/' . $draft->id . '/autosave', ['version' => '2000-01-01 00:00:00', 'title' => 'Mine', 'status' => 'draft']);
        self::assertSame(409, $stale->getStatusCode());
        self::assertStringContainsString('Someone else saved this entry', Cast::string(self::json($stale)['error']));

        foreach ([
            [$published, 'draft'],
            [$draft, 'published'],
            [$draft, 'scheduled'],
        ] as [$entry, $status]) {
            $refused = $this->autosave('/admin/content/articles/' . $entry->id . '/autosave', ['version' => self::version($entry), 'title' => 'Changed', 'status' => $status]);
            self::assertSame(409, $refused->getStatusCode());
            self::assertSame('Only drafts are saved automatically.', self::json($refused)['error']);
        }
        self::assertSame(409, $this->autosave('/admin/content/articles/autosave', ['locale' => 'en', 'title' => 'New', 'status' => 'published'])->getStatusCode());
        self::assertSame(409, $this->autosave('/admin/content/welcome/autosave', ['locale' => 'en', 'title' => 'Plain'])->getStatusCode(), 'a plain single is always published');
        self::assertSame('Draft', $this->repository()->find($draft->id)?->title);
        self::assertSame(EntryStatus::Published, $this->repository()->find($published->id)?->status);
    }

    public function testAnAuthorsAutosaveKeepsTheStoredPublicationDate(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $date = new \DateTimeImmutable('2026-10-05 09:30:00');
        $draft = $this->entry('articles', 'Planned', ['status' => EntryStatus::Draft, 'publishedAt' => $date, 'author' => $author]);
        $this->actingAs($author);

        $response = $this->autosave('/admin/content/articles/' . $draft->id . '/autosave', ['version' => self::version($draft), 'title' => 'Planned more', 'status' => 'draft']);

        self::assertSame(200, $response->getStatusCode());
        self::assertEquals($date, $this->repository()->find($draft->id)?->publishedAt, 'an author never sees the date input: its absence is no change');
    }

    public function testAutosaveNeedsTheRightsOfSavingAndAToken(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $others = $this->entry('articles', 'Others', ['status' => EntryStatus::Draft]);
        $this->actingAs($author);

        self::assertSame(403, $this->autosave('/admin/content/articles/' . $others->id . '/autosave', ['version' => self::version($others), 'title' => 'Mine', 'status' => 'draft'])->getStatusCode());
        self::assertSame(404, $this->autosave('/admin/content/pages/' . $others->id . '/autosave', ['title' => 'Elsewhere', 'status' => 'draft'])->getStatusCode());
        self::assertSame(419, $this->browser->post('/admin/content/articles/autosave', ['title' => 'No token'], ['Accept' => 'application/json'])->getStatusCode());
    }

    public function testTheFormOffersAutosaveForDraftsOnly(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $draft = $this->entry('articles', 'Draft', ['status' => EntryStatus::Draft]);
        $published = $this->entry('articles', 'Published');

        $new = (string) $this->browser->get('/admin/content/articles/new')->getBody();
        self::assertStringContainsString('data-entry-form data-autosave="/admin/content/articles/autosave">', $new);
        self::assertStringContainsString('autosave.js', $new);
        self::assertStringContainsString(
            'data-autosave="/admin/content/articles/' . $draft->id . '/autosave"',
            (string) $this->browser->get('/admin/content/articles/' . $draft->id)->getBody(),
        );
        $publishedForm = (string) $this->browser->get('/admin/content/articles/' . $published->id)->getBody();
        self::assertStringContainsString('data-entry-form>', $publishedForm);
        self::assertStringContainsString('autosave.js', $publishedForm, 'the script also asks before leaving unsaved changes');
        self::assertStringNotContainsString('data-autosave=', $publishedForm);
        self::assertStringNotContainsString('data-autosave=', (string) $this->browser->get('/admin/content/welcome/new')->getBody(), 'a plain single is always published');

        self::assertStringContainsString(
            '<p class="admin-action-bar-status" data-autosave-status data-saving="Saving…" data-saved-now="Saved just now"'
            . ' data-saved-minutes="{&quot;other&quot;:&quot;Saved # minutes ago&quot;,&quot;one&quot;:&quot;Saved # minute ago&quot;}"'
            . ' data-saved-hours="{&quot;other&quot;:&quot;Saved # hours ago&quot;,&quot;one&quot;:&quot;Saved # hour ago&quot;}" data-locale="en">'
            . '<span data-autosave-text>Not saved yet</span><span class="admin-visually-hidden" role="status" data-autosave-announce></span></p>',
            $new,
        );
        self::assertMatchesRegularExpression('~<template data-autosave-failed data-expired="The page has expired[^"]*" data-forbidden="You can no longer save this entry[^"]*"><div class="admin-toast admin-toast-error" role="alert" data-toast>.*?Your changes could not be saved automatically.*?</div></template>~', $new);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function autosave(string $uri, array $data): ResponseInterface
    {
        return $this->browser->post($uri, ['_csrf' => $this->csrfToken(), ...$data], ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $json = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        /** @var array<string, mixed> $json */
        return $json;
    }

    private static function version(Entry $entry): string
    {
        return $entry->updatedAt->format('Y-m-d H:i:s');
    }

    private function repository(): EntryRepository
    {
        return $this->app->container()->service(EntryRepository::class);
    }
}
