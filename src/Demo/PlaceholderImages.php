<?php

declare(strict_types=1);

namespace AtarCms\Demo;

use AtarCms\Media\Share\ShareFont;
use RuntimeException;

/**
 * Placeholder pictures for the demo content, drawn with GD: a flat neutral colour, a thin frame and the label
 * "<purpose> · <width>×<height>" centred in white Open Sans (the font of the share images), as JPEG.
 */
final class PlaceholderImages
{
    /** Background colours, in order: muted, distinct and dark enough for white text. */
    public const array COLOURS = [
        [0x4A, 0x5C, 0x6A], [0x5E, 0x76, 0x5C], [0x8A, 0x6E, 0x4B], [0x6B, 0x5B, 0x7A], [0x3F, 0x6B, 0x72],
        [0x7A, 0x56, 0x56], [0x55, 0x60, 0x48], [0x4E, 0x58, 0x7A], [0x6F, 0x66, 0x5A], [0x3D, 0x5A, 0x50],
    ];

    /**
     * @param int $colour an index of COLOURS (wraps around)
     *
     * @return string the JPEG bytes
     */
    public function jpeg(string $purpose, int $width, int $height, int $colour): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($image === false) {
            throw new RuntimeException('GD could not create an image');
        }
        [$r, $g, $b] = self::COLOURS[$colour % count(self::COLOURS)];
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, $r, $g, $b));
        $line = (int) imagecolorallocatealpha($image, 255, 255, 255, 90);
        $inset = (int) round(min($width, $height) * 0.04);
        imagesetthickness($image, max(2, (int) round($height / 300)));
        imagerectangle($image, $inset, $inset, $width - 1 - $inset, $height - 1 - $inset, $line);

        $label = $purpose . ' · ' . $width . '×' . $height;
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $size = $height * 0.06;
        if (ShareFont::available()) {
            $box = self::box($size, $label);
            $textWidth = $box[2] - $box[0];
            $scale = min(1.0, ($width * 0.8) / max(1, $textWidth));
            $size *= $scale;
            $box = self::box($size, $label);
            $x = (int) round(($width - ($box[2] - $box[0])) / 2 - $box[0]);
            $y = (int) round(($height - ($box[1] - $box[7])) / 2 - $box[7]);
            imagettftext($image, $size, 0, $x, $y, $white, ShareFont::path(), $label);
        } else {
            imagestring($image, 5, (int) (($width - strlen($label) * 9) / 2), (int) ($height / 2 - 8), $label, $white);
        }

        ob_start();
        imagejpeg($image, null, 82);

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int} the label's box at $size points
     */
    private static function box(float $size, string $label): array
    {
        $box = imagettfbbox($size, 0, ShareFont::path(), $label);
        if ($box === false) {
            throw new RuntimeException('GD could not measure the label');
        }

        /** @var array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int} $box */
        return $box;
    }
}
