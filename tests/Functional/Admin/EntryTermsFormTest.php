<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\EntryRepository;
use AtarCms\Content\Term;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

final class EntryTermsFormTest extends ContentFunctionalTestCase
{
    public function testEditorsPickTermsAndTypeTags(): void
    {
        $code = $this->term('topics', 'Code <i>');
        $php = $this->term('topics', 'PHP', ['parent' => $code]);
        $this->term('topics', 'Код', ['locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/articles/new')->getBody();
        self::assertStringContainsString('<label class="admin-check admin-depth-0"><input type="checkbox" name="terms[topics][]" value="' . $code->id . '"> Code &lt;i&gt;</label>', $form);
        self::assertStringContainsString('<label class="admin-check admin-depth-1"><input type="checkbox" name="terms[topics][]" value="' . $php->id . '"> PHP</label>', $form);
        self::assertStringNotContainsString('Код', $form);
        self::assertStringContainsString('<input id="field-tags" name="fields[tags]" value="" autocomplete="off" class="admin-tags-value" data-tags-value lang="en" dir="ltr">', $form);

        $response = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'status' => 'draft',
            'terms' => ['topics' => [(string) $php->id]],
            'fields' => ['tags' => 'News, <b>Bold</b>'],
        ]);
        $entry = $this->app->container()->service(EntryRepository::class)->findBySlug('articles', 'en', 'hello');
        self::assertNotNull($entry);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['News', '<b>Bold</b>', 'PHP'], array_map(static fn(Term $t): string => $t->name, $this->terms()->forEntries([$entry->id])[$entry->id] ?? []));

        $edit = (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody();
        self::assertStringContainsString('value="' . $php->id . '" checked> PHP</label>', $edit);
        self::assertStringContainsString('value="News, &lt;b&gt;Bold&lt;/b&gt;"', $edit);
        self::assertStringNotContainsString('<b>Bold</b>', $edit);
    }

    public function testTamperedTermIdsAreRejected(): void
    {
        $russian = $this->term('topics', 'Код', ['locale' => 'ru']);
        $tag = $this->term('tags', 'News');
        $region = $this->term('regions', 'North');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        foreach ([$russian->id, $tag->id, $region->id, 999999] as $id) {
            $response = $this->browser->post('/admin/content/articles', [
                '_csrf' => $this->csrfToken(),
                'locale' => 'en',
                'title' => 'Tampered',
                'status' => 'draft',
                'terms' => ['topics' => [(string) $id]],
            ]);
            self::assertSame(422, $response->getStatusCode(), (string) $id);
            self::assertStringContainsString('Choose existing categories or tags of this language.', (string) $response->getBody());
        }
        self::assertNull($this->app->container()->service(EntryRepository::class)->findBySlug('articles', 'en', 'tampered'));
    }

    public function testAuthorsCreateTagsButCannotManageTerms(): void
    {
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $response = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Mine',
            'status' => 'draft',
            'fields' => ['tags' => 'Fresh'],
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertNotNull($this->terms()->findBySlug('tags', 'en', 'fresh'));
        self::assertSame(403, $this->browser->get('/admin/taxonomies/tags')->getStatusCode());
    }

    public function testNonTranslatableCollectionsPickDefaultLocaleTerms(): void
    {
        $north = $this->term('regions', 'North');
        $this->term('regions', 'Север', ['locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/events/new')->getBody();
        self::assertStringContainsString('> North</label>', $form);
        self::assertStringNotContainsString('Север', $form);

        $response = $this->browser->post('/admin/content/events', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Concert',
            'status' => 'draft',
            'terms' => ['regions' => [(string) $north->id]],
        ]);
        $event = $this->app->container()->service(EntryRepository::class)->findBySlug('events', 'en', 'concert');

        self::assertSame(303, $response->getStatusCode());
        self::assertNotNull($event);
        self::assertSame([$north->id], $this->terms()->idsForEntry($event->id));
    }
}
