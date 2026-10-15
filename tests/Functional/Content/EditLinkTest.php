<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Auth\Roles;
use AtarCms\Kernel\Container;

final class EditLinkTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        // A role that can create, edit and publish articles but was never granted "view", for
        // testEditLinkRequiresViewAsWellAsEdit().
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'contributor' => ['label' => 'Contributor', 'permissions' => ['entries.articles.create', 'entries.articles.edit', 'entries.articles.publish']],
        ]));
    }

    public function testPublicPagesLinkToTheFormForUsersWhoMayEdit(): void
    {
        $article = $this->entry('articles', 'Hello', ['slug' => 'hello']);

        self::assertStringNotContainsString('class="edit-link"', (string) $this->browser->get('/articles/hello')->getBody());

        $this->actingAs($this->createUser('au@example.com', 'author'));
        self::assertStringNotContainsString('class="edit-link"', (string) $this->browser->get('/articles/hello')->getBody());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertStringContainsString(
            '<a class="edit-link" href="/admin/content/articles/' . $article->id . '">Edit</a>',
            (string) $this->browser->get('/articles/hello')->getBody(),
        );
        self::assertStringNotContainsString('class="edit-link"', (string) $this->browser->get('/articles')->getBody());
    }

    public function testEditLinkRequiresViewAsWellAsEdit(): void
    {
        $contributor = $this->createUser('co@example.com', 'contributor');
        $this->entry('articles', 'No view', ['slug' => 'no-view', 'author' => $contributor]);
        $this->actingAs($contributor);

        self::assertStringNotContainsString('class="edit-link"', (string) $this->browser->get('/articles/no-view')->getBody());
    }
}
