<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class FishartLogoAssetTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheFishartLogoKeepsItsExactBytes(): void
    {
        $logo = self::ROOT . '/resources/admin/assets/fishart.png';

        self::assertStringContainsString('/resources/admin/assets/fishart.png -text', (string) file_get_contents(self::ROOT . '/.gitattributes'));
        self::assertSame('e319b1f3d738f18937198beb04ce6c676bb45e9dcf5a293e864bb360856c027c', hash_file('sha256', $logo));
        self::assertSame([400, 93, IMAGETYPE_PNG], array_slice(getimagesize($logo) ?: [], 0, 3));
    }
}
