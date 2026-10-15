<?php

declare(strict_types=1);

namespace AtarCms\Media\Share;

use AtarCms\I18n\Bidi;
use AtarCms\Media\ImageFailed;
use AtarCms\Media\ImageProcessor;
use GdImage;
use RuntimeException;

/**
 * Draws a share card: a 1200 × 630 JPEG of the photo, darkened or beside an accent panel, with the title (Open Sans
 * Bold, at most four lines) and the brand (logo, site name, domain). Sizes are CSS pixels of the user-approved
 * brainstorm mock-up; text goes through TitleLines (GD points at 96 dpi). Plain GD: the photo and the logo are decoded
 * and cover-cropped by ImageProcessor.
 */
final class ShareCard
{
    /** Bumped whenever the drawing changes, so every image is made again on the next save or rebuild. */
    public const int VERSION = 3;

    public const int WIDTH = 1200;

    public const int HEIGHT = 630;

    public const int QUALITY = 85;

    /** The line height of the title, relative to its size. */
    private const float LINE_HEIGHT = 1.18;

    /** The brand's line height (the mock-up's body text). */
    private const float BRAND_LINE_HEIGHT = 1.5;

    /** Open Sans' ascender and descender (hhea), relative to the size: where a baseline sits in its line box. */
    private const float ASCENT = 1.069;

    private const float DESCENT = 0.293;

    private const string BAND = '#24211d';

    private const int LOGO = 84;

    private const int LOGO_RADIUS = 22;

    private const int LOGO_GAP = 22;

    private const int NAME_SIZE = 34;

    private const int DOMAIN_SIZE = 26;

    public function __construct(private readonly ImageProcessor $images) {}

    /**
     * @return string the JPEG bytes
     *
     * @throws ImageFailed when the photo or the logo cannot be read
     * @throws RuntimeException when GD cannot draw (no FreeType, a missing font)
     */
    public function render(ShareCardInput $input): string
    {
        if (!ShareFont::available()) {
            throw new RuntimeException('GD cannot draw TrueType text here (build GD with FreeType)');
        }
        $card = imagecreatetruecolor(self::WIDTH, self::HEIGHT) ?: throw new RuntimeException('GD could not create the card');
        imagealphablending($card, true);
        $brand = $input->brand;
        $accent = self::hex($brand->accent);
        // A right-to-left entry gets the mirror image of the layout (never of the photo or the logo).
        $m = $input->rtl;

        switch ($brand->layout) {
            case ShareLayout::Band:
                $this->photo($card, $input->photoFile, 0, self::WIDTH);
                self::gradient($card, static fn(float $t): float => $t <= 0.6 ? 0.55 - 0.2 * $t / 0.6 : 0.35 * (1 - $t) / 0.4);
                self::fill($card, 0, self::HEIGHT - 150, self::WIDTH, 150, self::hex(self::BAND));
                $this->title($card, $input, self::x(70, 1060, $m), 64, 1060, 58, false);
                $this->brand($card, $brand, self::x(70, self::WIDTH - 140, $m), self::HEIGHT - 33, self::WIDTH - 140, $m);
                break;
            case ShareLayout::Bottom:
                $this->photo($card, $input->photoFile, 0, self::WIDTH);
                self::gradient($card, static function (float $t): float {
                    $s = 1 - $t;

                    return $s <= 0.45 ? 0.85 - 0.3 * $s / 0.45 : ($s <= 0.8 ? 0.55 * (0.8 - $s) / 0.35 : 0.0);
                });
                $this->title($card, $input, self::x(70, 1060, $m), self::HEIGHT - 170, 1060, 58, true);
                self::roundedRect($card, self::x(70, 120, $m), self::HEIGHT - 158, 120, 8, 4, $accent, 1.0);
                $this->brand($card, $brand, self::x(70, self::WIDTH - 140, $m), self::HEIGHT - 56, self::WIDTH - 140, $m);
                break;
            case ShareLayout::Panel:
                $this->photo($card, $input->photoFile, self::x(520, self::WIDTH - 520, $m), self::WIDTH - 520);
                self::panel($card, 560, 515, $accent, $m);
                $this->title($card, $input, self::x(64, 440, $m), 70, 440, 48, false);
                $this->brand($card, $brand, self::x(64, 515 - 64 - 24, $m), self::HEIGHT - 56, 515 - 64 - 24, $m, true);
                break;
        }

        imageinterlace($card, true);
        ob_start();
        $written = imagejpeg($card, null, self::QUALITY);
        $bytes = (string) ob_get_clean();
        if (!$written || $bytes === '') {
            throw new RuntimeException('GD could not encode the card');
        }

        return $bytes;
    }

