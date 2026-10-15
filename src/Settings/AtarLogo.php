<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\View\AssetUrl;

/**
 * The AtarCMS logo files of an accent preset: the site's default icon while the settings choose none, and the admin
 * brand. They are admin assets (resources/admin/assets/logo/, served under app.admin_assets_url with a version
 * hash), so no route or server rendering is involved.
 */
final class AtarLogo
{
    /** The PNG sizes: the browser tab (32), the apple-touch-icon (180, full-bleed) and the manifest icons. */
    public const array SIZES = [32, 180, 192, 512];

    public function __construct(private readonly AssetUrl $assets) {}

    public function svg(AdminAccent $accent): string
    {
        return $this->assets->url('logo/atar-' . $accent->value . '.svg');
    }

    /**
     * @param 32|180|192|512 $size one of SIZES
     */
    public function png(AdminAccent $accent, int $size): string
    {
        return $this->assets->url('logo/atar-' . $accent->value . '-' . $size . '.png');
    }

    /**
     * The local path of a logo PNG (the brand of the generated share images).
     *
     * @param 32|180|192|512 $size one of SIZES
     */
    public static function file(AdminAccent $accent, int $size): string
    {
        return dirname(__DIR__, 2) . '/resources/admin/assets/logo/atar-' . $accent->value . '-' . $size . '.png';
    }
}
