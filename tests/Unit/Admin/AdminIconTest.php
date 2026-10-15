<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\AdminIcon;
use AtarCms\Kernel\ConfigException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminIconTest extends TestCase
{
    private const string SPRITE = '/admin-assets/icons.svg?v=0123456789ab';

    public function testADecorativeIconIsHiddenFromAssistiveTechnology(): void
    {
        self::assertSame(
            '<svg class="admin-icon" aria-hidden="true"><use href="/admin-assets/icons.svg?v=0123456789ab#newspaper"></use></svg>',
            AdminIcon::svg(self::SPRITE, 'newspaper'),
        );
    }

    /**
     * A labelled icon is named by aria-label, never by an SVG <title>: the browser would show that as its own tooltip
     * box beside the shared one (Plan 27).
     */
    public function testALabelledIconIsAnImageNamedByAnEscapedAriaLabel(): void
    {
        self::assertSame(
            '<svg class="admin-icon" role="img" aria-label="Delete &lt;b&gt;“x”&lt;/b&gt;"><use href="/admin-assets/icons.svg?v=0123456789ab#trash-2"></use></svg>',
            AdminIcon::svg(self::SPRITE, 'trash-2', 'Delete <b>“x”</b>'),
        );
        self::assertStringContainsString('aria-hidden="true"', AdminIcon::svg(self::SPRITE, 'x', ''), 'an empty label is no label');
    }

    /**
     * Icons whose picture points somewhere (back and forward, in and out, lines of text) are mirrored in a right-to-left
     * page by their class (admin.css: .admin-icon-mirror:dir(rtl)); the others never are.
     */
    public function testDirectionalIconsCarryTheMirrorClass(): void
    {
        self::assertSame(
            '<svg class="admin-icon admin-icon-mirror" aria-hidden="true"><use href="/admin-assets/icons.svg?v=0123456789ab#chevron-left"></use></svg>',
            AdminIcon::svg(self::SPRITE, 'chevron-left'),
        );
        self::assertStringStartsWith('<svg class="admin-icon admin-icon-mirror" role="img" aria-label="Next">', AdminIcon::svg(self::SPRITE, 'chevron-right', 'Next'));
        foreach (['history', 'arrow-up', 'chevron-down', 'search', 'arrow-down-a-z', 'x'] as $name) {
            self::assertStringStartsWith('<svg class="admin-icon" ', AdminIcon::svg(self::SPRITE, $name), $name);
        }
        self::assertSame([
            'arrow-left', 'arrow-right', 'chevron-left', 'chevron-right', 'external-link', 'log-in', 'log-out',
            'folder-input', 'folder-tree', 'align-left', 'list', 'list-tree', 'arrow-down-wide-narrow',
        ], AdminIcon::MIRRORED);
        $symbols = AdminIcon::symbols(__DIR__ . '/../../../resources/admin/assets/icons.svg');
        foreach (AdminIcon::MIRRORED as $name) {
            self::assertContains($name, $symbols, $name);
        }
        $css = (string) file_get_contents(__DIR__ . '/../../../resources/admin/assets/admin.css');
        self::assertStringContainsString('.admin-icon-mirror:dir(rtl) { scale: -1 1; }', $css, 'scale, so the rotations of the rail toggle and the accordion still apply');
    }

    public function testTheSpriteUrlIsEscaped(): void
    {
        self::assertStringContainsString('href="/a&quot;b&amp;c#x"', AdminIcon::svg('/a"b&c', 'x'));
    }

    #[DataProvider('invalidNames')]
    public function testNamesOutsideLowercaseLettersDigitsAndHyphensAreRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminIcon::svg(self::SPRITE, $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Newspaper'];
        yield 'quote' => ['x"onload="alert(1)'];
        yield 'fragment' => ['x#y'];
        yield 'path' => ['../x'];
        yield 'space' => ['arrow up'];
        yield 'underscore' => ['arrow_up'];
        yield 'trailing newline' => ["arrow-up\n"];
    }

    public function testTheSymbolsAreTheIdsOfTheSprite(): void
    {
        $symbols = AdminIcon::symbols(__DIR__ . '/../../../resources/admin/assets/icons.svg');

        self::assertContains('circle', $symbols);
        self::assertContains('trash-2', $symbols);
        self::assertSame($symbols, array_values(array_unique($symbols)));
    }

    /**
     * A missing or empty sprite is one clear error, not every menu icon reported as missing.
     */
    public function testAMissingSpriteIsOneClearError(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('The icon sprite ' . __DIR__ . '/missing.svg is missing or has no symbols');

        AdminIcon::symbols(__DIR__ . '/missing.svg');
    }

    public function testASpriteWithoutSymbolsIsOneClearError(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('is missing or has no symbols');

        AdminIcon::symbols(__FILE__);
    }
}
