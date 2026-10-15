<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Field\Field;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * The fixture's stories, made untranslated here: they exist only in the default language and take only sections of
 * that language, so on the Sections screen a section of another language holds none of them and its name links nowhere.
 */
final class SectionsUntranslatedArticlesTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $collections = $container->service(Collections::class);
        $container->set(Collections::class, new Collections(
            [
                ...$collections->all(),
                'stories' => Collection::make('stories')->label('Stories')->route('/{sections}/{slug}')->archive('/stories')->taxonomies(['sections'])->fields([Field::richText('body')]),
            ],
            $collections->taxonomies,
        ));
    }

    public function testASectionOfAnotherLanguageIsPlainText(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->term('sections', 'Гайды', ['locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $english = (string) $this->browser->get('/admin/sections?locale=en')->getBody();
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/stories?locale=en&amp;term=' . $guides->id . '">Guides</a>', $english);

        $russian = (string) $this->browser->get('/admin/sections?locale=ru')->getBody();
        self::assertStringContainsString('<span class="admin-tree-label">Гайды</span>', $russian);
        self::assertStringNotContainsString('href="/admin/content/stories?', $russian);
    }
}
