<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\AdminIcon;
use DOMDocument;
use DOMElement;
use DOMText;
use PHPUnit\Framework\TestCase;

final class IconsAssetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheSpriteAndItsLicenseKeepTheirExactBytes(): void
    {
        $attributes = (string) file_get_contents(self::ROOT . '/.gitattributes');
        $license = (string) file_get_contents(self::ROOT . '/resources/admin/assets/icons.LICENSE');

        self::assertStringContainsString('/resources/admin/assets/icons.svg -text', $attributes);
        self::assertStringContainsString('/resources/admin/assets/icons.LICENSE -text', $attributes);
        self::assertStringStartsWith('Lucide 1.49.0 icons (https://lucide.dev, npm package lucide-static)', $license);
        self::assertStringContainsString('ISC License', $license);
        self::assertStringContainsString('Copyright (c) 2026 Lucide Icons and Contributors', $license);
        self::assertSame('51470de1fbe18c221fc1870e87bf6131df450dc4fdf23c79cb77996b4f59fad1', hash_file('sha256', self::ROOT . '/resources/admin/assets/icons.svg'));
    }

    public function testTheSpriteHoldsOnlyLucideSymbols(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/resources/admin/assets/icons.svg');
        $document = new DOMDocument();

        self::assertTrue($document->loadXML($source, LIBXML_NONET));
        self::assertStringNotContainsStringIgnoringCase('<script', $source);
        self::assertStringNotContainsString('href', $source, 'no references to other files');
        self::assertStringNotContainsString('style', $source);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $source);

        $root = $document->documentElement;
        self::assertInstanceOf(DOMElement::class, $root);
        self::assertSame('svg', $root->tagName);
        $ids = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->data) === '') {
                continue;
            }
            self::assertInstanceOf(DOMElement::class, $child);
            self::assertSame('symbol', $child->tagName);
            self::assertMatchesRegularExpression(AdminIcon::NAME_PATTERN, $child->getAttribute('id'));
            self::assertSame('0 0 24 24', $child->getAttribute('viewBox'));
            self::assertSame('none', $child->getAttribute('fill'));
            self::assertSame('currentColor', $child->getAttribute('stroke'));
            self::assertSame('2', $child->getAttribute('stroke-width'));
            self::assertSame('round', $child->getAttribute('stroke-linecap'));
            self::assertSame('round', $child->getAttribute('stroke-linejoin'));
            $ids[] = $child->getAttribute('id');
        }
        self::assertCount(count(array_unique($ids)), $ids, 'symbol ids are unique');
        self::assertContains('newspaper', $ids);
    }
}
