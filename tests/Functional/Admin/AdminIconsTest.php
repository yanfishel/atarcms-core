<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Admin\AdminIcon;
use AtarCms\Admin\Content\FieldIcons;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * Every icon the admin renders is a symbol of the sprite, including the names templates and PHP choose at run time
 * (menu items, field types, sort arrows, tree moves, the theme menu), which AdminStylesTest's literal scan cannot see.
 */
final class AdminIconsTest extends ContentFunctionalTestCase
{
    private const string SPRITE = __DIR__ . '/../../../resources/admin/assets/icons.svg';

    public function testEveryRenderedIconIsInTheSprite(): void
    {
        $symbols = AdminIcon::symbols(self::SPRITE);
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $code = $this->term('topics', 'Code');
        $this->term('topics', 'PHP', ['parent' => $code]);
        $this->menuItem('main', 'Home', ['url' => '/']);
        $this->actingAs($this->createUser('ann@example.com', 'admin'));

        $paths = [
            '/admin', '/admin/content/articles', '/admin/content/articles?sort=title&dir=desc', '/admin/content/articles?q=nothing',
            '/admin/content/articles/new', '/admin/content/articles/' . $hello->id, '/admin/content/articles/' . $hello->id . '/delete',
            '/admin/content/pages/order', '/admin/taxonomies/topics', '/admin/taxonomies/topics/order', '/admin/taxonomies/tags',
            '/admin/menus/main', '/admin/media', '/admin/media/picker?kind=image', '/admin/users', '/admin/audit', '/admin/nope',
        ];
        $seen = [];
        foreach ($paths as $path) {
            $body = (string) $this->browser->get($path)->getBody();
            preg_match_all('~<use href="/admin-assets/icons\.svg\?v=[0-9a-f]{12}#([^"]+)"~', $body, $matches);
            self::assertNotEmpty($matches[1], $path);
            foreach ($matches[1] as $name) {
                self::assertContains($name, $symbols, sprintf('%s renders the icon "%s"', $path, $name));
                $seen[$name] = true;
            }
        }
        foreach (['chevron-down', 'search', 'arrow-up', 'arrow-left', 'sun', 'moon', 'monitor', 'grip-vertical', 'folder-tree', 'tag'] as $dynamic) {
            self::assertArrayHasKey($dynamic, $seen, 'the pages cover the run-time name ' . $dynamic);
        }
    }

    public function testEveryFieldTypeIconIsInTheSprite(): void
    {
        $symbols = AdminIcon::symbols(self::SPRITE);

        foreach ([...array_values(FieldIcons::ICONS), FieldIcons::DEFAULT] as $icon) {
            self::assertContains($icon, $symbols, $icon);
        }
    }
}