    private function photo(GdImage $card, string $file, int $x, int $width): void
    {
        $photo = $this->images->cover($file, $width, self::HEIGHT);
        imagecopy($card, $photo, $x, 0, 0, 0, $width, self::HEIGHT);
    }

    /**
     * Draws the title lines from $top (or, with $fromBottom, so that the last line box ends at $top).
     */
    private function title(GdImage $card, ShareCardInput $input, int $x, int $top, int $width, int $size, bool $fromBottom): void
    {
        $lines = TitleLines::place($input->title, ShareFont::path(), $size, $width, rtl: $input->rtl);
        $step = $size * self::LINE_HEIGHT;
        $start = $fromBottom ? $top - count($lines) * $step : (float) $top;
        $white = self::color($card, [255, 255, 255]);
        foreach ($lines as $i => [$line, $offset]) {
            self::text($card, $line, $size, $x + $offset, $start + $i * $step + self::baseline($size, self::LINE_HEIGHT), $white);
        }
    }

    /**
     * The logo, then the name and the domain on two lines, vertically centred like the mock-up's flex row whose bottom
     * edge is $bottom. Mirrored, the logo is at the right of the box and both lines end flush against it; the name is
     * in the visual order of the brand's language either way, the domain always left to right.
     */
    private function brand(GdImage $card, ShareBrand $brand, int $x, int $bottom, int $width, bool $mirror, bool $ring = false): void
    {
        $nameBox = self::NAME_SIZE * self::BRAND_LINE_HEIGHT;
        $domainBox = self::DOMAIN_SIZE * self::BRAND_LINE_HEIGHT;
        $height = max(self::LOGO, $nameBox + $domainBox);
        $top = $bottom - $height;
        $logoX = $mirror ? $x + $width - self::LOGO : $x;
        $logoTop = (int) round($top + ($height - self::LOGO) / 2);
        if ($ring) {
            self::roundedRect($card, $logoX - 3, $logoTop - 3, self::LOGO + 6, self::LOGO + 6, self::LOGO_RADIUS + 3, [255, 255, 255], 0.35);
        }
        $logo = $this->images->cover($brand->logoFile, self::LOGO, self::LOGO);
        self::roundCorners($logo, self::LOGO_RADIUS);
        imagecopy($card, $logo, $logoX, $logoTop, 0, 0, self::LOGO, self::LOGO);

        $textWidth = $width - self::LOGO - self::LOGO_GAP;
        $textTop = $top + ($height - $nameBox - $domainBox) / 2;
        $name = Bidi::visual(TitleLines::fit($brand->shownName(), ShareFont::path(), self::NAME_SIZE, $textWidth), $brand->rtl);
        $domain = Bidi::visual(TitleLines::fit($brand->domain, ShareFont::path(), self::DOMAIN_SIZE, $textWidth), false);
        $textEnd = $logoX - self::LOGO_GAP;
        $nameX = $mirror ? $textEnd - TitleLines::width($name, ShareFont::path(), self::NAME_SIZE) : $x + self::LOGO + self::LOGO_GAP;
        $domainX = $mirror ? $textEnd - TitleLines::width($domain, ShareFont::path(), self::DOMAIN_SIZE) : $x + self::LOGO + self::LOGO_GAP;
        self::text($card, $name, self::NAME_SIZE, $nameX, $textTop + self::baseline(self::NAME_SIZE, self::BRAND_LINE_HEIGHT), self::color($card, [255, 255, 255]));
        self::text($card, $domain, self::DOMAIN_SIZE, $domainX, $textTop + $nameBox + self::baseline(self::DOMAIN_SIZE, self::BRAND_LINE_HEIGHT), self::color($card, [255, 255, 255], 0.75));
    }

    /**
     * Where the baseline sits below the top of a line box of $size × $lineHeight (CSS half-leading).
     */
    private static function baseline(float $size, float $lineHeight): float
    {
        return ($lineHeight - self::ASCENT - self::DESCENT) / 2 * $size + self::ASCENT * $size;
    }

    private static function text(GdImage $card, string $text, float $size, int $x, float $baseline, int $color): void
    {
        if ($text !== '' && imagettftext($card, $size * TitleLines::POINTS, 0, $x, (int) round($baseline), $color, ShareFont::path(), $text) === false) {
            throw new RuntimeException('GD could not draw the text');
        }
    }

