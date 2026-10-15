<?php

declare(strict_types=1);

namespace App;

use AtarCms\Kernel\Module;

/**
 * The site's own module: register services, routes, admin menu items and commands here.
 * Collection routes (config/collections.php) are registered after it, so routes added here win.
 */
final class SiteModule extends Module {}
