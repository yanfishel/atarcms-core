<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Content\Collections;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Media\MediaRepository;
use AtarCms\Tests\Support\Regex;

/**
 * The home page's slides (Field::slides('hero')) as the "Atar" theme's slider: the first slide without JavaScript,
 * every slide with slider.js (only on a page with more than one), a link, a button, the controls named in the page's
 * language.
 */
final class HeroSliderTest extends SkeletonTestCase
{
    public function testTheHomePageShowsItsSlidesAsASlider(): void
    {
        [$sea, $sky, $hill] = [$this->insertMedia('sea.jpg'), $this->insertMedia('sky.jpg'), $this->insertMedia('hill.jpg')];
        $this->home('en', [
            ['image' => $sea, 'title' => 'By the sea <b>', 'subtitle' => 'Blue water', 'url' => '/news', 'button' => 'Read the news'],
            ['image' => $sky, 'title' => 'Sky', 'url' => '/contacts'],
            ['image' => $hill, 'url' => '/news'],
        ]);

        $home = (string) $this->browser->get('/')->getBody();

        self::assertMatchesRegularExpression('~<section class="hero-slider" aria-roledescription="carousel" aria-label="Highlights" data-slider>~', $home);
        self::assertSame(3, substr_count($home, 'role="group" aria-roledescription="slide"'));
        self::assertMatchesRegularExpression('~<div class="hero-slide is-active" role="group" aria-roledescription="slide" aria-label="1 of 3" data-slide>~', $home, 'the first slide shows before the script runs');
        self::assertMatchesRegularExpression('~<div class="hero-slide" role="group" aria-roledescription="slide" aria-label="3 of 3" data-slide>~', $home);
        self::assertMatchesRegularExpression('~<h2 class="hero-slide-title">By the sea &lt;b&gt;</h2>\s*<p class="hero-slide-subtitle">Blue water</p>\s*<a class="button hero-slide-button" href="/news">Read the news</a>~', $home, 'a link with a button text: the button');
        self::assertMatchesRegularExpression('~<a class="hero-slide-link" href="/contacts">.*?<h2 class="hero-slide-title">Sky</h2>.*?</a>~s', $home, 'a link alone: the whole slide, named by its title');
        self::assertMatchesRegularExpression('~<a class="hero-slide-link" href="/news" aria-label="3 of 3">~', $home, 'a link with no texts still has an accessible name');
        self::assertSame(1, substr_count($home, 'fetchpriority="high"'), 'only the first picture loads at once');
        self::assertSame(1, substr_count($home, '<h1'), 'the slides\' titles are h2');
        self::assertMatchesRegularExpression('~<button type="button" class="hero-slider-arrow hero-slider-previous" aria-label="Previous slide" title="Previous slide" data-slider-previous>~', $home);
        self::assertMatchesRegularExpression('~<button type="button" class="hero-slider-arrow hero-slider-next" aria-label="Next slide" title="Next slide" data-slider-next>~', $home);
        self::assertMatchesRegularExpression('~<button type="button" class="hero-slider-dot" aria-label="Show slide 1" title="Show slide 1" aria-current="true" data-slider-dot="0"></button>~', $home);
        self::assertMatchesRegularExpression('~<button type="button" class="hero-slider-dot" aria-label="Show slide 2" title="Show slide 2" data-slider-dot="1"></button>~', $home);
        self::assertMatchesRegularExpression('~<button type="button" class="hero-slider-pause" aria-label="Pause" title="Pause" data-slider-pause data-pause-label="Pause" data-play-label="Play"><span class="hero-slider-pause-icon">\s*<svg [^>]*><use href="[^"]*icons\.svg[^"]*#pause"></use></svg>\s*</span><span class="hero-slider-play-icon">\s*<svg [^>]*><use href="[^"]*icons\.svg[^"]*#play"></use></svg>\s*</span></button>~', $home, 'Pause is an icon-only button with a tooltip');
        self::assertMatchesRegularExpression('~<script type="module" src="/theme-assets/js/slider\.js\?v=[0-9a-f]+"></script>~', $home);
        self::assertDoesNotMatchRegularExpression('/<style\b|\sstyle="/i', $home);

        self::assertStringNotContainsString('slider.js', (string) $this->browser->get('/contacts')->getBody(), 'only pages with a slider load it');
    }

    public function testTheControlsSpeakThePagesLanguage(): void
    {
        $this->home('he', [['image' => $this->insertMedia('a.jpg'), 'title' => 'ים'], ['image' => $this->insertMedia('b.jpg')]]);

        $home = (string) $this->browser->get('/he')->getBody();

        self::assertStringContainsString('<html lang="he" dir="rtl">', $home);
        self::assertStringContainsString('aria-label="השקופית הקודמת"', $home);
        self::assertStringContainsString('aria-label="1 מתוך 2"', $home);
    }