    /**
     * Black over the whole card, row by row, with the opacity $opacity(t) at t = y / height.
     *
     * @param callable(float): float $opacity
     */
    private static function gradient(GdImage $card, callable $opacity): void
    {
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $alpha = max(0.0, min(1.0, $opacity(($y + 0.5) / self::HEIGHT)));
            if ($alpha > 0) {
                imagefilledrectangle($card, 0, $y, self::WIDTH - 1, $y, self::color($card, [0, 0, 0], $alpha));
            }
        }
    }

    /**
     * The accent panel from the left edge to a slanted right edge ($top px wide at the top, $bottom at the bottom),
     * anti-aliased by the pixel the edge crosses on each row; $mirror draws it from the right edge.
     *
     * @param array{int, int, int} $rgb
     */
    private static function panel(GdImage $card, int $top, int $bottom, array $rgb, bool $mirror): void
    {
        $solid = self::color($card, $rgb);
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $edge = $top + ($bottom - $top) * ($y + 0.5) / self::HEIGHT;
            $full = (int) floor($edge);
            imagefilledrectangle($card, self::x(0, $full, $mirror), $y, self::x(0, $full, $mirror) + $full - 1, $y, $solid);
            $coverage = $edge - $full;
            if ($coverage > 0) {
                imagesetpixel($card, self::x($full, 1, $mirror), $y, self::color($card, $rgb, $coverage));
            }
        }
    }

    /**
     * The left edge of a box at $x, $width wide, in a card mirrored for a right-to-left language (or as it is).
     */
    private static function x(int $x, int $width, bool $mirror): int
    {
        return $mirror ? self::WIDTH - $x - $width : $x;
    }

    /**
     * @param array{int, int, int} $rgb
     */
    private static function fill(GdImage $card, int $x, int $y, int $width, int $height, array $rgb): void
    {
        imagefilledrectangle($card, $x, $y, $x + $width - 1, $y + $height - 1, self::color($card, $rgb));
    }

    /**
     * A filled rectangle with anti-aliased round corners of $radius, at $opacity.
     *
     * @param array{int, int, int} $rgb
     */
    private static function roundedRect(GdImage $card, int $x, int $y, int $width, int $height, int $radius, array $rgb, float $opacity): void
    {
        for ($j = 0; $j < $height; $j++) {
            for ($i = 0; $i < $width; $i++) {
                $coverage = self::coverage($i, $j, $width, $height, $radius) * $opacity;
                if ($coverage > 0) {
                    imagesetpixel($card, $x + $i, $y + $j, self::color($card, $rgb, $coverage));
                }
            }
        }
    }

    /**
     * Makes the corners of $image transparent (anti-aliased) with $radius.
     */
    private static function roundCorners(GdImage $image, int $radius): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        for ($j = 0; $j < $height; $j++) {
            for ($i = 0; $i < $width; $i++) {
                $coverage = self::coverage($i, $j, $width, $height, $radius);
                if ($coverage >= 1) {
                    continue;
                }
                $color = (int) imagecolorat($image, $i, $j);
                $alpha = 1 - (($color >> 24) & 0x7F) / 127;
                $rgb = [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
                imagesetpixel($image, $i, $j, self::color($image, $rgb, $alpha * $coverage));
            }
        }
        imagealphablending($image, true);
    }

    /**
     * How much of the pixel ($i, $j) of a $width × $height box lies inside its rounded corners (0…1, sampled 4 × 4).
     */
    private static function coverage(int $i, int $j, int $width, int $height, int $radius): float
    {
        $cx = $i < $radius ? $radius : ($i >= $width - $radius ? $width - $radius : null);
        $cy = $j < $radius ? $radius : ($j >= $height - $radius ? $height - $radius : null);
        if ($cx === null || $cy === null) {
            return 1.0;
        }
        $inside = 0;
        for ($sy = 0; $sy < 4; $sy++) {
            for ($sx = 0; $sx < 4; $sx++) {
                $dx = $i + ($sx + 0.5) / 4 - $cx;
                $dy = $j + ($sy + 0.5) / 4 - $cy;
                if ($dx * $dx + $dy * $dy <= $radius * $radius) {
                    $inside++;
                }
            }
        }

        return $inside / 16;
    }

    /**
     * @param array{int, int, int} $rgb
     * @param float $opacity 1 = opaque
     */
    private static function color(GdImage $image, array $rgb, float $opacity = 1.0): int
    {
        $color = imagecolorallocatealpha(
            $image,
            self::channel($rgb[0]),
            self::channel($rgb[1]),
            self::channel($rgb[2]),
            self::alpha($opacity),
        );
        if ($color === false) {
            throw new RuntimeException('GD could not allocate a colour');
        }

        return $color;
    }

    /**
     * @return int<0, 255>
     */
    private static function channel(int $value): int
    {
        return max(0, min(255, $value));
    }

    /**
     * @return int<0, 127> GD's alpha of $opacity (0 = opaque, 127 = transparent)
     */
    private static function alpha(float $opacity): int
    {
        return max(0, min(127, (int) round(127 * (1 - $opacity))));
    }

    /**
     * @return array{int, int, int}
     */
    private static function hex(string $hex): array
    {
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m) !== 1) {
            throw new RuntimeException(sprintf('Invalid colour "%s"', $hex));
        }

        return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3])];
    }
}
