<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Alternates;
use AtarCms\I18n\Locales;
use AtarCms\I18n\LocalizedUrls;
use AtarCms\I18n\RequireDefaultLocale;
use AtarCms\Kernel\Config;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use stdClass;

final class LocalizedUrlsTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', [stdClass::class, 'home'], 'home');
        $this->router->get('/news/{slug}', [stdClass::class, 'news'], 'news.show');
        $this->router->get('/admin/x', [stdClass::class, 'admin'], 'admin.x')->middleware(RequireDefaultLocale::class);
        $this->router->get('/unnamed', [stdClass::class, 'unnamed']);
    }

    public function testPrefixesNonDefaultLocalesOnLocalizableRoutes(): void
    {
        $urls = $this->urls(['en', 'ru']);

        self::assertSame('/', $urls->path('home', [], 'en'));
        self::assertSame('/ru', $urls->path('home', [], 'ru'));
        self::assertSame('/ru?page=2', $urls->path('home', ['page' => 2], 'ru'));
        self::assertSame('/news/%D0%BF%D1%80%D0%B8', $urls->path('news.show', ['slug' => 'при'], 'en'));
        self::assertSame('/ru/news/%D0%BF%D1%80%D0%B8', $urls->path('news.show', ['slug' => 'при'], 'ru'));
        self::assertSame('/admin/x', $urls->path('admin.x', [], 'ru'));
    }

    public function testAlternatesForTheCurrentRoute(): void
    {
        $news = $this->router->match('GET', '/news/%D0%BF%D1%80%D0%B8');
        $request = (new Psr17Factory())->createServerRequest('GET', '/ru/news/x')->withAttribute(RouteMatch::class, $news);

        self::assertSame([
            'en' => 'https://example.test/news/%D0%BF%D1%80%D0%B8',
            'ru' => 'https://example.test/ru/news/%D0%BF%D1%80%D0%B8',
        ], $this->urls(['en', 'ru'])->alternates($request));
    }

    public function testNoAlternatesWhereThereIsNothingToAlternate(): void
    {
        $factory = new Psr17Factory();
        $withMatch = fn(string $path) => $factory->createServerRequest('GET', $path)
            ->withAttribute(RouteMatch::class, $this->router->match('GET', $path));
        $urls = $this->urls(['en', 'ru']);

        self::assertSame([], $urls->alternates($factory->createServerRequest('GET', '/missing')));
        self::assertSame([], $urls->alternates($withMatch('/admin/x')));
        self::assertSame([], $urls->alternates($withMatch('/unnamed')));
        self::assertSame([], $this->urls(['en'])->alternates($withMatch('/')));
    }

    public function testAnAlternatesAttributeReplacesRouteAlternates(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/news/x')
            ->withAttribute(RouteMatch::class, $this->router->match('GET', '/news/x'))
            ->withAttribute(Alternates::class, new Alternates(['ru' => '/ru/novosti/privet', 'de' => '/de/x', 'en' => '/news/hello']));

        self::assertSame(
            ['en' => 'https://example.test/news/hello', 'ru' => 'https://example.test/ru/novosti/privet'],
            $this->urls(['en', 'ru'])->alternates($request),
        );
        self::assertSame([], $this->urls(['en', 'ru'])->alternates($request->withAttribute(Alternates::class, new Alternates(['en' => '/news/hello']))));
        self::assertSame([], $this->urls(['en', 'ru'])->alternates($request->withAttribute(Alternates::class, new Alternates([]))));
    }

    public function testHreflangsAddXDefaultOnlyWhenTheDefaultLocaleIsPresent(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/ru/a')
            ->withAttribute(Alternates::class, new Alternates(['ru' => '/ru/a', 'de' => '/de/a']));
        $urls = $this->urls(['en', 'ru', 'de']);

        self::assertSame(['ru' => 'https://example.test/ru/a', 'de' => 'https://example.test/de/a'], $urls->hreflangs($request));
        self::assertSame(
            ['en' => 'https://example.test/a', 'ru' => 'https://example.test/ru/a', 'x-default' => 'https://example.test/a'],
            $urls->hreflangs($request->withAttribute(Alternates::class, new Alternates(['en' => '/a', 'ru' => '/ru/a']))),
        );
    }

    /**
     * @param list<string> $locales
     */
    private function urls(array $locales): LocalizedUrls
    {
        return new LocalizedUrls($this->router, new Locales('en', $locales), new Config(['app' => ['url' => 'https://example.test/']]));
    }
}
