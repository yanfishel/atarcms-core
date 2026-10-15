<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Media\MediaPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaPathTest extends TestCase
{
    private const string HEX = '0123456789abcdef0123456789abcdef';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'jpeg' => ['2026/09/' . self::HEX . '.jpg', true];
        yield 'pdf' => ['2026/12/' . self::HEX . '.pdf', true];
        yield 'mp4' => ['2026/12/' . self::HEX . '.mp4', true];
        yield 'webm' => ['2026/12/' . self::HEX . '.webm', true];
        yield 'mov' => ['2026/12/' . self::HEX . '.mov', false];
        yield 'month 13' => ['2026/13/' . self::HEX . '.jpg', false];
        yield 'upper-case hex' => ['2026/09/' . strtoupper(self::HEX) . '.jpg', false];
        yield 'short name' => ['2026/09/abc.jpg', false];
        yield 'php' => ['2026/09/' . self::HEX . '.php', false];
        yield 'double extension' => ['2026/09/' . self::HEX . '.jpg.php', false];
        yield 'traversal' => ['../2026/09/' . self::HEX . '.jpg', false];
        yield 'leading slash' => ['/2026/09/' . self::HEX . '.jpg', false];
        yield 'trailing newline' => ["2026/09/" . self::HEX . ".jpg\n", false];
        yield 'jpeg spelling' => ['2026/09/' . self::HEX . '.jpeg', false];
    }

    #[DataProvider('paths')]
    public function testValidatesStoredPaths(string $path, bool $valid): void
    {
        self::assertSame($valid, MediaPath::isValid($path));
    }

    public function testKnowsTheTypeOfAPath(): void
    {
        self::assertSame('image/jpeg', MediaPath::mime('2026/09/' . self::HEX . '.jpg'));
        self::assertSame('image/webp', MediaPath::mime('2026/09/' . self::HEX . '.webp'));
        self::assertSame('application/pdf', MediaPath::mime('2026/09/' . self::HEX . '.pdf'));
        self::assertTrue(MediaPath::isImage('2026/09/' . self::HEX . '.gif'));
        self::assertFalse(MediaPath::isImage('2026/09/' . self::HEX . '.pdf'));
        self::assertSame('video/mp4', MediaPath::mime('2026/09/' . self::HEX . '.mp4'));
        self::assertSame('video/webm', MediaPath::mime('2026/09/' . self::HEX . '.webm'));
        self::assertTrue(MediaPath::isVideo('2026/09/' . self::HEX . '.mp4'));
        self::assertFalse(MediaPath::isVideo('2026/09/' . self::HEX . '.pdf'));
        self::assertFalse(MediaPath::isImage('2026/09/' . self::HEX . '.webm'));
        self::assertNull(MediaPath::variantSource('2026/09/' . self::HEX . '.mp4'), 'videos have no variants');
        self::assertNull(MediaPath::variantSource('2026/09/' . self::HEX . '.mp4.webp'));
    }

    public function testFindsTheOriginalOfAVariantFile(): void
    {
        $jpeg = '2026/09/' . self::HEX . '.jpg';
        $webp = '2026/09/' . self::HEX . '.webp';

        $png = '2026/09/' . self::HEX . '.png';

        self::assertSame([$jpeg, null], MediaPath::variantSource($jpeg));
        self::assertSame([$jpeg, 'image/webp'], MediaPath::variantSource($jpeg . '.webp'));
        self::assertSame([$webp, null], MediaPath::variantSource($webp));
        self::assertNull(MediaPath::variantSource($webp . '.webp'), 'a WebP original has no second WebP version');
        self::assertSame([$jpeg, 'image/png'], MediaPath::variantSource($jpeg . '.png'), 'the PNG version of the site icon');
        self::assertSame([$webp, 'image/png'], MediaPath::variantSource($webp . '.png'));
        self::assertNull(MediaPath::variantSource($png . '.png'), 'a PNG original has no second PNG version');
        self::assertNull(MediaPath::variantSource($jpeg . '.webp.png'));
        self::assertNull(MediaPath::variantSource('2026/09/' . self::HEX . '.pdf'));
        self::assertNull(MediaPath::variantSource('2026/09/' . self::HEX . '.pdf.webp'));
        self::assertNull(MediaPath::variantSource('../' . $jpeg));
        self::assertSame([$jpeg, 'image/avif'], MediaPath::variantSource($jpeg . '.avif'), 'the AVIF version');
        self::assertSame([$webp, 'image/avif'], MediaPath::variantSource($webp . '.avif'));
        self::assertSame([$png, 'image/avif'], MediaPath::variantSource($png . '.avif'));
        self::assertNull(MediaPath::variantSource($jpeg . '.avif.avif'));
        self::assertNull(MediaPath::variantSource($jpeg . '.webp.avif'));
        self::assertNull(MediaPath::variantSource('2026/09/' . self::HEX . '.avif'), 'no AVIF originals');
        self::assertNull(MediaPath::variantSource('2026/09/' . self::HEX . '.mp4.avif'));
    }
}
