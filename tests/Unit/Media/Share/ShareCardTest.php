<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media\Share;

use AtarCms\Media\ImageProcessor;
use AtarCms\Media\Share\ShareBrand;
use AtarCms\Media\Share\ShareCard;
use AtarCms\Media\Share\ShareCardInput;
use AtarCms\Media\Share\ShareFont;
use AtarCms\Media\Share\ShareLayout;
use AtarCms\Media\Share\TitleLines;
use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\AtarLogo;
use AtarCms\Tests\Support\TempDirectory;
use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ShareCardTest extends TestCase
{
    use TempDirectory;

    private const string TITLE = 'Рыбалка на Оке в октябре: где клюёт щука и что взять с собой';

    /**
     * @return iterable<string, array{ShareLayout}>
     */
    public static function layouts(): iterable
    {
        foreach (ShareLayout::cases() as $layout) {
            yield $layout->value => [$layout];
        }
    }

    #[DataProvider('layouts')]
    public function testEachLayoutIsA1200By630Jpeg(ShareLayout $layout): void
    {
        $bytes = $this->render($layout);

        $size = getimagesizefromstring($bytes);
        self::assertNotFalse($size);
        self::assertSame([1200, 630, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);
    }

    public function testTheBandLayoutDarkensThePhotoAndHasACharcoalBand(): void
    {
        $card = self::decode($this->render(ShareLayout::Band));

        self::assertColor([0x24, 0x21, 0x1d], self::rgb($card, 1100, 600), 'the band');
        self::assertLessThan(120, self::rgb($card, 1150, 20)[0], 'the top of the photo is darkened');
        self::assertGreaterThan(140, self::rgb($card, 1150, 470)[0], 'the photo above the band is hardly darkened');
        self::assertGreaterThan(240, max(self::row($card, 64 + 40, 70, 600)), 'white title text near the top');
    }

    public function testTheBottomLayoutHasTheAccentBar(): void
    {
        $card = self::decode($this->render(ShareLayout::Bottom));

        self::assertColor([0x2a, 0x7d, 0x5c], self::rgb($card, 130, 476), 'the accent bar');
        self::assertGreaterThan(190, self::rgb($card, 1150, 20)[0], 'the top of the photo stays bright');
        self::assertLessThan(80, self::rgb($card, 1150, 620)[0], 'the bottom is dark');
    }

    public function testThePanelLayoutHasASlantedAccentPanelBesideThePhoto(): void
    {
        $card = self::decode($this->render(ShareLayout::Panel, AdminAccent::Blue));

        self::assertColor([0x25, 0x63, 0xb8], self::rgb($card, 30, 300), 'the panel');
        self::assertColor([200, 200, 200], self::rgb($card, 1100, 300), 'the photo');
        self::assertColor([0x25, 0x63, 0xb8], self::rgb($card, 550, 5), 'the panel is 560 px wide at the top');
        self::assertColor([200, 200, 200], self::rgb($card, 530, 625), 'and about 515 px at the bottom');
        // Row 300: the edge crosses pixel 538 at about 54%, so that pixel mixes the accent and the photo.
        $edge = self::rgb($card, 538, 300)[0];
        self::assertGreaterThan(0x25 + 30, $edge, 'anti-aliased: not the accent');
        self::assertLessThan(200 - 30, $edge, 'anti-aliased: not the photo');
    }

    public function testTheLogoIsDrawnFromEitherSource(): void
    {
        $atar = self::decode($this->render(ShareLayout::Band));
        self::assertColor([0x2a, 0x7d, 0x5c], self::rgb($atar, 70 + 12, 513 + 42), 'the AtarCMS logo tile');
        self::assertColor([0x24, 0x21, 0x1d], self::rgb($atar, 70, 510), 'its rounded corner shows the band');

        $icon = $this->writeFile('icon.png', self::png(300, 200, [250, 200, 0]));
        $card = self::decode($this->render(ShareLayout::Band, logo: $icon));
        self::assertColor([250, 200, 0], self::rgb($card, 70 + 42, 513 + 42), 'the site icon, cover-cropped');
        self::assertColor([0x24, 0x21, 0x1d], self::rgb($card, 70, 510), 'rounded');
    }

    public function testALongBrandNameStaysInsideTheCard(): void
    {
        $card = self::decode($this->render(ShareLayout::Band, name: str_repeat('Клуб рыбаков «Тихая заводь» ', 6)));

        self::assertColor([0x24, 0x21, 0x1d], self::rgb($card, 1190, 545), 'nothing is drawn past the right margin');
    }

    #[DataProvider('layouts')]
    public function testAHebrewTitleIsRightAlignedInItsBox(ShareLayout $layout): void
    {
        // The title's box per layout: [left, right, a row through the first line].
        [$left, $right, $y] = match ($layout) {
            ShareLayout::Band => [70, 1130, 64 + 40],
            ShareLayout::Bottom => [70, 1130, 630 - 170 - 68 + 40],
            ShareLayout::Panel => [1200 - 64 - 440, 1200 - 64, 70 + 34],
        };
        $card = self::decode($this->render($layout, title: 'דיג 2026', rtl: true));

        self::assertGreaterThan(240, max(self::row($card, $y, $right - 120, $right)), 'white text at the right edge of the box');
        self::assertLessThan(235, max(self::row($card, $y, $left, $right - 260)), 'nothing at its left');
    }

    public function testAHebrewEntrysTitleStartingWithALatinWordIsRightAligned(): void
    {
        $card = self::decode($this->render(ShareLayout::Band, title: 'GoPro 12 – סקירה', rtl: true));

        self::assertGreaterThan(240, max(self::row($card, 104, 1010, 1130)), 'white text at the right edge');
        self::assertLessThan(235, max(self::row($card, 104, 70, 500)), 'nothing at the left');
    }

    public function testAnEnglishEntrysTitleStartingWithHebrewStaysLeftAligned(): void
    {
        $card = self::decode($this->render(ShareLayout::Band, title: 'שלום 12', rtl: false));

        self::assertGreaterThan(240, max(self::row($card, 104, 70, 200)), 'white text at the left edge');
        self::assertLessThan(235, max(self::row($card, 104, 700, 1130)), 'nothing at the right');
    }

    public function testTheBrandRowOfARightToLeftEntryIsMirrored(): void
    {
        $domain = 'example-fishing-club.co.il';
        $card = self::decode($this->render(ShareLayout::Band, name: 'AtarCMS ישראל', domain: $domain, rtl: true));

        self::assertColor([0x2a, 0x7d, 0x5c], self::rgb($card, 1200 - 70 - 12, 513 + 42), 'the logo at the right edge');
        self::assertColor([0x24, 0x21, 0x1d], self::rgb($card, 1200 - 70 - 1, 510), 'its rounded corner');
        self::assertColor([0x24, 0x21, 0x1d], self::rgb($card, 70 + 42, 513 + 42), 'no logo on the left');
        // The text ends 22 px left of the logo (1046): the name on row 535, the domain on row 580.
        $name = TitleLines::width('AtarCMS ישראל', ShareFont::path(), 34);
        $domainWidth = TitleLines::width($domain, ShareFont::path(), 26);
        self::assertGreaterThan(240, max(self::row($card, 535, 1024 - 50, 1026)), 'the name flush against the logo');
        self::assertLessThan(120, max(self::row($card, 535, 70, 1024 - $name - 10)), 'nothing before the name');
        self::assertGreaterThan(180, max(self::row($card, 580, 1024 - 50, 1026)), 'the domain flush against the logo');
        self::assertLessThan(120, max(self::row($card, 580, 70, 1024 - $domainWidth - 10)), 'nothing before the domain');
    }

    public function testThePanelOfARightToLeftEntryIsOnTheRight(): void
    {
        $card = self::decode($this->render(ShareLayout::Panel, AdminAccent::Blue, rtl: true, title: 'דיג 2026'));

        self::assertColor([0x25, 0x63, 0xb8], self::rgb($card, 1170, 300), 'the panel');
        self::assertColor([200, 200, 200], self::rgb($card, 100, 300), 'the photo');
        self::assertColor([0x25, 0x63, 0xb8], self::rgb($card, 1200 - 550, 5), 'the panel is 560 px wide at the top');
        self::assertColor([200, 200, 200], self::rgb($card, 1200 - 530, 625), 'and about 515 px at the bottom');
        $edge = self::rgb($card, 1200 - 1 - 538, 300)[0];
        self::assertGreaterThan(0x25 + 30, $edge, 'anti-aliased: not the accent');
        self::assertLessThan(200 - 30, $edge, 'anti-aliased: not the photo');
    }

    public function testTheAccentBarOfARightToLeftEntryIsOnTheRight(): void
    {
        $card = self::decode($this->render(ShareLayout::Bottom, rtl: true, title: 'דיג 2026'));

        self::assertColor([0x2a, 0x7d, 0x5c], self::rgb($card, 1200 - 130, 476), 'the accent bar');
        self::assertLessThan(120, self::rgb($card, 130, 476)[1], 'not on the left');
    }

    public function testALeftToRightCardIsDrawnAsBefore(): void
    {
        foreach (ShareLayout::cases() as $layout) {
            self::assertSame($this->render($layout), $this->render($layout, rtl: false), $layout->value);
        }
    }

    private function render(ShareLayout $layout, AdminAccent $accent = AdminAccent::Green, ?string $logo = null, string $name = 'Клуб рыбаков «Тихая заводь»', string $title = self::TITLE, string $domain = 'zavod.ru', bool $rtl = false): string
    {
        $photo = $this->writeFile('photo.jpg', self::jpeg(1600, 1000, [200, 200, 200]));
        $brand = new ShareBrand($name, 'Тихая заводь', $domain, $accent->logoColor(), $logo ?? AtarLogo::file($accent, 192), 'test', $layout, $rtl);

        return (new ShareCard(new ImageProcessor()))->render(new ShareCardInput($photo, $title, $brand, $rtl));
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $color
     */
    private static function jpeg(int $width, int $height, array $color): string
    {
        $image = self::solid($width, $height, $color);
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $color
     */
    private static function png(int $width, int $height, array $color): string
    {
        $image = self::solid($width, $height, $color);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $color
     */
    private static function solid(int $width, int $height, array $color): GdImage
    {
        $image = imagecreatetruecolor($width, $height) ?: throw new RuntimeException('GD');
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, ...$color));

        return $image;
    }

    private static function decode(string $bytes): GdImage
    {
        return imagecreatefromstring($bytes) ?: throw new RuntimeException('Not an image');
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(GdImage $image, int $x, int $y): array
    {
        $color = (int) imagecolorat($image, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    /**
     * @return non-empty-list<int> the red values of a row segment
     */
    private static function row(GdImage $image, int $y, int $from, int $to): array
    {
        $values = [];
        for ($x = $from; $x < $to; $x++) {
            $values[] = self::rgb($image, $x, $y)[0];
        }
        if ($values === []) {
            self::fail('a row segment of at least one pixel');
        }

        return $values;
    }

    /**
     * @param array{int, int, int} $expected
     * @param array{int, int, int} $actual
     */
    private static function assertColor(array $expected, array $actual, string $message): void
    {
        foreach ($expected as $i => $channel) {
            self::assertEqualsWithDelta($channel, $actual[$i], 8, $message . ' (' . implode(',', $actual) . ')');
        }
    }
}
