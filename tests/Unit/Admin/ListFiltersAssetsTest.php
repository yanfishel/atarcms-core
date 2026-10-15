<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class ListFiltersAssetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheModuleNeverInjectsMarkupStylesOrUrls(): void
    {
        $script = (string) file_get_contents(self::ROOT . '/resources/admin/assets/list-filters.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'import '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $script, $forbidden);
        }
    }

    public function testAChangeSubmitsAtOnceAndTypingAfterAPauseFromTwoCharacters(): void
    {
        $script = (string) file_get_contents(self::ROOT . '/resources/admin/assets/list-filters.js');
        $list = (string) file_get_contents(self::ROOT . '/resources/admin/templates/entries/index.php');

        foreach (['data-list-filters', 'data-list-search'] as $hook) {
            self::assertStringContainsString('[' . $hook . ']', $script, $hook);
            self::assertStringContainsString($hook, $list, $hook);
        }
        self::assertStringContainsString('const DELAY = 300;', $script);
        self::assertStringContainsString('const MIN_LENGTH = 2;', $script);
        self::assertStringContainsString('length >= MIN_LENGTH || length === 0', $script, 'an emptied box shows everything again');
        self::assertStringContainsString('requestSubmit()', $script, 'through the form, so htmx sends it');
    }
}
