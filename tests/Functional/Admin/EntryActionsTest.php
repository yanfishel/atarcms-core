<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\RevisionRepository;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

final class EntryActionsTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        // A role that can translate, delete and restore articles but was never granted "view",
        // for testActionsRequireView().
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'contributor' => ['label' => 'Contributor', 'permissions' => [
                'entries.articles.create',
                'entries.articles.edit',
                'entries.articles.edit_others',
                'entries.articles.delete',
                'entries.articles.publish',
            ]],
            // Can delete (and view) a draft but never edit or publish it, for testDeleteConfirmationCancelsToTheListWhenNotEditable()
            // and testDeletingALiveEntryNeedsThePublishPermission().
            'remover' => ['label' => 'Remover', 'permissions' => ['entries.articles.view', 'entries.articles.delete', 'entries.articles.edit_others']],
        ]));
    }

    public function testCreatesATranslationAsADraft(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody();
        self::assertStringContainsString('action="/admin/content/articles/' . $hello->id . '/translate"', $form);

        $response = $this->browser->post('/admin/content/articles/' . $hello->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        $russian = $this->repository()->findBySlug('articles', 'ru', 'hello');
        self::assertNotNull($russian);
        self::assertSame('/admin/content/articles/' . $russian->id . '?locale=ru', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Copy for translation created.', (string) $this->browser->get('/admin/content/articles/' . $russian->id)->getBody());

        $again = $this->browser->post('/admin/content/articles/' . $hello->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'de']);
        self::assertSame('/admin/content/articles/' . $hello->id, $again->getHeaderLine('Location'));
        self::assertStringContainsString('This language is not available for this section.', (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody());
    }

    public function testDeletesWithConfirmation(): void
    {
        $hello = $this->entry('articles', 'Hello <b>', ['slug' => 'hello']);
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $confirm = (string) $this->browser->get('/admin/content/articles/' . $hello->id . '/delete')->getBody();
        self::assertStringContainsString('Delete “Hello &lt;b&gt;” (English)?', $confirm);
        self::assertStringContainsString('href="/admin/content/articles/' . $hello->id . '"', $confirm);

        $deleted = $this->browser->post('/admin/content/articles/' . $hello->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/content/articles?locale=en', $deleted->getHeaderLine('Location'));
        self::assertNull($this->repository()->find($hello->id));
        self::assertStringContainsString('Entry deleted.', (string) $this->browser->get('/admin/content/articles')->getBody());

        $blocked = $this->browser->post('/admin/content/pages/' . $about->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $blocked->getStatusCode(), 'a refused delete is a toast on the page the confirmation cancels to');
        self::assertSame('/admin/content/pages/' . $about->id, $blocked->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('~admin-toast-error.*Delete or move the subpages first\.~s', (string) $this->browser->get('/admin/content/pages/' . $about->id)->getBody());
        self::assertNotNull($this->repository()->find($about->id));
        self::assertStringNotContainsString('admin-alert', (string) $this->browser->get('/admin/content/pages/' . $about->id . '/delete')->getBody());
    }

    public function testDeleteConfirmationCancelsToTheListWhenNotEditable(): void
    {
        $remover = $this->createUser('rm@example.com', 'remover');
        $entry = $this->entry('articles', 'Removable', ['slug' => 'removable', 'status' => EntryStatus::Draft]);
        $this->actingAs($remover);

        $confirm = (string) $this->browser->get('/admin/content/articles/' . $entry->id . '/delete')->getBody();

        self::assertStringNotContainsString('href="/admin/content/articles/' . $entry->id . '"', $confirm);
        self::assertStringContainsString('href="/admin/content/articles?locale=en"', $confirm);
    }

    public function testDeletingALiveEntryNeedsThePublishPermission(): void
    {
        $live = $this->entry('articles', 'Live', ['slug' => 'live']);
        $this->actingAs($this->createUser('rm@example.com', 'remover'));

        self::assertSame(403, $this->browser->get('/admin/content/articles/' . $live->id . '/delete')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $live->id . '/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertNotNull($this->repository()->find($live->id));
    }

    public function testRevisionsAndRestore(): void
    {
        $entry = $this->entry('articles', 'First', ['slug' => 'first']);
        $this->clock->advance('+1 minute');
        $entry = $this->app->container()->service(EntryService::class)->update($entry, new EntryInput('Second', fields: $entry->data), null, null);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $first = $this->app->container()->service(RevisionRepository::class)->forEntry($entry->id)[1];

        $page = (string) $this->browser->get('/admin/content/articles/' . $entry->id . '/revisions')->getBody();
        self::assertStringContainsString('Earlier versions of “Second”', $page);
        self::assertStringContainsString('action="/admin/content/articles/' . $entry->id . '/revisions/' . $first->id . '/restore"', $page);

        $restored = $this->browser->post('/admin/content/articles/' . $entry->id . '/revisions/' . $first->id . '/restore', ['_csrf' => $this->csrfToken()]);
        self::assertSame('/admin/content/articles/' . $entry->id, $restored->getHeaderLine('Location'));
        self::assertSame('First', $this->repository()->find($entry->id)?->title);
        self::assertStringContainsString('Version restored.', (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody());

        $unknown = $this->browser->post('/admin/content/articles/' . $entry->id . '/revisions/99999/restore', ['_csrf' => $this->csrfToken()]);
        self::assertSame('/admin/content/articles/' . $entry->id . '/revisions', $unknown->getHeaderLine('Location'));
    }

    public function testAnAutosaveRevisionIsMarked(): void
    {
        $editor = $this->createUser('ed@example.com', 'editor');
        $entry = $this->entry('articles', 'First', ['status' => EntryStatus::Draft]);
        $this->app->container()->service(EntryService::class)->update($entry, new EntryInput('Typed'), $editor, null, autosave: true);
        $this->actingAs($editor);

        $page = (string) $this->browser->get('/admin/content/articles/' . $entry->id . '/revisions')->getBody();

        self::assertSame(1, substr_count($page, '<span class="admin-muted">· Autosave</span>'));
    }

    public function testPermissionsAndCollectionChecks(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $others = $this->entry('articles', 'Others', ['slug' => 'others']);
        $own = $this->entry('articles', 'Own', ['slug' => 'own', 'author' => $author, 'status' => EntryStatus::Draft]);
        $this->actingAs($author);

        self::assertSame(403, $this->browser->get('/admin/content/articles/' . $own->id . '/delete')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $own->id . '/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertStringNotContainsString('/delete"', (string) $this->browser->get('/admin/content/articles/' . $own->id)->getBody());
        $revision = $this->app->container()->service(RevisionRepository::class)->forEntry($others->id)[0];
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $others->id . '/revisions/' . $revision->id . '/restore', ['_csrf' => $this->csrfToken()])->getStatusCode());
        $revisionsPage = $this->browser->get('/admin/content/articles/' . $others->id . '/revisions');
        self::assertSame(200, $revisionsPage->getStatusCode());
        self::assertStringNotContainsString('/restore"', (string) $revisionsPage->getBody());

        // The author can view and create in this collection, but cannot translate another user's entry
        // without edit_others: no translation row should appear.
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $others->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getStatusCode());
        self::assertNull($this->repository()->findBySlug('articles', 'ru', 'others'));

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        foreach (['/translate', '/delete', '/revisions/' . $revision->id . '/restore'] as $action) {
            self::assertSame(404, $this->browser->post('/admin/content/pages/' . $others->id . $action, ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getStatusCode(), $action);
        }
        self::assertSame(404, $this->browser->get('/admin/content/pages/' . $others->id . '/revisions')->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/content/pages/' . $others->id . '/delete')->getStatusCode());

        $this->actingAs($this->createUser('nobody@example.com', 'nobody'));
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $others->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getStatusCode());
    }

    public function testActionsRequireView(): void
    {
        // "contributor" can create/edit/edit_others/delete articles but was never granted "view": every
        // action still needs canView() first, before loading the entry or applying its own rule.
        $contributor = $this->createUser('co@example.com', 'contributor');
        $entry = $this->entry('articles', 'Visible', ['slug' => 'visible']);
        $this->actingAs($contributor);

        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $entry->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getStatusCode());
        self::assertSame(403, $this->browser->get('/admin/content/articles/' . $entry->id . '/delete')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $entry->id . '/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        $revision = $this->app->container()->service(RevisionRepository::class)->forEntry($entry->id)[0];
        self::assertSame(403, $this->browser->post('/admin/content/articles/' . $entry->id . '/revisions/' . $revision->id . '/restore', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertNull($this->repository()->findBySlug('articles', 'ru', 'visible'));
        self::assertSame('Visible', $this->repository()->find($entry->id)?->title);
    }

    private function repository(): EntryRepository
    {
        return $this->app->container()->service(EntryRepository::class);
    }
}
