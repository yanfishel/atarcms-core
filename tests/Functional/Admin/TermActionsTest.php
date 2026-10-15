<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\EntryRepository;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

final class TermActionsTest extends ContentFunctionalTestCase
{
    public function testCreatesTranslationsWithTheirParents(): void
    {
        $code = $this->term('topics', 'Code');
        $php = $this->term('topics', 'PHP', ['parent' => $code]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/taxonomies/topics/' . $code->id)->getBody();
        self::assertStringContainsString('action="/admin/taxonomies/topics/' . $code->id . '/translate"', $form);
        self::assertStringContainsString('href="/admin/taxonomies/topics/' . $code->id . '/delete"', $form);

        $response = $this->browser->post('/admin/taxonomies/topics/' . $code->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        $codeRu = $this->terms()->findBySlug('topics', 'ru', 'code');
        self::assertNotNull($codeRu);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/taxonomies/topics/' . $codeRu->id . '?locale=ru', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Copy for translation created.', (string) $this->browser->get('/admin/taxonomies/topics/' . $codeRu->id)->getBody());

        $this->browser->post('/admin/taxonomies/topics/' . $php->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        self::assertSame($codeRu->id, $this->terms()->findBySlug('topics', 'ru', 'php')?->parentId);

        $again = $this->browser->post('/admin/taxonomies/topics/' . $code->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        self::assertSame('/admin/taxonomies/topics/' . $code->id, $again->getHeaderLine('Location'));
        self::assertStringContainsString('A translation for this language already exists.', (string) $this->browser->get('/admin/taxonomies/topics/' . $code->id)->getBody());
    }

    public function testDeletingATermRemovesItFromEntries(): void
    {
        $php = $this->term('topics', 'PHP <b>');
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['topics' => [$php->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $confirm = (string) $this->browser->get('/admin/taxonomies/topics/' . $php->id . '/delete')->getBody();
        self::assertStringContainsString('Delete “PHP &lt;b&gt;” (English)? It is removed from 1 entry; other languages stay.', $confirm);
        self::assertStringContainsString('href="/admin/taxonomies/topics/' . $php->id . '"', $confirm);

        $deleted = $this->browser->post('/admin/taxonomies/topics/' . $php->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/taxonomies/topics?locale=en', $deleted->getHeaderLine('Location'));
        self::assertNull($this->terms()->find($php->id));
        self::assertNotNull($this->app->container()->service(EntryRepository::class)->find($hello->id));
        self::assertSame([], $this->terms()->forEntries([$hello->id]));
        self::assertStringContainsString('Deleted.', (string) $this->browser->get('/admin/taxonomies/topics')->getBody());
        self::assertContains('term.deleted', $this->auditActions());
    }

    public function testTermsWithSubtermsCannotBeDeleted(): void
    {
        $code = $this->term('topics', 'Code');
        $this->term('topics', 'PHP', ['parent' => $code]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $blocked = $this->browser->post('/admin/taxonomies/topics/' . $code->id . '/delete', ['_csrf' => $this->csrfToken()]);

        self::assertSame(303, $blocked->getStatusCode(), 'a refused delete is an error toast on the edit page');
        self::assertSame('/admin/taxonomies/topics/' . $code->id, $blocked->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('~admin-toast-error.*Delete or move the items inside it first\.~s', (string) $this->browser->get('/admin/taxonomies/topics/' . $code->id)->getBody());
        self::assertNotNull($this->terms()->find($code->id));
    }

    public function testActionsNeedThePermissionAndTheRightTaxonomy(): void
    {
        $php = $this->term('topics', 'PHP');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        self::assertSame(403, $this->browser->post('/admin/taxonomies/topics/' . $php->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getStatusCode());
        self::assertSame(403, $this->browser->get('/admin/taxonomies/topics/' . $php->id . '/delete')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/taxonomies/topics/' . $php->id . '/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertNotNull($this->terms()->find($php->id));
        self::assertNull($this->terms()->findBySlug('topics', 'ru', 'php'));

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame(404, $this->browser->post('/admin/taxonomies/tags/' . $php->id . '/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertNotNull($this->terms()->find($php->id));
    }
}
