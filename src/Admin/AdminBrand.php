<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Settings\AdminAccent;

/**
 * The admin brand of a request (the adminBrand view helper): the name beside the mark, the admin colour that
 * <html data-accent> applies, and the mark itself: the site icon when the settings choose one, else the AtarCMS logo
 * in the admin colour.
 */
final readonly class AdminBrand
{
    /**
     * @param string $markUrl the site icon's 180 px variant, or the logo SVG of $accent
     * @param bool $siteIcon whether $markUrl is the site icon
     */
    public function __construct(
        public string $name,
        public AdminAccent $accent,
        public string $markUrl,
        public bool $siteIcon,
    ) {}
}
