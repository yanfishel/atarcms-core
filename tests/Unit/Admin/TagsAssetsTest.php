<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * tags.js follows the admin script rules (no markup, styles, setAttribute, requests or network URLs): it clones the
 * tag box's server <template>s, keeps the comma-separated input current and tells the entry form about changes.
 */
final class TagsAssetsTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    private const string TEMPLATES = __DIR__ . '/../../../resources/admin/templates';

    public function testTheModuleNeverInjectsMarkupStylesOrRequests(): void
    {
        $module = (string) file_get_contents(self::ASSETS . '/tags.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'htmx', 'import '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, $forbidden);
        }
        self::assertStringContainsString('template.content.firstElementChild.cloneNode(true)', $module, 'pills and options are clones of the server\'s templates');
        self::assertStringContainsString("carrier.dispatchEvent(new Event('input', { bubbles: true }));", $module, 'autosave.js hears every add and remove');
        self::assertStringContainsString("carrier.dispatchEvent(new Event('change', { bubbles: true }));", $module);
        self::assertStringContainsString('event.stopPropagation();', $module, 'typing a query alone is no change');
        self::assertStringContainsString('query.ariaActiveDescendantElement = ', $module, 'the combobox points at its active option through properties');
        self::assertStringContainsString("query.ariaExpanded = 'true';", $module);
        self::assertStringContainsString('label.htmlFor = query.id;', $module, 'the field label names the combobox');
        self::assertStringContainsString("normalize('NFD').replace(/\\p{M}/gu, '')", $module, 'case- and accent-insensitive, like the collation');
        self::assertStringContainsString('const MAX_SHOWN = 50;', $module);
        self::assertStringContainsString('event.isComposing || event.keyCode === 229', $module, 'an IME composition is never cut short (Safari\'s composition-ending Enter)');
        self::assertStringContainsString("full.textContent = isFull ? texts.full ?? '' : '';", $module, 'the limit line has text only when full, so the query\'s description is silent before');
        self::assertStringContainsString('if (!document.hasFocus())', $module, 'leaving the window adds nothing');
        self::assertStringContainsString('.slice(0, query.maxLength)', $module, 'a pasted remainder keeps the query\'s length limit');
    }

    public function testTheTemplatesHoldNoInlineCode(): void
    {
        foreach (['fields/tags.php', 'tags/pill.php'] as $file) {
            $template = (string) file_get_contents(self::TEMPLATES . '/' . $file);
            foreach (['style=', '<style', '<script', 'hx-on', 'innerHTML'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $template, $file . ': ' . $forbidden);
            }
        }
    }
}
