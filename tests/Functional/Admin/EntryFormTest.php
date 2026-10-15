<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use DateTimeImmutable;

final class EntryFormTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        // A role that can create and edit articles but was never granted "view", for testCreateAndEditRequireView().
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'contributor' => ['label' => 'Contributor', 'permissions' => ['entries.articles.create', 'entries.articles.edit']],
        ]));
    }

    public function testOnlyFormsWithAYouTubeFieldLoadItsCardAndCovers(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $articles = $this->browser->get('/admin/content/articles/new');
        $events = $this->browser->get('/admin/content/events/new');

        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/youtube\.js\?v=[0-9a-f]{12}" nonce="[^"]+"></script>~', (string) $articles->getBody());
        self::assertMatchesRegularExpression("~img-src 'self' data: blob:[^;]* https://i\.ytimg\.com[;\s]~", $articles->getHeaderLine('Content-Security-Policy'), 'the video covers');
        self::assertStringNotContainsString('youtube.js', (string) $events->getBody());
        self::assertStringNotContainsString('i.ytimg.com', $events->getHeaderLine('Content-Security-Policy'));
    }

    public function testCreateAndEditRequireView(): void
    {
        $contributor = $this->createUser('co@example.com', 'contributor');
        $own = $this->entry('articles', 'Own draft', ['slug' => 'own-draft', 'status' => EntryStatus::Draft, 'author' => $contributor]);
        $this->actingAs($contributor);

        self::assertSame(403, $this->browser->get('/admin/content/articles/new')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Sneaked in',
            'status' => 'draft',
        ])->getStatusCode());
        self::assertSame(403, $this->browser->get('/admin/content/articles/' . $own->id)->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $own->id, $this->editPost('Hijacked', '2026-09-28 12:00:00'))->getStatusCode());

        self::assertNull($this->app->container()->service(EntryRepository::class)->findBySlug('articles', 'en', 'sneaked-in'));
        self::assertSame('Own draft', $this->app->container()->service(EntryRepository::class)->find($own->id)?->title);
    }

    public function testEditorCreatesAnEntry(): void
    {
        $editor = $this->createUser('ed@example.com', 'editor');
        $this->actingAs($editor);

        $form = (string) $this->browser->get('/admin/content/articles/new')->getBody();
        self::assertStringContainsString('data-rich-text', $form);
        self::assertStringContainsString('name="fields[rating]"', $form);
        self::assertStringContainsString('<input type="hidden" name="locale" value="en">', $form);
        self::assertStringContainsString('<input type="radio" name="status" value="published" data-status-choice>', $form);

        $response = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'status' => 'draft',
            'fields' => ['summary' => 'Short', 'rating' => '4', 'featured' => '1'],
        ]);

        $entry = $this->app->container()->service(EntryRepository::class)->findBySlug('articles', 'en', 'hello');
        self::assertNotNull($entry);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/content/articles/' . $entry->id, $response->getHeaderLine('Location'));
        self::assertSame($editor->id, $entry->authorId);
        self::assertSame(['summary' => 'Short', 'rating' => 4, 'featured' => true], $entry->data);
        self::assertStringContainsString('Entry created.', (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody());
    }

    public function testErrorsRerenderTheSubmittedValuesEscaped(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => '',
            'fields' => ['summary' => '</textarea><script>x</script>', 'rating' => '11'],
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Please fix 2 fields.', $body);
        self::assertStringContainsString('This field is required.', $body);
        self::assertStringContainsString('The value may not be greater than 10.', $body);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;x&lt;/script&gt;</textarea>', $body);
        self::assertStringNotContainsString('<script>x', $body);
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
    }

    public function testUpdatesAndStaleForms(): void
    {
        $entry = $this->entry('articles', 'Original', ['slug' => 'original']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $form = (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody();
        self::assertStringContainsString('<input type="hidden" name="version" value="2026-09-28 12:00:00">', $form);

        $this->clock->advance('+1 minute');
        $saved = $this->browser->post('/admin/content/articles/' . $entry->id, $this->editPost('Changed', '2026-09-28 12:00:00'));
        self::assertSame(303, $saved->getStatusCode());
        self::assertStringContainsString('Changes saved.', (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody());

        $stale = $this->browser->post('/admin/content/articles/' . $entry->id, $this->editPost('Overwrite', '2026-09-28 12:00:00'));
        self::assertSame(422, $stale->getStatusCode());
        $staleBody = (string) $stale->getBody();
        self::assertMatchesRegularExpression('~admin-toast-error.*Someone else saved this entry after you opened it\.~s', $staleBody, 'a refusal without a field is a toast');
        self::assertStringNotContainsString('class="admin-alert"', $staleBody);
        self::assertStringContainsString('value="Overwrite"', $staleBody, 'the input is kept');
        self::assertSame('Changed', $this->app->container()->service(EntryRepository::class)->find($entry->id)?->title);
    }

    public function testAuthorsWorkOnTheirOwnDraftsOnly(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $others = $this->entry('articles', 'Someone else', ['slug' => 'someone', 'status' => EntryStatus::Draft]);
        $ownPublished = $this->entry('articles', 'Mine live', ['slug' => 'mine-live', 'author' => $author]);
        $this->actingAs($author);

        $form = (string) $this->browser->get('/admin/content/articles/new')->getBody();
        self::assertStringNotContainsString('value="published"', $form);
        self::assertStringNotContainsString('name="published_at"', $form);

        $forged = $this->browser->post('/admin/content/articles', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'title' => 'Forged', 'status' => 'published']);
        self::assertSame(422, $forged->getStatusCode());
        self::assertStringContainsString('You cannot publish in this section.', (string) $forged->getBody());

        $created = $this->browser->post('/admin/content/articles', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'title' => 'My draft', 'status' => 'draft']);
        self::assertSame(303, $created->getStatusCode());

        self::assertSame(403, $this->browser->get('/admin/content/articles/' . $others->id)->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $others->id, $this->editPost('Hijack', '2026-09-28 12:00:00'))->getStatusCode());
        self::assertSame(403, $this->browser->get('/admin/content/articles/' . $ownPublished->id)->getStatusCode());
        self::assertSame('Someone else', $this->app->container()->service(EntryRepository::class)->find($others->id)?->title);

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame(200, $this->browser->get('/admin/content/articles/' . $ownPublished->id)->getStatusCode());
    }

    public function testAnEntryIsOnlyReachableThroughItsOwnCollection(): void
    {
        $article = $this->entry('articles', 'Article', ['slug' => 'article']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame(404, $this->browser->get('/admin/content/pages/' . $article->id)->getStatusCode());
        self::assertSame(404, $this->browser->post('/admin/content/pages/' . $article->id, $this->editPost('X', '2026-09-28 12:00:00'))->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/content/articles/99999')->getStatusCode());
    }

    public function testNonTranslatableCollectionsAndSingletons(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertStringContainsString('<input type="hidden" name="locale" value="en">', (string) $this->browser->get('/admin/content/events/new?locale=ru')->getBody());
        $this->browser->post('/admin/content/events', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'title' => 'Concert']);
        self::assertNotNull($this->app->container()->service(EntryRepository::class)->findBySlug('events', 'en', 'concert'));

        $singleForm = (string) $this->browser->get('/admin/content/contact/new')->getBody();
        self::assertStringNotContainsString('name="slug"', $singleForm);
        $stored = $this->browser->post('/admin/content/contact', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'title' => 'Contact', 'fields' => ['phone' => '+1']]);
        self::assertSame(303, $stored->getStatusCode());
        self::assertSame($stored->getHeaderLine('Location'), $this->browser->get('/admin/content/contact/new')->getHeaderLine('Location'));
    }

    public function testHierarchyLocaleTabsAndPreview(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about', 'status' => EntryStatus::Draft]);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $teamForm = (string) $this->browser->get('/admin/content/pages/' . $team->id)->getBody();
        self::assertStringContainsString('<option value="' . $about->id . '" selected>About</option>', $teamForm);
        self::assertStringNotContainsString('<option value="' . $team->id . '"', $teamForm);
        self::assertStringContainsString('The parent page is not published', $teamForm);

        $helloForm = (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody();
        self::assertMatchesRegularExpression('~<h1>Hello <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>~', $helloForm);
        self::assertMatchesRegularExpression('~<a href="/admin/content/articles/' . $privet->id . '\?locale=ru" hreflang="ru" title="Русский"><span aria-hidden="true">RU</span><svg[^>]*><use href="[^"]+#check"></use></svg><span class="admin-visually-hidden">Русский: open the translation</span></a>~', $helloForm);
        self::assertStringContainsString('formaction="/admin/content/articles/' . $hello->id . '/preview"', $helloForm);
        self::assertStringContainsString('name="meta_title"', $helloForm);

        $note = $this->entry('notes', 'Note', ['fields' => ['text' => 'x']]);
        $noteForm = (string) $this->browser->get('/admin/content/notes/' . $note->id)->getBody();
        self::assertStringNotContainsString('data-preview', $noteForm);
        self::assertStringNotContainsString('admin-visually-hidden" tabindex="-1"', $noteForm);
        self::assertStringNotContainsString('name="meta_title"', $noteForm);
    }

    public function testLanguageTabsLinkOnlyWhenTheTranslationIsEditable(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'author' => $author, 'status' => EntryStatus::Draft]);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->actingAs($author);

        $form = (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody();

        self::assertStringNotContainsString('href="/admin/content/articles/' . $privet->id . '?locale=ru"', $form);
        self::assertMatchesRegularExpression('~<span class="admin-translations-exists" title="Русский"><span aria-hidden="true">RU</span><svg[^>]*><use href="[^"]+#check"></use></svg><span class="admin-visually-hidden">Русский: translated</span></span>~', $form);
    }

    public function testForgedPublishedOrScheduledStatusOnUpdateIsRejected(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $draft = $this->entry('articles', 'My draft', ['slug' => 'my-draft', 'status' => EntryStatus::Draft, 'author' => $author]);
        $this->actingAs($author);
        $version = $draft->updatedAt->format('Y-m-d H:i:s');

        $published = $this->browser->post('/admin/content/articles/' . $draft->id, [
            '_csrf' => $this->csrfToken(),
            'version' => $version,
            'title' => 'Sneaky published',
            'status' => 'published',
        ]);
        self::assertSame(422, $published->getStatusCode());
        self::assertStringContainsString('You cannot publish in this section.', (string) $published->getBody());

        $scheduled = $this->browser->post('/admin/content/articles/' . $draft->id, [
            '_csrf' => $this->csrfToken(),
            'version' => $version,
            'title' => 'Sneaky scheduled',
            'status' => 'scheduled',
            'published_at' => '2026-10-05T09:00',
        ]);
        self::assertSame(422, $scheduled->getStatusCode());
        self::assertStringContainsString('You cannot publish in this section.', (string) $scheduled->getBody());

        $stored = $this->app->container()->service(EntryRepository::class)->find($draft->id);
        self::assertSame(EntryStatus::Draft, $stored?->status);
        self::assertSame('My draft', $stored->title);
    }

    public function testAuthorSavingADraftKeepsAScheduledPublishDate(): void
    {
        $editor = $this->createUser('ed@example.com', 'editor');
        $author = $this->createUser('au@example.com', 'author');
        $draft = $this->entry('articles', 'Prepped', [
            'slug' => 'prepped',
            'status' => EntryStatus::Draft,
            'author' => $author,
            'publishedAt' => new DateTimeImmutable('2026-10-05 09:00:00'),
        ]);
        $this->actingAs($editor);
        // The editor schedules a future publish date while keeping the entry a draft.
        $editorSave = $this->browser->post('/admin/content/articles/' . $draft->id, [
            '_csrf' => $this->csrfToken(),
            'version' => $draft->updatedAt->format('Y-m-d H:i:s'),
            'title' => 'Prepped',
            'status' => 'draft',
            'published_at' => '2026-10-05T09:00',
        ]);
        self::assertSame(303, $editorSave->getStatusCode());
        $afterEditor = $this->app->container()->service(EntryRepository::class)->find($draft->id);
        self::assertSame('2026-10-05 09:00:00', $afterEditor?->publishedAt?->format('Y-m-d H:i:s'));

        // The entry is now shared with the author, who saves it without ever seeing the published_at field.
        $this->actingAs($author);
        $authorSave = $this->browser->post('/admin/content/articles/' . $draft->id, [
            '_csrf' => $this->csrfToken(),
            'version' => $afterEditor->updatedAt->format('Y-m-d H:i:s'),
            'title' => 'Prepped v2',
            'status' => 'draft',
        ]);
        self::assertSame(303, $authorSave->getStatusCode());

        $updated = $this->app->container()->service(EntryRepository::class)->find($draft->id);
        self::assertSame('Prepped v2', $updated?->title);
        self::assertSame('2026-10-05 09:00:00', $updated->publishedAt?->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, mixed>
     */
    private function editPost(string $title, string $version): array
    {
        return ['_csrf' => $this->csrfToken(), 'version' => $version, 'title' => $title, 'status' => 'published'];
    }
}
