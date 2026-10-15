<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Term;
use AtarCms\I18n\RequestLocale;
use AtarCms\View\Renderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The theme helpers of Plan 23: the direction of the page's language, languages named in themselves and the children
 * of a term in tree order.
 */
final class ThemeHelpersTest extends ContentFunctionalTestCase
{
    public function testTheThemeKnowsTheDirectionAndTheNamesOfLanguages(): void
    {
        $renderer = $this->app->container()->service(Renderer::class);

        self::assertSame(' lang="he" dir="rtl"', $renderer->callHelper('langAttrs', ['he']));
        self::assertSame(' lang="ru" dir="ltr"', $renderer->callHelper('langAttrs', ['ru']));
        self::assertSame('Русский', $renderer->callHelper('languageName', ['ru']));
    }

    public function testTermChildrenListATermsChildrenInTreeOrder(): void
    {
        $about = $this->term('sections', 'About', ['position' => 2]);
        $services = $this->term('sections', 'Services', ['position' => 1]);
        $this->term('sections', 'Team', ['parent' => $about, 'position' => 2]);
        $this->term('sections', 'History', ['parent' => $about, 'position' => 1]);
        $this->term('sections', 'Russian', ['locale' => 'ru']);
        $renderer = $this->app->container()->service(Renderer::class);
        $request = self::request('en');

        self::assertSame(['Services', 'About'], self::names($renderer->callHelper('termChildren', ['sections'], $request)));
        self::assertSame(['History', 'Team'], self::names($renderer->callHelper('termChildren', ['sections', $about], $request)));
        self::assertSame([], $renderer->callHelper('termChildren', ['sections', $services], $request));
        self::assertSame(['Russian'], self::names($renderer->callHelper('termChildren', ['sections'], self::request('ru'))));
    }

    private static function request(string $locale): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'https://localhost/')->withAttribute(RequestLocale::class, new RequestLocale($locale, $locale !== 'en'));
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $terms): array
    {
        self::assertIsArray($terms);
        self::assertContainsOnlyInstancesOf(Term::class, $terms);

        return array_values(array_map(static fn(Term $term): string => $term->name, $terms));
    }
}
