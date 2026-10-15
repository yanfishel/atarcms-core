<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Terms;

use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * Tags are kept separately per language (Plan 35, user decision): a taxonomy filled only by tags fields
 * (Collections::onlyTagged(), the rule of its missing "Add new") offers no translating. Its term form and dialog have
 * no Translations line, the translate action refuses (404), the list makes no "Copy for translation" offer and the
 * content language switch from a tag's form goes to that language's list. Other taxonomies keep all of it.
 */
final class TagTranslationsTest extends ContentFunctionalTestCase
{
    public function testATagsFormAndDialogHaveNoTranslationsLine(): void
    {
        $tag = $this->term('tags', 'News');
        $topic = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        foreach ([[], ['HX-Request' => 'true']] as $headers) {
            $tagForm = (string) $this->browser->get('/admin/taxonomies/tags/' . $tag->id, $headers)->getBody();
            self::assertStringContainsString('News', $tagForm);
            self::assertStringNotContainsString('admin-translations', $tagForm);
            self::assertStringNotContainsString('/translate', $tagForm);
            self::assertStringContainsString('admin-translations', (string) $this->browser->get('/admin/taxonomies/topics/' . $topic->id, $headers)->getBody(), 'other taxonomies keep it');
        }
    }

    public function testATagCannotBeTranslated(): void
    {
        $tag = $this->term('tags', 'News');
        $topic = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame(404, $this->browser->post('/admin/taxonomies/tags/' . $tag->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getStatusCode());
        self::assertSame([], $this->terms()->forTaxonomy('tags', 'ru'));
        self::assertSame(303, $this->browser->post('/admin/taxonomies/topics/' . $topic->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getStatusCode());
        self::assertCount(1, $this->terms()->forTaxonomy('topics', 'ru'));
    }

    public function testTheContentLanguageSwitchOffersNoTagTranslation(): void
    {
        $tag = $this->term('tags', 'News');
        $topic = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame('/admin/taxonomies/tags?locale=ru', $this->switchTo('ru', '/admin/taxonomies/tags/' . $tag->id)->getHeaderLine('Location'));
        self::assertStringNotContainsString('Copy for translation', (string) $this->browser->get('/admin/taxonomies/tags?locale=ru&translate=' . $tag->id)->getBody());
        self::assertSame('/admin/taxonomies/topics?locale=ru&translate=' . $topic->id, $this->switchTo('ru', '/admin/taxonomies/topics/' . $topic->id)->getHeaderLine('Location'));
        self::assertStringContainsString('Copy for translation', (string) $this->browser->get('/admin/taxonomies/topics?locale=ru&translate=' . $topic->id)->getBody());
    }

    private function switchTo(string $locale, string $return): \Psr\Http\Message\ResponseInterface
    {
        return $this->browser->post('/admin/preferences/content-locale', ['_csrf' => $this->csrfToken(), 'locale' => $locale, 'return' => $return]);
    }
}
