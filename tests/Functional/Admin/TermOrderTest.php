<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\Term;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

final class TermOrderTest extends ContentFunctionalTestCase
{
    public function testOnlyHierarchicalTaxonomiesAndTaxonomyManagersGetTheOrderScreen(): void
    {
        $code = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        self::assertSame(403, $this->browser->get('/admin/taxonomies/topics/order')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/taxonomies/topics/order', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => str_repeat('0', 40), 'tree' => '[]'])->getStatusCode());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame(404, $this->browser->get('/admin/taxonomies/tags/order')->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/taxonomies/ghost/order')->getStatusCode());
        self::assertStringContainsString('href="/admin/taxonomies/topics/order?locale=en"', (string) $this->browser->get('/admin/taxonomies/topics')->getBody());
        self::assertStringNotContainsString('/order', (string) $this->browser->get('/admin/taxonomies/tags')->getBody());
        self::assertSame(0, $this->terms()->find($code->id)?->position);
    }

    public function testShowsAndSavesTheTermTreeAndMakesOpenFormsStale(): void
    {
        $code = $this->term('topics', 'Code <b>');
        $php = $this->term('topics', 'PHP', ['parent' => $code]);
        $go = $this->term('topics', 'Go', ['position' => 1]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/topics/order')->getBody();
        self::assertStringContainsString('<h1>Order of Topics <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>', $body);
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/taxonomies/topics/' . $code->id . '">Code &lt;b&gt;</a>', $body);
        self::assertStringContainsString('<span class="admin-muted admin-tree-meta">php</span>', $body);
        preg_match('~name="version" value="([0-9a-f]{40})"~', $body, $version);

        $this->clock->advance('+1 minute');
        $response = $this->browser->post('/admin/taxonomies/topics/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => $version[1] ?? '',
            'tree' => sprintf('[{"id":%d},{"id":%d,"children":[{"id":%d}]}]', $go->id, $code->id, $php->id),
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/taxonomies/topics/order?locale=en', $response->getHeaderLine('Location'));
        self::assertSame([[null, 0], [null, 1], [$code->id, 0]], [$this->placement($go), $this->placement($code), $this->placement($php)]);
        self::assertStringContainsString('Order saved.', (string) $this->browser->get('/admin/taxonomies/topics/order')->getBody());

        $staleForm = $this->browser->post('/admin/taxonomies/topics/' . $go->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'name' => 'Go',
            'slug' => 'go',
            'parent_id' => '',
        ]);
        self::assertSame(422, $staleForm->getStatusCode());
        self::assertStringContainsString('Someone else saved this item after you opened it.', (string) $staleForm->getBody());
        self::assertSame([null, 0], $this->placement($go));
    }

    public function testABackgroundSaveAnswersWithTheNewVersion(): void
    {
        $code = $this->term('topics', 'Code');
        $go = $this->term('topics', 'Go', ['position' => 1]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        preg_match('~name="version" value="([0-9a-f]{40})"~', (string) $this->browser->get('/admin/taxonomies/topics/order')->getBody(), $loaded);

        $saved = $this->browser->post('/admin/taxonomies/topics/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => $loaded[1] ?? '',
            'tree' => sprintf('[{"id":%d},{"id":%d}]', $go->id, $code->id),
        ], ['Accept' => 'application/json']);

        self::assertSame(200, $saved->getStatusCode());
        preg_match('~name="version" value="([0-9a-f]{40})"~', (string) $this->browser->get('/admin/taxonomies/topics/order')->getBody(), $now);
        self::assertSame(['version' => $now[1] ?? ''], json_decode((string) $saved->getBody(), true));
        self::assertSame([[null, 1], [null, 0]], [$this->placement($code), $this->placement($go)]);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function placement(Term $term): array
    {
        $stored = $this->terms()->find($term->id);

        return [$stored?->parentId, $stored?->position];
    }
}
