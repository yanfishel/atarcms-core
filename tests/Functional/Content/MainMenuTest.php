<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Collections;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Container;

/**
 * With 'main_menu' => ['welcome', 'stories'] in config/content.php, the theme's "main" menu is the fixture's sections
 * with the "welcome" page and the "stories" archive pinned among them.
 */
final class MainMenuTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $config = $container->service(Config::class);
        $config = new Config([...$config->all(), 'content' => [...$config->array('content'), 'main_menu' => ['welcome', 'stories']]]);
        $container->set(Config::class, $config);
        $container->set(MainMenu::class, MainMenu::fromConfig($config, $container->service(Collections::class)));
    }

    public function testThemesRenderTheSectionsWithThePinnedItems(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->term('sections', 'PHP', ['parent' => $guides]);
        $this->menuItem('main', 'Unused', ['url' => '/unused']);

        $body = (string) $this->browser->get('/menus')->getBody();

        self::assertStringContainsString(
            '<nav class="menu-main"><ul><li class="link"><a href="/welcome">Welcome</a></li><li class="link"><a href="/stories">Stories</a></li>'
            . '<li class="link"><a href="/guides">Guides</a><ul><li class="link"><a href="/guides/php">PHP</a></li></ul></li></ul></nav>',
            $body,
        );
        self::assertStringNotContainsString('Unused', $body);
    }
}
