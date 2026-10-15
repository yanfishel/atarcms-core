<?php

declare(strict_types=1);

namespace AtarCms\Media\Share;

/**
 * The font of the generated share images: Open Sans Bold (SIL Open Font License 1.1, with Cyrillic), vendored
 * byte-exact in resources/fonts/ with its licence. GD draws it through FreeType.
 */
final class ShareFont
{
    public static function path(): string
    {
        return dirname(__DIR__, 3) . '/resources/fonts/OpenSans-Bold.ttf';
    }

    /**
     * Whether GD can draw TrueType text here (it is built with FreeType).
     */
    public static function available(): bool
    {
        return function_exists('imagettftext') && function_exists('imagettfbbox') && is_file(self::path());
    }
}
