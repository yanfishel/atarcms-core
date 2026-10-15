<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Http\CanonicalUrl;
use AtarCms\I18n\RequestLocale;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\MediaRepository;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\View\Renderer;
use Nyholm\Psr7\Factory\Psr17Factory;

final class SiteSeoDefaultsTest extends ContentFunctionalTestCase
{
    public function testPagesWithoutTheirOwnDescriptionAndImageUseTheSiteDefaults(): void
    {
        $site = $this->image(str_repeat('a', 32));
        $own = $this->image(str_repeat('b', 32));
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput('Fishing club', 'We fish & "sail".', $site), null, null);
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Own', ['slug' => 'own', 'metaDescription' => 'Our own words', 'fields' => ['cover' => $own]]);
        $large = '?v=' . ($this->app->container()->service(ImageVariants::class)->get('large')?->version() ?? self::fail('No large variant'));

        $hello = (string) $this->browser->get('/articles/hello')->getBody();
        self::assertStringContainsString('<meta name="description" content="We fish &amp; &quot;sail&quot;.">', $hello);
        self::assertStringContainsString('<meta property="og:title" content="Hello">', $hello);
        self::assertStringContainsString('<meta property="og:description" content="We fish &amp; &quot;sail&quot;.">', $hello);
        self::assertStringContainsString('<meta property="og:image" content="https://localhost/media/large/2026/09/' . str_repeat('a', 32) . '.jpg' . $large . '">', $hello);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $hello);

        $page = (string) $this->browser->get('/articles/own')->getBody();
        self::assertStringContainsString('<meta name="description" content="Our own words">', $page);
        self::assertStringContainsString('<meta property="og:description" content="Our own words">', $page);
        self::assertStringContainsString('<meta property="og:image" content="https://localhost/media/large/2026/09/' . str_repeat('b', 32) . '.jpg' . $large . '">', $page, 'the main photo');

        $russian = (string) $this->browser->get('/ru/articles')->getBody();
        self::assertStringNotContainsString('name="description"', $russian, 'the texts are per language');
        self::assertStringNotContainsString('og:description', $russian);
        self::assertStringContainsString('/media/large/2026/09/' . str_repeat('a', 32) . '.jpg', $russian, 'the sharing image is site-wide');
    }

    public function testThePageTitleFallsBackToTheSiteTitleOfTheLanguage(): void
    {
        $this->app->container()->service(SettingsService::class)->save('ru', new SettingsInput('Клуб'), null, null);
        $request = (new Psr17Factory())->createServerRequest('GET', '/x')->withAttribute(CanonicalUrl::class, new CanonicalUrl('https://localhost/x'));
        $renderer = $this->app->container()->service(Renderer::class);

        $russian = $renderer->callHelper('socialMeta', [null], $request->withAttribute(RequestLocale::class, new RequestLocale('ru', true)));
        $english = $renderer->callHelper('socialMeta', [null], $request->withAttribute(RequestLocale::class, new RequestLocale('en', false)));

        self::assertIsString($russian);
        self::assertIsString($english);
        self::assertStringContainsString('<meta property="og:title" content="Клуб">', $russian);
        self::assertStringContainsString('<meta property="og:title" content="Fixture">', $english);
    }

    private function image(string $name): int
    {
        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . $name . '.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1234,
            'width' => 1200,
            'height' => 800,
            'alt' => [],
            'uploaded_by' => null,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
    }
}
