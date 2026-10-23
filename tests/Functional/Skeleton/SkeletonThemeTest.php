<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Content\Preset\PresetPages;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Support\Cast;

/**
 * The default theme on the demo content, in every language: each page renders with its language and direction, its
 * landmarks, its side column and no inline style or script.
 */
final class SkeletonThemeTest extends SkeletonTestCase
{
    public function testEveryPageOfTheDemoSiteRendersInEveryLanguage(): void
    {
        $this->seedDemo();
        $article = Cast::string($this->db->scalar("SELECT e.slug FROM entries e WHERE e.collection = 'articles' AND e.locale = 'he' AND JSON_EXTRACT(e.data, '$.summary') IS NOT NULL ORDER BY e.id LIMIT 1"));
        $news = Cast::string($this->db->scalar("SELECT slug FROM entries WHERE collection = 'news' AND locale = 'en' ORDER BY published_at DESC LIMIT 1"));
        $tag = Cast::string($this->db->scalar("SELECT slug FROM terms WHERE taxonomy = 'tags' AND locale = 'ru' ORDER BY id LIMIT 1"));

        foreach (['' => ['en', 'ltr'], '/ru' => ['ru', 'ltr'], '/he' => ['he', 'rtl']] as $prefix => [$locale, $dir]) {
            $pages = [
                $prefix === '' ? '/' : $prefix,
                $prefix . '/services/consulting',
                $prefix . '/news',
                $prefix . '/about',
                $prefix . '/contacts',
                $prefix . '/accessibility',
                $prefix . '/privacy',
                $prefix . '/search?q=lorem',
            ];
            foreach ($pages as $path) {
                $response = $this->browser->get($path);
                $body = (string) $response->getBody();
                self::assertSame(200, $response->getStatusCode(), $path);
                self::assertStringContainsString('<html lang="' . $locale . '" dir="' . $dir . '">', $body, $path);
                self::assertSame(1, substr_count($body, '<h1'), $path . ': one heading of the page');
                self::assertStringContainsString('<a class="skip-link" href="#main">', $body, $path);
                self::assertStringContainsString('<main id="main"', $body, $path);
                self::assertStringContainsString('<nav class="site-nav" aria-label="', $body, $path);
                self::assertStringContainsString('<details class="phone-nav">', $body, $path);
                self::assertStringContainsString('<footer class="site-footer">', $body, $path);
                self::assertDoesNotMatchRegularExpression('/<style\b|\sstyle="/i', $body, $path . ': no inline styles');
                self::assertSame(1, preg_match_all('~<script(?! type="module" src="/theme-assets/js/| type="application/ld\+json">)~', $body), $path . ': one inline script, the bootstrap (a JSON-LD data block is no script)');
                self::assertMatchesRegularExpression('~<script nonce="[A-Za-z0-9+/=_-]+">try\{~', $body, $path);
                self::assertMatchesRegularExpression('~<script type="module" src="/theme-assets/js/tooltip\.js\?v=[0-9a-f]+"></script>~', $body, $path . ': titles show as tooltips');
                self::assertMatchesRegularExpression('~<details class="dropdown theme-menu">~', $body, $path);
                self::assertDoesNotMatchRegularExpression('/<img(?![^>]*\salt=)[^>]*>/', $body, $path . ': every image has an alt');
                // The accessibility toolbar (on by default) and the preset pages: the statement linked from its panel, both
                // from the footer.
                self::assertMatchesRegularExpression('~<script type="module" src="/theme-assets/js/a11y\.js\?v=[0-9a-f]+"></script>~', $body, $path);
                self::assertStringContainsString("localStorage.getItem('atar-a11y')", $body, $path . ': stored choices apply before the first paint');
                self::assertMatchesRegularExpression('~<button type="button" class="a11y-trigger" aria-expanded="false" aria-controls="a11y-panel" aria-label="[^"]+" title="[^"]+" data-a11y-trigger>~', $body, $path);
                self::assertMatchesRegularExpression('~<section class="a11y-panel" id="a11y-panel" role="dialog" aria-labelledby="a11y-title" tabindex="-1" hidden data-a11y-panel>~', $body, $path);
                self::assertSame(11, substr_count($body, ' aria-pressed="false" data-a11y-option="'), $path . ': every option a toggle button');
                self::assertStringContainsString('<a class="a11y-statement" href="' . $prefix . '/accessibility">', $body, $path . ': the panel links the statement');
                self::assertMatchesRegularExpression('~<ul class="footer-legal">\s*<li><a href="' . $prefix . '/privacy">[^<]+</a></li>\s*<li><a href="' . $prefix . '/accessibility">[^<]+</a></li>\s*</ul>~', $body, $path . ': the footer links both preset pages');
            }
        }

        $home = (string) $this->browser->get('/he')->getBody();
        self::assertStringContainsString('<h1>אתר הדגמה</h1>', $home);
        self::assertStringContainsString('<section class="hero">', $home);
        self::assertStringContainsString('<section class="hero-slider" aria-roledescription="carousel" aria-label="מומלצים" data-slider>', $home, 'the demo\'s slides');
        self::assertSame(3, substr_count($home, 'aria-roledescription="slide"'));
        self::assertMatchesRegularExpression('~<script type="module" src="/theme-assets/js/slider\.js\?v=[0-9a-f]+"></script>~', $home);
        self::assertMatchesRegularExpression('~<section class="block container" aria-labelledby="section-\d+">\s*<header class="block-header">\s*<h2 id="section-\d+">שירותים</h2>~', $home);
        self::assertStringContainsString('<ul class="languages"', $home);
        // The names carry their language; the items follow the page, so they line up the same way.
        self::assertStringContainsString(' hreflang="ru"><span lang="ru" dir="ltr">Русский</span></a>', $home);
        self::assertStringContainsString('aria-current="true"><span lang="he" dir="rtl">עברית</span></a>', $home, 'the phone menu\'s list');
        // The button shows the current language's flag (a language without one: its short name), the items flag + name.
        self::assertMatchesRegularExpression('~<details class="dropdown language-menu">\s*<summary aria-label="שפות: עברית"><img class="flag" src="/theme-assets/flags/he\.png\?v=[0-9a-f]{12}" alt="" width="24" height="24"><svg~', $home, 'the button shows the current language\'s flag');
        self::assertMatchesRegularExpression('~aria-current="true"><img class="flag" src="/theme-assets/flags/he\.png\?v=[0-9a-f]{12}" alt="" width="24" height="24"><span lang="he" dir="rtl">עברית</span><svg class="icon"[^>]*><use href="[^"]+#check"></use></svg>\s*</a>~', $home, 'the dropdown marks the current language');
        self::assertMatchesRegularExpression('~hreflang="ru"><img class="flag" src="/theme-assets/flags/ru\.png\?v=[0-9a-f]{12}" alt="" width="24" height="24"><span lang="ru" dir="ltr">Русский</span></a>~', $home);
        self::assertMatchesRegularExpression('~<summary aria-label="Languages: English"><img class="flag" src="/theme-assets/flags/en\.png~', (string) $this->browser->get('/')->getBody());
        self::assertLessThan(strpos($home, 'class="dropdown language-menu"'), strpos($home, 'class="search-form"'), 'search, then the languages');
        self::assertLessThan(strpos($home, 'class="dropdown theme-menu"'), strpos($home, 'class="dropdown language-menu"'), 'then the theme, at the edge');
        self::assertStringNotContainsString('js/gallery.js', $home, 'the lightbox only where there is a gallery');
        self::assertStringContainsString('© ', $home);

        $section = (string) $this->browser->get('/services/consulting')->getBody();
        self::assertMatchesRegularExpression('~<nav class="breadcrumbs" aria-label="You are here">\s*<ol>\s*<li><a href="/">Home</a></li>\s*<li><a href="https://localhost/services">Services</a></li>\s*<li><span aria-current="page">Consulting</span></li>~', $section);
        self::assertMatchesRegularExpression('~<a href="/services/consulting" aria-current="page">Consulting</a>~', $section, 'the side column marks the current subsection');
        self::assertStringContainsString('<article class="list-item">', $section, 'an announced article');
        self::assertStringContainsString('<article class="article-inline">', $section, 'an article shown in full');
        self::assertMatchesRegularExpression('~<li class="has-children is-ancestor">\s*<a href="/services[^"]*">Services<svg class="icon"~', $section);

        $articleUrl = Cast::string($this->db->scalar("SELECT t.slug FROM terms t JOIN entry_terms et ON et.term_id = t.id JOIN entries e ON e.id = et.entry_id WHERE e.slug = ? AND e.locale = 'he' AND t.taxonomy = 'sections'", [$article]));
        $page = (string) $this->browser->get('/he/' . $this->trail($articleUrl) . '/' . $article)->getBody();
        self::assertStringContainsString('<h2 id="aside-more">עוד במדור הזה</h2>', $page);
        self::assertStringContainsString('<section class="gallery-block" aria-label="תמונות" data-lightbox-labels data-close="סגור"', $page);
        self::assertMatchesRegularExpression('~<a href="/media/large/[^"]+" data-pswp-width="1200" data-pswp-height="800"><span class="frame">~', $page);
        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="/theme-assets/vendor/photoswipe-5\.4\.4\.css\?v=[0-9a-f]+">\s*<script type="module" src="/theme-assets/js/gallery\.js\?v=~', $page);
        self::assertStringContainsString('fetchpriority="high"', $page);

        $newsPage = (string) $this->browser->get('/news/' . $news)->getBody();
        self::assertStringContainsString('<section class="related" aria-labelledby="related-title">', $newsPage);
        self::assertStringContainsString('<div class="entry-tags">', $newsPage);
        self::assertStringContainsString('<h2 id="aside-tags">Tags</h2>', $newsPage);

        $contacts = (string) $this->browser->get('/he/contacts')->getBody();
        self::assertStringContainsString('<iframe class="map-frame" src="https://www.google.com/maps?q=32.085300%2C34.781800&amp;z=15&amp;hl=he&amp;output=embed"', $contacts);
        self::assertMatchesRegularExpression('~<a class="map-waze" href="https://waze\.com/ul\?ll=32\.085300%2C34\.781800&amp;navigate=yes" rel="noopener" target="_blank"><svg class="icon icon-brand"[^>]*><use href="[^"]+brands\.svg[^"]*#waze"></use></svg>\s*ניווט ב-Waze</a>~', $contacts, 'the Waze link looks like the contact links: their icon, weight and colour');
        self::assertMatchesRegularExpression('~<a href="tel:\+15550100" aria-label="טלפון: \+1 555 0100"><svg class="icon"[^>]*><use href="[^"]+icons\.svg[^"]*#phone"></use></svg>\s*<span dir="ltr">\+1 555 0100</span></a>~', $contacts);
        self::assertMatchesRegularExpression('~<dt><svg class="icon"[^>]*>.*?</svg>\s*כתובת</dt>~s', $contacts, 'the address keeps its label');

        // The footer: contacts as icon links without labels, messengers by their values, social networks as round buttons.
        $footer = substr($home, (int) strpos($home, '<footer'));
        self::assertMatchesRegularExpression('~<a href="mailto:hello@example\.com" aria-label="אימייל: hello@example\.com">~', $footer);
        self::assertMatchesRegularExpression('~<a href="https://wa\.me/15550100" aria-label="WhatsApp: \+15550100" rel="noopener"><svg class="icon icon-brand"[^>]*><use href="[^"]+brands\.svg[^"]*#whatsapp"></use></svg>\s*<span dir="ltr">\+15550100</span></a>~', $footer);
        self::assertMatchesRegularExpression('~<a href="https://t\.me/atar_demo" aria-label="Telegram: @atar_demo" rel="noopener">.*?<span dir="ltr">@atar_demo</span></a>~s', $footer);
        self::assertStringNotContainsString('<dt>', $footer, 'no labels for phone and email');
        self::assertMatchesRegularExpression('~<a class="social-button" href="https://www\.youtube\.com/@example" rel="me noopener" aria-label="YouTube" title="YouTube"><svg class="icon icon-brand"[^>]*><use href="[^"]+#youtube"></use></svg>~', $footer);
        self::assertSame(3, substr_count($footer, 'class="social-button"'));
        self::assertStringContainsString('<form class="form" method="post" action="/he/contacts#contact" novalidate>', $contacts);

        self::assertSame(200, $this->browser->get('/ru/tag/' . $tag)->getStatusCode());
        $missing = $this->browser->get('/he/nothing-here');
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString('<p class="error-code" aria-hidden="true">404</p>', (string) $missing->getBody());
        self::assertStringContainsString('dir="rtl"', (string) $missing->getBody());
        self::assertStringContainsString('data-a11y-trigger', (string) $missing->getBody(), 'the toolbar on error pages too');
        $statementPage = (string) $this->browser->get('/he/accessibility')->getBody();
        self::assertStringContainsString('<h1>הצהרת נגישות</h1>', $statementPage);
        self::assertStringContainsString('ת״י 5568', $statementPage);
        self::assertStringContainsString('<li><a href="/ru/privacy">Политика конфиденциальности</a></li>', (string) $this->browser->get('/ru')->getBody());
        $privacyPage = (string) $this->browser->get('/privacy')->getBody();
        self::assertStringContainsString('<h1>Privacy policy</h1>', $privacyPage);
        self::assertStringContainsString('Google Maps', $privacyPage);
    }

