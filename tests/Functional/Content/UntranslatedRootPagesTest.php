<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Field\Field;
use AtarCms\Content\TermService;
use AtarCms\Kernel\Container;

/**
 * The fixture's "info" pages, made untranslated here, share the root with the sections: their route turns Russian
 * addresses away, and the Russian sections answer them.
 */
final class UntranslatedRootPagesTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $collections = $container->service(Collections::class);
        $container->set(Collections::class, new Collections(
            [...$collections->all(), 'info' => Collection::make('info')->label('Info pages')->route('/{slug}')->fields([Field::richText('body')])],
            $collections->taxonomies,
        ));
    }

    public function testRussianAddressesGoToTheSections(): void
    {
        $this->entry('info', 'About us', ['slug' => 'about']);
        $about = $this->term('sections', 'About', ['locale' => 'ru', 'slug' => 'about']);
        $guides = $this->term('sections', 'Guides');
        $this->app->container()->service(TermService::class)->createTranslation($guides, 'ru', null, null);
        $this->term('sections', 'История', ['locale' => 'ru', 'slug' => 'istoriya', 'parent' => $about]);

        self::assertStringContainsString('<div class="generic" data-collection="info">', (string) $this->browser->get('/about')->getBody());
        $russian = $this->browser->get('/ru/about');
        self::assertSame(302, $russian->getStatusCode(), 'the Russian section, not the English page');
        self::assertSame('/ru/about/istoriya', $russian->getHeaderLine('Location'));
        self::assertSame(200, $this->browser->get('/ru/guides')->getStatusCode());
        self::assertSame(404, $this->browser->get('/ru/ghost')->getStatusCode());
    }
}
