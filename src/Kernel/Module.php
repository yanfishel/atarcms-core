<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use AtarCms\Admin\AdminMenu;
use AtarCms\Cli\Command;
use AtarCms\Http\Routing\Router;

/**
 * Extension point. Subclasses override only the hooks they need;
 * new hooks are always added here as no-ops so existing modules keep working.
 */
abstract class Module
{
    public function services(Container $container): void {}

    public function routes(Router $router): void {}

    public function adminMenu(AdminMenu $menu): void {}

    /**
     * @return list<string> directories containing migration files
     */
    public function migrationPaths(): array
    {
        return [];
    }

    /**
     * @return list<string> directories with admin templates, e.g. field widgets "fields/{type}.php";
     *                      the core admin templates take precedence
     */
    public function adminTemplatePaths(): array
    {
        return [];
    }

    /**
     * @return list<class-string<Command>>
     */
    public function commands(): array
    {
        return [];
    }
}
