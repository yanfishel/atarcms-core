<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Taxonomies;
use AtarCms\Kernel\Config;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton site is built on sections: articles live in them, pages stand alone at the root, and the default
 * theme speaks every label of the configuration in both languages.
 */
final class SkeletonSectionsTest extends TestCase
{
    private const string SKELETON = __DIR__ . '/../../../skeleton';

    public function testTheSkeletonIsBuiltOnSections(): void
    {
        $collections = self::collections();
        $sections = $collections->taxonomies->get('sections');

        self::assertSame(['home', 'contacts', 'news', 'articles', 'pages'], array_keys($collections->all()));
        self::assertSame(['privacy' => 'site.presets.privacy', 'accessibility' => 'site.presets.accessibility'], $collections->get('pages')->presets, 'the privacy policy and the accessibility statement');
        self::assertSame(['sections', 'tags'], array_keys($collections->taxonomies->all()));
        self::assertTrue($sections->isRootTree());
        self::assertTrue($sections->redirectToFirstChild);
        self::assertSame('sections', $collections->get('articles')->routeTaxonomy);
        self::assertSame('/{slug}', $collections->get('pages')->route);
        self::assertFalse($collections->get('pages')->hierarchical);
        self::assertSame(['tags'], $collections->get('news')->termTaxonomies());
        self::assertSame('/contacts', $collections->get('contacts')->route);
        self::assertTrue($collections->get('contacts')->single);
        self::assertSame(['lead', 'hero', 'intro'], array_keys($collections->get('home')->fields));
        self::assertSame(['body'], array_keys($collections->get('contacts')->fields), 'the address is a site setting');
        foreach (['home' => 'intro', 'contacts' => 'body'] as $name => $text) {
            self::assertTrue($collections->get($name)->plain, $name);
            self::assertTrue($collections->get($name)->fields[$text]->tall, $name);
        }
        self::assertArrayNotHasKey('event_date', $collections->get('news')->fields);
        self::assertSame('site.fields.announce_help', $collections->get('articles')->fields['summary']->help);
    }

    public function testTheMainMenuIsTheSectionsWithHomeNewsAndContacts(): void
    {
        $content = require self::SKELETON . '/config/content.php';
        self::assertIsArray($content);
        $theme = require self::SKELETON . '/themes/default/theme.php';
        self::assertIsArray($theme);

        $menu = MainMenu::fromConfig(new Config(['content' => $content]), self::collections());

        self::assertSame(['home', 'news', 'contacts'], array_map(static fn(Collection $collection): string => $collection->name, $menu->pinned ?? []));
        self::assertSame('sections', $menu->sections?->name);
        self::assertSame(['main' => 'site.menus.main', 'footer' => 'site.menus.footer'], $theme['menus'], 'the footer menu is edited on the Menus screen');
        self::assertSame('contacts', $content['contact_form'] ?? null);
        self::assertSame([], glob(self::SKELETON . '/database/migrations/*.php'), 'no indexed field, no sync migration');
    }

    /**
     * The contacts page shows the site's Google map (added on its admin form; its host is always allowed in
     * frames, so the skeleton lists no extra frame source), the contact details and the contact form; editors manage
     * the messages.
     */
    public function testTheContactsPageShowsTheMapTheDetailsAndTheForm(): void
    {
        $template = (string) file_get_contents(self::SKELETON . '/themes/default/collections/contacts/show.php');
        $map = (string) file_get_contents(self::SKELETON . '/themes/default/partials/map.php');
        $security = require self::SKELETON . '/config/security.php';
        $roles = require self::SKELETON . '/config/roles.php';
        self::assertIsArray($security);
        self::assertIsArray($roles);

        self::assertStringContainsString('$map = $this->contactMap();', $template);
        self::assertStringContainsString('$form = $this->contactForm();', $template);
        self::assertStringContainsString("\$this->partial('partials/contact-details', ['settings' => \$settings])", $template);
        self::assertStringContainsString('<iframe class="map-frame" src="<?= $this->e($map->embedUrl) ?>"', $map);
        self::assertStringContainsString(' sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox" allowfullscreen></iframe>', $map, 'the map may run its own script and open Google Maps, nothing more');
        self::assertIsArray($security['csp']);
        self::assertSame([], $security['csp']['frame_src'] ?? null);
        self::assertIsArray($roles['editor']);
        self::assertIsArray($roles['editor']['permissions']);
        self::assertContains('messages.manage', $roles['editor']['permissions']);
        self::assertIsArray($roles['author']);
        self::assertIsArray($roles['author']['permissions']);
        self::assertNotContains('messages.manage', $roles['author']['permissions']);
    }

    /**
     * Entry and section pages print the texts the controllers resolve along the sections ($seo, PageSeo), never the
     * entry's own texts alone (an article would lose its section's).
     */
    public function testThePagesPrintTheResolvedTitleAndDescription(): void
    {
        $templates = [
            'collections/articles/show.php' => "'title' => \$seo->title,\n    'description' => \$seo->description,",
            'collections/pages/show.php' => "'title' => \$seo->title,\n    'description' => \$seo->description,",
            'collections/news/show.php' => "'title' => \$seo->title,\n    'description' => \$seo->description,",
            'show.php' => "['title' => \$seo?->title ?? \$heading, 'description' => \$seo?->description]",
            'taxonomies/sections/show.php' => "'title' => \$seo->title,\n    'description' => \$seo->description,",
        ];
        foreach ($templates as $file => $layout) {
            $template = (string) file_get_contents(self::SKELETON . '/themes/default/' . $file);
            self::assertStringContainsString($layout, $template, $file);
            self::assertStringNotContainsString('seo->metaTitle', $template, $file);
        }
    }

    public function testTheThemeTranslatesEveryLabelOfTheConfiguration(): void
    {
        $collections = self::collections();
        $labels = [];
        foreach ($collections->all() as $collection) {
            $labels[] = $collection->label;
            foreach ($collection->fields as $field) {
                $labels[] = $field->label;
            }
            array_push($labels, ...array_values($collection->presets));
        }
        foreach ($collections->taxonomies->all() as $taxonomy) {
            $labels[] = $taxonomy->label;
        }

        foreach (['en', 'ru', 'he', 'de', 'fr'] as $locale) {
            $catalog = self::catalog($locale);
            foreach ($labels as $label) {
                self::assertArrayHasKey($label, $catalog, $locale . ': ' . $label);
            }
        }
        self::assertSame(array_keys(self::catalog('en')), array_keys(self::catalog('ru')), 'the theme catalogs have the same keys');
    }

    private static function collections(): Collections
    {
        $collections = require self::SKELETON . '/config/collections.php';
        $taxonomies = require self::SKELETON . '/config/taxonomies.php';
        self::assertIsArray($collections);
        self::assertIsArray($taxonomies);

        return new Collections($collections, new Taxonomies($taxonomies));
    }

    /**
     * @return array<string, string> dotted key => message
     */
    private static function catalog(string $locale): array
    {
        $flat = [];
        $walk = static function (array $messages, string $prefix) use (&$walk, &$flat): void {
            foreach ($messages as $key => $message) {
                if (is_array($message)) {
                    $walk($message, $prefix . $key . '.');
                } elseif (is_string($message)) {
                    $flat[$prefix . $key] = $message;
                }
            }
        };
        $catalog = require self::SKELETON . '/themes/default/lang/' . $locale . '.php';
        self::assertIsArray($catalog);
        $walk($catalog, '');

        return $flat;
    }
}
