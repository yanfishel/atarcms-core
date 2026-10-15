<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Tests\Support\Regex;
use PHPUnit\Framework\TestCase;

final class MediaAssetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheMediaModuleImportsTheVersionedSortableBuildAndNeverInjectsMarkup(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString("import Sortable from './vendor/sortable-1.15.7.esm.js';", $media);
        // "innerHTML" as an htmx swap mode is fine; assigning markup through the DOM is not.
        foreach (['.innerHTML', '.outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $media, $forbidden);
        }
        self::assertFileDoesNotExist(self::ROOT . '/resources/admin/assets/vendor/sortable.esm.js');
    }

    /**
     * A failed upload clears the file input: with JavaScript the submit button is hidden and choosing the same file
     * again must fire "change".
     */
    public function testFailedUploadsAreReportedAndClearTheChosenFiles(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString("'htmx:responseError'", $media);
        self::assertStringContainsString("'htmx:sendError'", $media);
        self::assertStringContainsString('event.detail.successful', $media);
        self::assertStringContainsString("form.querySelector('template[data-upload-failed]')?.content.firstElementChild", $media, 'the failure is a toast cloned from the server');
        $clear = strpos($media, "form.querySelector('input[type=\"file\"]').value = '';");
        self::assertNotFalse($clear);
        self::assertGreaterThan((int) strpos($media, 'function showUploadError('), $clear);
        self::assertLessThan((int) strpos($media, "document.addEventListener('htmx:responseError'"), $clear, 'in showUploadError()');

        // No glob() brace patterns: musl builds of PHP 8.4 (the Alpine images) lack the flag.
        foreach (['index', 'picker'] as $name) {
            $template = self::ROOT . '/resources/admin/templates/media/' . $name . '.php';
            self::assertFileExists($template);
            self::assertStringContainsString("<template data-upload-failed><?= trim(\$this->partial('partials/toast', ['type' => 'error', 'text' => \$this->t('admin.media.upload.failed')])) ?></template>", (string) file_get_contents($template), $template);
        }
    }

    public function testThePickerInsertsAnImageIntoTheRichTextEditor(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString("document.addEventListener('media-pick',", $media);
        self::assertStringContainsString("openPicker('image', event.detail?.locale || '')", $media, 'one image, with the alt texts of the content language');
        self::assertStringContainsString("url.searchParams.set('locale', locale)", $media);
        self::assertStringContainsString("new CustomEvent('media-picked', { detail: { url: file.url, alt: file.alt } })", $media);
        self::assertStringContainsString("const describe = (tile) => ({ name: tile.dataset.name, thumb: tile.dataset.thumb, url: tile.dataset.url, alt: tile.dataset.alt || '' });", $media);
        self::assertStringContainsString("    if (!dialog.open) {
        insert = false;
        selection.clear();", $media, 'a cancelled dialog sends nothing');
    }

    public function testUploadsShowTheirProgress(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString("'htmx:xhr:progress'", $media);
        self::assertStringContainsString('[data-upload-progress]', $media);
        self::assertStringContainsString('Math.max(progress.value', $media, 'the bar never moves backwards');
        self::assertStringContainsString("'htmx:beforeRequest'", $media, 'the bar is reset for each request');
        foreach (['index', 'picker'] as $name) {
            self::assertStringContainsString('data-upload-progress', (string) file_get_contents(self::ROOT . '/resources/admin/templates/media/' . $name . '.php'), $name);
        }
    }

    /**
     * Choosing or removing files updates the media tab's counter, which the server rendered (hidden at 0).
     */
    public function testTheMediaTabCounterFollowsTheChosenFiles(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');
        $form = (string) file_get_contents(self::ROOT . '/resources/admin/templates/entries/form.php');

        self::assertMatchesRegularExpression('~function sync\(widget\) \{\n(?:[^\n]+\n)+    countTab\(widget\);\n\}~', $media, 'every change of a widget goes through sync()');
        self::assertStringContainsString("querySelector('[data-tab-counter]')", $media);
        self::assertStringContainsString("querySelectorAll('[data-tab-count]')", $media);
        self::assertStringContainsString("panel.querySelectorAll('[data-media-chosen] > [data-id]').length", $media, 'as FormTab::$count: the chosen items of the panel');
        self::assertStringContainsString('counter.hidden = count === 0;', $media);
        self::assertStringContainsString('data-tab-counter', $form);
        self::assertStringContainsString('data-tab-count>', $form);
    }

    public function testGalleriesShowNoMainMarker(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');
        $css = (string) file_get_contents(self::ROOT . '/resources/admin/assets/admin.css');

        self::assertStringNotContainsString('admin-media-main', $media, 'picked images get no "Main" marker (a user decision)');
        self::assertStringNotContainsString('mainLabel', $media);
        self::assertStringNotContainsString('admin-media-main', $css);
    }

    public function testPickedItemsCloneTheServerRenderedRemoveButton(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');
        $field = (string) file_get_contents(self::ROOT . '/resources/admin/templates/media/field.php');

        self::assertStringContainsString("widget.querySelector('[data-media-remove-template]')", $media);
        self::assertStringContainsString('.content.firstElementChild.cloneNode(true)', $media, 'the icon comes from the sprite the server links');
        self::assertStringNotContainsString('removeLabel', $media);
        self::assertStringContainsString('<template data-media-remove-template>', $field);
        self::assertStringContainsString('title="<?= $this->e($this->t(\'admin.media.field.remove\')) ?>" aria-label="<?= $this->e($this->t(\'admin.media.field.remove\')) ?>"', $field);
    }

    public function testChosenCardsCropThePreviewAndCarryASmallRemoveButtonInTheCorner(): void
    {
        $css = (string) file_get_contents(self::ROOT . '/resources/admin/assets/admin.css');

        $thumb = Regex::match('/^\.admin-media-thumb \{([^}]*)\}/m', $css);
        self::assertStringContainsString('aspect-ratio: 4 / 3;', $thumb[1]);
        self::assertStringContainsString('object-fit: cover;', $thumb[1]);
        self::assertStringNotContainsString('contain', $thumb[1]);

        $remove = Regex::match('/^\.admin-media-remove \{([^}]*)\}/m', $css);
        foreach (['position: absolute;', 'top: var(--space-2);', 'inset-inline-end: var(--space-2);', 'width: 32px;', 'height: 32px;', 'border-radius: 50%;'] as $declaration) {
            self::assertStringContainsString($declaration, $remove[1], $declaration);
        }
    }

    public function testASuccessfulUploadBecomesAToast(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString("'htmx:afterSettle'", $media);
        self::assertStringContainsString("querySelectorAll('template[data-upload-toast]')", $media);
        self::assertStringContainsString("closest('dialog')?.querySelector('[data-toasts]')", $media, 'a modal dialog covers the page stack');
        self::assertStringContainsString('.append(...template.content.children)', $media, 'the toasts alone, without whitespace, so an emptied stack matches :empty');
        foreach (['library', 'picker'] as $name) {
            $template = (string) file_get_contents(self::ROOT . '/resources/admin/templates/media/' . $name . '.php');
            self::assertStringContainsString('<template data-upload-toast>', $template, $name);
            self::assertStringNotContainsString('admin-alert', $template, $name . ': failures are toasts too');
            self::assertStringContainsString("'type' => 'error', 'text' => \$this->t(\$failure['key'], \$failure['params'])", $template, $name);
        }
    }

    /**
     * The library's column (the page and the picker dialog) is one track that may shrink below its content's
     * min-content width, so at 390px the drop zone, toolbar and grid keep the 16px gutter instead of running to the
     * screen's edge (an implicit grid track is sized to the widest child).
     */
    public function testTheLibraryColumnShrinksToTheScreen(): void
    {
        $css = (string) file_get_contents(self::ROOT . '/resources/admin/assets/admin.css');

        self::assertMatchesRegularExpression('/\n\.admin-media-content \{[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*\}/', $css);
        self::assertMatchesRegularExpression('/\n\.admin-media-results \{[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*\}/', $css);
        self::assertMatchesRegularExpression('/\n\.admin-media-toolbar \{[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*\}/', $css);
        // On a phone the view, order and type menus open from the start of their rows, not off the screen.
        self::assertStringContainsString(".admin-media-toolbar-end > .admin-media-dropdown > .admin-menu-panel,\n    .admin-media-toolbar-row > .admin-media-dropdown > .admin-menu-panel { inset-inline-end: auto; inset-inline-start: 0; }", $css);
    }

    public function testTheIdsInputIsHiddenOnlyForWidgetsThatCanChoose(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertMatchesRegularExpression("~querySelector\('\[data-media-choose\]'\) !== null\) \{\s+widget\.classList\.add\('admin-media-enabled'\)~", $media);
    }

    public function testVendoredFilesKeepTheirExactBytes(): void
    {
        $attributes = (string) file_get_contents(self::ROOT . '/.gitattributes');

        self::assertStringContainsString('/resources/admin/assets/vendor/sortable-1.15.7.esm.js -text', $attributes);
        self::assertStringContainsString('/resources/admin/assets/vendor/sortable.LICENSE -text', $attributes);
    }

    public function testTheMediaTemplatesHaveNoInlineCode(): void
    {
        $files = glob(self::ROOT . '/resources/admin/templates/media/*.php') ?: [];

        self::assertNotEmpty($files);
        foreach ($files as $template) {
            $source = (string) file_get_contents($template);
            self::assertDoesNotMatchRegularExpression('~<script(?![^>]*\ssrc=)~', $source, $template);
            self::assertStringNotContainsString('<style', $source, $template);
            self::assertStringNotContainsString(' style=', $source, $template);
            self::assertStringNotContainsString('hx-on', $source, $template);
        }
    }

    /**
     * The remembered view and picker folder are cookies the server reads (a user decision): the script stores nothing.
     */
    public function testNoLocalStorage(): void
    {
        self::assertStringNotContainsString('localStorage', (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js'));
    }

    /**
     * The picker's selection lives in the script (a Map in click order), survives every swap of the dialog's content
     * and is shown again on the files' checkboxes and in the footer.
     */
    public function testTheSelectionIsMarkedAgainAfterEverySwap(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString('const selection = new Map();', $media);
        self::assertMatchesRegularExpression("~document\\.addEventListener\\('htmx:afterSettle', \\(\\) => \\{\\n    if \\(dialog\\?\\.open\\) \\{\\n        markTiles\\(\\);~", $media);
        self::assertStringContainsString('tile.checked = selection.has(id);', $media, 'the files are checkboxes, as in the library');
        self::assertStringContainsString('tile.disabled = taken.has(id);', $media, 'a gallery\'s files cannot be picked again');
        self::assertStringContainsString("label.textContent = footer.dataset.already;", $media);
        self::assertStringContainsString("[data-media-pick][data-uploaded]", $media, 'uploaded files join the selection');
        self::assertStringContainsString('delete tile.dataset.uploaded;', $media, 'once');
    }

    public function testChooseAppendsInClickOrderAndSkipsDuplicates(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString('const picked = [...selection];', $media);
        self::assertStringContainsString("if (![...list.children].some((item) => item.dataset.id === id)) {\n                list.append(chosenItem(current, id, file));", $media);
        self::assertStringContainsString('(multiple || picked[0][0] !== unchanged)', $media, 'choosing a single field\'s own file again changes nothing');
        self::assertStringContainsString("footer.dataset.add.replace('#', () => String(selection.size))", $media);
        self::assertStringContainsString("footer.dataset.selected.replace('#', () => (multiple ? String(selection.size) : selection.values().next().value.name))", $media);
        self::assertStringContainsString('button.disabled = selection.size === 0;', $media);
    }

    public function testEnterAndDoubleClickChooseInSingleMode(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString("dialog?.addEventListener('dblclick',", $media);
        self::assertStringContainsString("if (tile !== null && !multiple && !tile.disabled) {\n        chooseOnly(tile);", $media);
        self::assertStringContainsString("event.key !== 'Enter'", $media);
        self::assertStringContainsString("if (multiple) {\n        toggle(tile);\n    } else {\n        chooseOnly(tile);", $media);
    }

    public function testCopyLinkUsesTheClipboardAndToasts(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');
        $edit = (string) file_get_contents(self::ROOT . '/resources/admin/templates/media/edit.php');

        self::assertStringContainsString('button.hidden = !navigator.clipboard;', $media);
        self::assertStringContainsString('navigator.clipboard.writeText(button.dataset.copyLink)', $media);
        self::assertStringContainsString("report('template[data-copy-toast]')", $media);
        self::assertStringContainsString("report('template[data-copy-failed]')", $media);
        self::assertStringContainsString('hidden data-copy-link=', $edit, 'without the clipboard there is no button');
        self::assertStringContainsString('<template data-copy-toast>', $edit);
        self::assertStringContainsString('<template data-copy-failed>', $edit);
    }

    /**
     * The toolbar submits itself: any change but the search box, and the search 300 ms after typing once it holds 3+
     * characters or was emptied; a chosen option is cloned into its dropdown's summary.
     */
    public function testTheToolbarSubmitsItselfAndShowsTheChoice(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');
        $css = (string) file_get_contents(self::ROOT . '/resources/admin/assets/admin.css');

        self::assertStringContainsString("if (form === null || input.matches('[data-media-search]')) {", $media);
        self::assertStringContainsString('if (length > 2 || length === 0) {', $media);
        self::assertStringContainsString('setTimeout(() => input.form?.requestSubmit(), 300)', $media);
        self::assertStringContainsString("summary.querySelector('[data-dropdown-current]').replaceChildren(...parts.map((part) => part.cloneNode(true)));", $media);
        self::assertStringContainsString('summary.ariaLabel = name;', $media);
        self::assertStringContainsString('.js .admin-media-apply { display: none; }', $css, 'without JavaScript the toolbar keeps its submit button');
        self::assertStringContainsString('.js .admin-media-bulk:not(.admin-media-bulk-active) { display: none; }', $css, 'without JavaScript the bulk bar stays');
        self::assertStringContainsString("bulk.classList.toggle('admin-media-bulk-active', count > 0);", $media);
    }

    /**
     * "Select all" / "Clear selection" are JavaScript only (rendered hidden, shown through the property), hidden in a
     * single file's picker, and checked again after every swap; the picker's Clear clears the whole selection.
     */
    public function testSelectAllAndClearSelection(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');
        $count = (string) file_get_contents(self::ROOT . '/resources/admin/templates/media/count.php');

        self::assertStringContainsString('hidden data-select-all>', $count);
        self::assertStringContainsString('hidden data-select-none>', $count);
        self::assertStringContainsString('all.hidden = inPicker && !multiple;', $media);
        self::assertStringContainsString('all.disabled = boxes.every((box) => box.checked);', $media);
        self::assertStringContainsString('none.disabled = inPicker ? selection.size === 0 : !boxes.some((box) => box.checked);', $media);
        self::assertStringContainsString('.filter((box) => !box.disabled)', $media, 'files already in the gallery are skipped');
        self::assertMatchesRegularExpression('~function countChecked\(\) \{(?:\n[^\n]*)+?\n    showSelectAll\(\);\n\}~', $media, 'after every swap (countChecked runs on htmx:afterSettle)');
        foreach (['library', 'picker'] as $name) {
            self::assertStringContainsString("partial('media/count'", (string) file_get_contents(self::ROOT . '/resources/admin/templates/media/' . $name . '.php'), $name);
        }
    }

    public function testTheBulkButtonsNeedACheckedFile(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');

        self::assertStringContainsString("document.querySelectorAll('input[form=\"media-bulk\"]:checked').length", $media);
        self::assertStringContainsString('button.disabled = count === 0;', $media);
        self::assertStringContainsString("bulk.dataset.selected.replace('#', () => String(count))", $media);
    }

    public function testChoosingFilesUploadsThemAtOnce(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');
        $css = (string) file_get_contents(self::ROOT . '/resources/admin/assets/admin.css');

        self::assertStringContainsString("document.addEventListener('change'", $media);
        self::assertStringContainsString('form.requestSubmit()', $media, 'a chosen file goes through the same path as a drop');
        self::assertStringContainsString('html.js .admin-dropzone [data-upload-submit] { display: none; }', $css, 'the submit button is hidden only when the script can replace it');
        foreach (['index', 'picker'] as $name) {
            self::assertStringContainsString('data-upload-submit', (string) file_get_contents(self::ROOT . '/resources/admin/templates/media/' . $name . '.php'), $name);
        }
    }

    /**
     * A theme colour preset fills the colour input and turns the colour on, by value and events only; the presets
     * show only with JavaScript.
     */
    public function testAThemeColourPresetFillsTheColourInput(): void
    {
        $media = (string) file_get_contents(self::ROOT . '/resources/admin/assets/media.js');
        $css = (string) file_get_contents(self::ROOT . '/resources/admin/assets/admin.css');

        self::assertStringContainsString("input.closest('[data-color-presets]')?.closest('.admin-color-field')", $media);
        self::assertStringContainsString('color.value = input.value;', $media);
        self::assertStringContainsString("color.dispatchEvent(new Event('input', { bubbles: true }));", $media);
        self::assertStringContainsString('on.checked = true;', $media);
        self::assertStringContainsString('        usePreset(input);', $media, 'on every radio change');
        self::assertStringContainsString(".admin-color-presets { display: none; }\n.js .admin-color-presets { display: inline-block; }", $css);
    }
}
