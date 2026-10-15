<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\TermService;

/**
 * The fixture's "sections" redirect a term with children to its first leaf (->redirectToFirstChild()).
 */
final class SectionRedirectTest extends ContentFunctionalTestCase
{
    public function testAParentSectionSendsVisitorsToItsFirstLeaf(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP', ['parent' => $guides, 'position' => 2]);
        $this->term('sections', 'Basics', ['parent' => $php]);
        $this->term('sections', 'Go', ['parent' => $guides, 'position' => 1]);

        $parent = $this->browser->get('/guides?page=2');
        self::assertSame(302, $parent->getStatusCode());
        self::assertSame('/guides/go', $parent->getHeaderLine('Location'), 'the first child by order, without the query');

        $middle = $this->browser->get('/guides/php');
        self::assertSame(302, $middle->getStatusCode());
        self::assertSame('/guides/php/basics', $middle->getHeaderLine('Location'), 'down to a leaf in one step');

        self::assertSame(200, $this->browser->get('/guides/php/basics')->getStatusCode());
        self::assertSame(200, $this->browser->get('/guides/go')->getStatusCode());
    }

    public function testEachLanguageFollowsItsOwnTree(): void
    {
        $service = $this->app->container()->service(TermService::class);
        $guides = $this->term('sections', 'Guides');
        $this->term('sections', 'Go', ['parent' => $guides]);
        $service->createTranslation($guides, 'ru', null, null);

        $russian = $this->browser->get('/ru/guides');
        self::assertSame(200, $russian->getStatusCode(), 'the Russian tree has no child yet: the parent renders');

        $russianGuides = $this->terms()->findBySlug('sections', 'ru', 'guides');
        self::assertNotNull($russianGuides);
        $this->term('sections', 'Основы', ['locale' => 'ru', 'slug' => 'osnovy', 'parent' => $russianGuides]);
        $redirect = $this->browser->get('/ru/guides');
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame('/ru/guides/osnovy', $redirect->getHeaderLine('Location'));
    }

    public function testOtherTaxonomiesRenderTheirParents(): void
    {
        $code = $this->term('topics', 'Code');
        $this->term('topics', 'PHP', ['parent' => $code]);

        self::assertSame(200, $this->browser->get('/topics/code')->getStatusCode());
    }
}
