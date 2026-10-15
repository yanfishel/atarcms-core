<?php

declare(strict_types=1);

namespace AtarCms\Media\Share;

/**
 * The layout of the generated share images, chosen per theme in theme.php ('share_image' => ['layout' => 'band']):
 * the title on the darkened photo above a charcoal band with the brand, the title at the bottom with an accent bar
 * above the brand, or a slanted panel of the accent colour beside the photo.
 */
enum ShareLayout: string
{
    case Band = 'band';
    case Bottom = 'bottom';
    case Panel = 'panel';

    public const self DEFAULT = self::Band;
}