    public function testOneSlideIsAStillPictureAndNoSlidesNoSlider(): void
    {
        $this->home('en', [['image' => $this->insertMedia('sea.jpg'), 'title' => 'Alone']]);

        $home = (string) $this->browser->get('/')->getBody();

        self::assertStringContainsString('<section class="hero-slider" aria-roledescription="carousel" aria-label="Highlights">', $home, 'no data-slider');
        self::assertStringContainsString('<h2 class="hero-slide-title">Alone</h2>', $home);
        self::assertStringNotContainsString('hero-slider-controls', $home);
        self::assertStringNotContainsString('slider.js', $home);

        $this->home('ru', []);
        $ru = (string) $this->browser->get('/ru')->getBody();
        self::assertStringNotContainsString('hero-slider', $ru);
        self::assertStringContainsString('<section class="hero">', $ru, 'the site name and the lead stay');
    }

    public function testAVideoSlidePlaysItsVideoWithThePictureAsPoster(): void
    {
        $sea = $this->insertMedia('sea.jpg', alt: ['en' => 'The sea at dawn']);
        $waves = $this->insertMedia('waves.mp4', 'video/mp4');
        $sky = $this->insertMedia('sky.jpg');
        $this->home('en', [
            ['image' => $sea, 'video' => $waves, 'title' => 'Waves <b>'],
            ['image' => $sky, 'title' => 'Sky'],
        ]);

        $home = (string) $this->browser->get('/')->getBody();

        self::assertMatchesRegularExpression(
            '~<video class="hero-slide-video" muted loop playsinline preload="metadata" poster="/media/hero/2026/09/[0-9a-f]{32}\.jpg\?v=[0-9a-f]+" aria-hidden="true" data-slide-video><source src="/uploads/2026/09/[0-9a-f]{32}\.mp4" type="video/mp4"></video>\s*<span class="visually-hidden">The sea at dawn</span>~',
            $home,
            'the picture is the poster; the script plays the video',
        );
        self::assertStringNotContainsString('autoplay', $home, 'playback is the script\'s job: never the autoplay attribute');
        self::assertSame(1, substr_count($home, '<video'), 'the slide without a video keeps its picture');
        self::assertSame(1, substr_count($home, '<img'));
        self::assertStringContainsString('<h2 class="hero-slide-title">Waves &lt;b&gt;</h2>', $home);
        self::assertMatchesRegularExpression('~<section class="hero-slider" aria-roledescription="carousel" aria-label="Highlights" data-slider>~', $home);
        self::assertDoesNotMatchRegularExpression('/<style\b|\sstyle="/i', $home);
    }

    public function testOneVideoSlideLoadsTheScriptWithPauseOnly(): void
    {
        $this->home('en', [['image' => $this->insertMedia('sea.jpg'), 'video' => $this->insertMedia('waves.webm', 'video/webm'), 'title' => 'Alone']]);

        $home = (string) $this->browser->get('/')->getBody();

        self::assertStringContainsString('<section class="hero-slider" aria-roledescription="carousel" aria-label="Highlights" data-slider>', $home, 'the script plays the one video');
        self::assertMatchesRegularExpression('~<source src="/uploads/2026/09/[0-9a-f]{32}\.webm" type="video/webm">~', $home);
        self::assertStringContainsString('slider.js', $home);
        self::assertStringContainsString('data-slider-pause', $home, 'a moving video needs a Pause');
        self::assertStringNotContainsString('data-slider-next', $home, 'one slide: no arrows');
        self::assertStringNotContainsString('data-slider-dot', $home, 'and no dots');
    }

    /**
     * The first slide is the page's largest picture (its LCP): a picture loads at once at high priority, a video's
     * poster (which browsers fetch late, at low priority) is preloaded from the <head> at high priority. The other
     * slides wait.
     */
    public function testAVideoFirstSlidePreloadsItsPosterAtHighPriority(): void
    {
        $this->home('en', [
            ['image' => $this->insertMedia('sea.jpg'), 'video' => $this->insertMedia('waves.mp4', 'video/mp4'), 'title' => 'Waves'],
            ['image' => $this->insertMedia('sky.jpg'), 'video' => $this->insertMedia('clouds.mp4', 'video/mp4'), 'title' => 'Clouds'],
            ['image' => $this->insertMedia('hill.jpg', alt: ['en' => 'Hill']), 'title' => 'Hill'],
        ]);

        $home = (string) $this->browser->get('/')->getBody();

        self::assertSame(2, preg_match_all('~<video class="hero-slide-video"[^>]* poster="([^"]+)"~', $home, $posters));
        [$head] = explode('</head>', $home, 2);
        self::assertSame(1, substr_count($head, '<link rel="preload"'), 'one preload: the first slide\'s poster');
        self::assertStringContainsString('<link rel="preload" as="image" href="' . $posters[1][0] . '" fetchpriority="high">', $head);
        self::assertStringNotContainsString($posters[1][1], $head, 'the second slide\'s poster waits');
        self::assertSame(1, substr_count($home, 'fetchpriority="high"'), 'nothing else at high priority');
        self::assertMatchesRegularExpression('~<img [^>]*alt="Hill"[^>]*loading="lazy"~', $home, 'a later picture slide stays lazy');
    }