    /**
     * Site settings → General switches the toolbar: off, no page has its button, panel or script, and the bootstrap
     * no longer applies stored choices (nothing would undo them); the footer still links the statement when it exists.
     */
    public function testTheAccessibilityToolbarFollowsItsSwitch(): void
    {
        $page = (string) $this->browser->get('/')->getBody();
        self::assertStringContainsString('data-a11y-trigger', $page, 'on without a setting');
        self::assertStringNotContainsString('a11y-statement', $page, 'no statement yet: no link');
        self::assertStringNotContainsString('footer-legal', $page, 'no preset pages yet (bin/atar migrate makes them): no links');
        self::assertStringContainsString('<div class="a11y" data-a11y data-a11y-position="bottom-end">', $page, 'the bottom inline-end corner by default');

        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(accessibilityToolbarPosition: 'top-start'), null, null);
        foreach (['/', '/he'] as $path) {
            self::assertStringContainsString('<div class="a11y" data-a11y data-a11y-position="top-start">', (string) $this->browser->get($path)->getBody(), $path . ': a logical corner, mirrored by the page direction');
        }

        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(accessibilityToolbar: false), null, null);

        foreach (['/', '/he', '/he/nothing-here'] as $path) {
            $body = (string) $this->browser->get($path)->getBody();
            self::assertStringNotContainsString('data-a11y', $body, $path);
            self::assertStringNotContainsString('a11y.js', $body, $path);
            self::assertStringNotContainsString('atar-a11y', $body, $path);
            self::assertMatchesRegularExpression('~<script nonce="[A-Za-z0-9+/=_-]+">try\{~', $body, $path . ': the theme bootstrap stays');
        }

        $this->app->container()->service(PresetPages::class)->sync();
        $body = (string) $this->browser->get('/he')->getBody();
        self::assertStringNotContainsString('a11y-statement', $body);
        self::assertStringContainsString('<li><a href="/he/accessibility">הצהרת נגישות</a></li>', $body, 'without the toolbar the footer still links the statement');
    }

    private function trail(string $slug): string
    {
        $parent = $this->db->scalar("SELECT p.slug FROM terms t JOIN terms p ON p.id = t.parent_id WHERE t.slug = ? AND t.locale = 'he'", [$slug]);

        return is_string($parent) ? $parent . '/' . $slug : $slug;
    }
}
