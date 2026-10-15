<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Kernel\ConfigException;
use AtarCms\Media\ImageVariant;
use AtarCms\Media\ImageVariants;
use AtarCms\View\ThemeConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageVariantTest extends TestCase
{
    public function testParsesASpec(): void
    {
        $variant = ImageVariant::fromArray('thumb', ['width' => 320, 'height' => 240, 'fit' => 'crop', 'quality' => 70]);

        self::assertSame(['thumb', 320, 240, 'crop', 70], [$variant->name, $variant->width, $variant->height, $variant->fit, $variant->quality]);
        $plain = ImageVariant::fromArray('medium', ['width' => 800]);
        self::assertSame([null, 'contain', 82], [$plain->height, $plain->fit, $plain->quality]);
    }

    /**
     * Output sizes verified against intervention/image 4.3 (scaleDown() for "contain", coverDown() for "crop").
     *
     * @return iterable<string, array{int, ?int, string, int, int, int, int}>
     */
    public static function dimensions(): iterable
    {
        yield 'contain, width only' => [800, null, 'contain', 1000, 333, 800, 266];
        yield 'contain, odd width' => [800, null, 'contain', 1201, 799, 800, 532];
        yield 'contain, box' => [320, 320, 'contain', 1200, 800, 320, 213];
        yield 'contain, tall into a box' => [800, 600, 'contain', 333, 1000, 200, 600];
        yield 'contain, never upscaled' => [800, null, 'contain', 300, 200, 300, 200];
        yield 'crop, wide' => [320, 320, 'crop', 1200, 800, 320, 320];
        yield 'crop, small original' => [320, 320, 'crop', 200, 100, 100, 100];
        yield 'crop, tall original' => [800, 600, 'crop', 333, 1000, 333, 250];
        yield 'crop, square into a banner' => [320, 200, 'crop', 500, 500, 320, 200];
    }

    #[DataProvider('dimensions')]
    public function testComputesTheOutputSize(int $width, ?int $height, string $fit, int $originalWidth, int $originalHeight, int $expectedWidth, int $expectedHeight): void
    {
        $variant = new ImageVariant('v', $width, $height, $fit);

        self::assertSame([$expectedWidth, $expectedHeight], $variant->dimensions($originalWidth, $originalHeight));
    }

    public function testTheVersionChangesWithTheSettings(): void
    {
        $version = ImageVariant::fromArray('thumb', ['width' => 320])->version();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $version);
        self::assertSame($version, ImageVariant::fromArray('thumb', ['width' => 320])->version());
        self::assertNotSame($version, ImageVariant::fromArray('thumb', ['width' => 321])->version());
        self::assertNotSame($version, ImageVariant::fromArray('thumb', ['width' => 320, 'quality' => 60])->version());
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidSpecs(): iterable
    {
        yield 'not an array' => ['thumb', 320, 'must be an array'];
        yield 'no width' => ['thumb', ['height' => 100], '"width" must be an integer between 1 and 4096'];
        yield 'width too large' => ['thumb', ['width' => 5000], '"width" must be an integer between 1 and 4096'];
        yield 'height zero' => ['thumb', ['width' => 100, 'height' => 0], '"height" must be an integer between 1 and 4096'];
        yield 'crop without height' => ['thumb', ['width' => 100, 'fit' => 'crop'], '"crop" needs a height'];
        yield 'unknown fit' => ['thumb', ['width' => 100, 'fit' => 'stretch'], '"fit" must be "contain" or "crop"'];
        yield 'quality' => ['thumb', ['width' => 100, 'quality' => 0], '"quality" must be an integer between 1 and 100'];
        yield 'unknown key' => ['thumb', ['width' => 100, 'format' => 'avif'], 'unknown key "format"'];
        yield 'bad name' => ['Thumb', ['width' => 100], 'Invalid image variant name "Thumb"'];
    }

    #[DataProvider('invalidSpecs')]
    public function testRejectsInvalidSpecs(string $name, mixed $spec, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        ImageVariant::fromArray($name, $spec);
    }

    public function testTheAdminVariantIsAlwaysAvailableAndReserved(): void
    {
        $variants = ImageVariants::fromTheme(ThemeConfig::fromArray(['images' => ['thumb' => ['width' => 320, 'height' => 320, 'fit' => 'crop']]]));

        self::assertSame(['thumb', 'admin', 'icon_32', 'icon_180', 'icon_192', 'icon_512'], array_keys($variants->variants));
        $admin = $variants->get(ImageVariants::ADMIN);
        self::assertNotNull($admin);
        self::assertSame([320, 320, 'contain', 75], [$admin->width, $admin->height, $admin->fit, $admin->quality]);
        self::assertNull($variants->get('medium'));
        self::assertSame(['admin', 'icon_32', 'icon_180', 'icon_192', 'icon_512'], array_keys(new ImageVariants()->variants));
    }

    public function testTheIconVariantsAreSquarePngsOutOfThemeFamilies(): void
    {
        $variants = new ImageVariants(['thumb' => ImageVariant::fromArray('thumb', ['width' => 320, 'height' => 320, 'fit' => 'crop'])]);

        foreach (ImageVariants::ICONS as $name => $size) {
            $icon = $variants->get($name);
            self::assertNotNull($icon);
            self::assertSame([$size, $size, 'crop'], [$icon->width, $icon->height, $icon->fit]);
            self::assertTrue(ImageVariants::isIcon($name));
        }
        self::assertFalse(ImageVariants::isIcon('thumb'));
        self::assertSame(['thumb'], self::names($variants->family($variants->variants['thumb'])), 'a square theme variant takes no icon into its srcset');
        self::assertSame(['icon_192'], self::names($variants->family($variants->variants['icon_192'])));
    }

    public function testFamiliesShareFitAndAspectRatio(): void
    {
        $variants = new ImageVariants([
            'thumb' => ImageVariant::fromArray('thumb', ['width' => 320, 'height' => 320, 'fit' => 'crop']),
            'square' => ImageVariant::fromArray('square', ['width' => 640, 'height' => 640, 'fit' => 'crop']),
            'banner' => ImageVariant::fromArray('banner', ['width' => 1200, 'height' => 400, 'fit' => 'crop']),
            'medium' => ImageVariant::fromArray('medium', ['width' => 800]),
            'box' => ImageVariant::fromArray('box', ['width' => 400, 'height' => 400]),
        ]);

        self::assertSame(['thumb', 'square'], self::names($variants->family($variants->variants['thumb'])));
        self::assertSame(['banner'], self::names($variants->family($variants->variants['banner'])));
        self::assertSame(['medium', 'box'], self::names($variants->family($variants->variants['medium'])));
        self::assertSame(['admin'], self::names($variants->family($variants->variants['admin'])));
    }

    /**
     * @param array<ImageVariant> $family
     *
     * @return array<string>
     */
    private static function names(array $family): array
    {
        return array_map(static fn(ImageVariant $v): string => $v->name, $family);
    }
}