    public function testAPictureFirstSlideLoadsAtOnceAtHighPriorityWithoutAPreload(): void
    {
        $this->home('en', [
            ['image' => $this->insertMedia('sea.jpg', alt: ['en' => 'Sea']), 'title' => 'Sea'],
            ['image' => $this->insertMedia('sky.jpg'), 'video' => $this->insertMedia('clouds.mp4', 'video/mp4'), 'title' => 'Clouds'],
            ['image' => $this->insertMedia('hill.jpg', alt: ['en' => 'Hill']), 'title' => 'Hill'],
        ]);

        $home = (string) $this->browser->get('/')->getBody();

        $first = Regex::match('~<img [^>]*alt="Sea"[^>]*>~', $home);
        self::assertStringContainsString('fetchpriority="high"', $first[0]);
        self::assertStringContainsString('loading="eager"', $first[0]);
        self::assertStringNotContainsString('decoding="async"', $first[0], 'the browser decodes the LCP picture as it sees fit');
        self::assertSame(1, substr_count($home, 'fetchpriority="high"'));
        self::assertMatchesRegularExpression('~<img [^>]*alt="Hill"[^>]*loading="lazy" decoding="async"~', $home);
        self::assertStringNotContainsString('rel="preload"', $home, 'the picture is in the markup: the preload scanner finds it, with its WebP source');
    }

    /**
     * Art direction: phones get a taller crop of the same picture for their slide box (the "hero_phone" variant, 5:4
     * like the slide box at up to 40rem) instead of the wide landscape crop, most of which the box would cut off.
     */
    public function testPhonesGetATallerCropOfEachPicture(): void
    {
        $this->home('en', [
            ['image' => $this->insertMedia('sea.jpg', alt: ['en' => 'Sea']), 'title' => 'Sea'],
            ['image' => $this->insertMedia('hill.jpg', alt: ['en' => 'Hill']), 'title' => 'Hill'],
        ]);

        $home = (string) $this->browser->get('/')->getBody();

        self::assertSame(2, preg_match_all(
            '~<picture>(?:<source media="\(max-width: 40rem\)" type="image/avif" srcset="/media/hero_phone/(2026/09/[0-9a-f]{32}\.jpg)\.avif\?v=[0-9a-f]+ 800w" sizes="100vw" width="800" height="640">)?'
            . '<source media="\(max-width: 40rem\)" type="image/webp" srcset="/media/hero_phone/(2026/09/[0-9a-f]{32}\.jpg)\.webp\?v=[0-9a-f]+ 800w" sizes="100vw" width="800" height="640">'
            . '<source media="\(max-width: 40rem\)" srcset="/media/hero_phone/\2\?v=[0-9a-f]+ 800w" sizes="100vw" width="800" height="640">'
            . '(?:<source type="image/avif" [^>]*>)?<source type="image/webp" srcset="[^"]* /media/hero/\2\.webp\?v=[^>]*>'
            . '<img src="/media/hero/\2\?v=[0-9a-f]+" [^>]*width="1600" height="1000" alt="(?:Sea|Hill)"~',
            $home,
        ));
    }

    public function testNoSlidesNoPreload(): void
    {
        $this->home('en', []);

        $home = (string) $this->browser->get('/')->getBody();

        self::assertStringNotContainsString('rel="preload"', $home);
        self::assertStringNotContainsString('fetchpriority', $home);
        self::assertStringNotContainsString('rel="preload"', (string) $this->browser->get('/contacts')->getBody());
    }

    /**
     * @param list<array<string, int|string>> $slides
     */
    private function home(string $locale, array $slides): void
    {
        $container = $this->app->container();
        $container->service(EntryService::class)->create(
            $container->service(Collections::class)->get('home'),
            $locale,
            new EntryInput('', fields: ['lead' => 'Welcome', 'hero' => $slides]),
            null,
            null,
        );
    }

    /**
     * @param array<string, string> $alt
     */
    private function insertMedia(string $name, string $mime = 'image/jpeg', array $alt = []): int
    {
        $now = new \DateTimeImmutable('2026-09-28 12:00:00');
        $image = str_starts_with($mime, 'image/');

        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . '.' . (['video/mp4' => 'mp4', 'video/webm' => 'webm'][$mime] ?? 'jpg'),
            'original_name' => $name,
            'mime' => $mime,
            'size' => 100,
            'width' => $image ? 1600 : null,
            'height' => $image ? 1000 : null,
            'alt' => $alt,
            'uploaded_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
