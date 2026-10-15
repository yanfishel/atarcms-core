<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class TabsAssetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheTabsModuleNeverInjectsMarkupStylesOrUrls(): void
    {
        $tabs = (string) file_get_contents(self::ROOT . '/resources/admin/assets/tabs.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'import '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $tabs, $forbidden);
        }
    }

    public function testTheModuleReadsTheHooksTheFormRenders(): void
    {
        $tabs = (string) file_get_contents(self::ROOT . '/resources/admin/assets/tabs.js');
        $form = (string) file_get_contents(self::ROOT . '/resources/admin/templates/entries/form.php');

        foreach (['data-tabs', 'data-status-date', 'data-status-choice'] as $hook) {
            self::assertStringContainsString('[' . $hook . ']', $tabs, $hook);
            self::assertStringContainsString($hook, $form, $hook);
        }
        self::assertStringContainsString("choice.value !== 'published'", $tabs, 'only "Published" fills the date');
        self::assertStringContainsString("input.value === ''", $tabs, 'a typed date is never overwritten');
        self::assertStringContainsString('input.value = input.dataset.now', $tabs);
        self::assertStringContainsString('input[data-now]', $tabs);
        self::assertStringContainsString('data-now=', $form);
        self::assertStringContainsString('dataset.errors', $tabs, 'the first tab with errors opens');
        self::assertStringContainsString('data-errors=', $form);
        self::assertStringContainsString("addEventListener('invalid'", $tabs, 'an invalid input in a hidden panel gets its tab opened');
        self::assertStringContainsString('admin.tabs.', $form, 'the storage key of each column');
        self::assertStringContainsString("'input:invalid, select:invalid, textarea:invalid'", $tabs, 'only the first invalid control opens its tab');
        self::assertStringContainsString('event.target !== form.querySelector(FIRST_INVALID)', $tabs);
        self::assertStringContainsString('event.altKey', $tabs, 'browser shortcuts are not swallowed');
        // WAI-ARIA tabs: in a right-to-left page the right arrow goes to the previous tab (the one on its right).
        // getComputedStyle, not :dir(): a browser without :dir() would throw and lose the arrows in both directions.
        self::assertStringContainsString("const step = KEYS[event.key] * (horizontal(event.key) && getComputedStyle(tab).direction === 'rtl' ? -1 : 1);", $tabs);
        self::assertStringNotContainsString(':dir(', $tabs);
    }

    /**
     * The section form uses the same tabs: its column has an empty key (the tab is not remembered, so the dialog opens
     * on the name), and in the Sections screen's dialog it arrives through htmx after the module ran, so the module
     * sets up every column not ready yet once htmx has settled new content.
     */
    public function testTheSectionFormsTabsWorkInTheDialogWithoutMemory(): void
    {
        $tabs = (string) file_get_contents(self::ROOT . '/resources/admin/assets/tabs.js');
        $form = (string) file_get_contents(self::ROOT . '/resources/admin/templates/terms/tabs.php');

        foreach ([
            "document.querySelectorAll('[data-tabs]:not(.admin-tabs-ready)')",
            "document.addEventListener('htmx:afterSettle', setUpAll);",
            "const remembered = key === '' ? -1 : tabs.findIndex((tab) => tab.dataset.tab === read(key));",
            "if (key !== '') {\n            store(key, tabs[index].dataset.tab);\n        }",
        ] as $code) {
            self::assertStringContainsString($code, $tabs, $code);
        }
        foreach (['data-tabs=""', 'role="tablist"', 'data-errors=', 'admin-tab-panel-open', 'class="admin-tab-heading"'] as $hook) {
            self::assertStringContainsString($hook, $form, $hook);
        }
    }

    /**
     * Until tabs.js has set up a column, only the panel the server opened shows (no flash of every panel under the tab
     * list); without JavaScript there is no "js" class and every panel shows.
     */
    public function testOnlyTheOpenPanelShowsBeforeTheModuleRuns(): void
    {
        $tabs = (string) file_get_contents(self::ROOT . '/resources/admin/assets/tabs.js');
        $css = (string) file_get_contents(self::ROOT . '/resources/admin/assets/admin.css');

        self::assertStringContainsString('.js .admin-form-column:not(.admin-tabs-ready) > .admin-tab-panel:not(.admin-tab-panel-open) { display: none; }', $css);
        self::assertStringContainsString("column.classList.add('admin-tabs-ready');", $tabs);
        self::assertLessThan(strpos($tabs, "column.classList.add('admin-tabs-ready');"), strpos($tabs, 'select(withErrors >= 0'), 'after the first selection');
    }
}
