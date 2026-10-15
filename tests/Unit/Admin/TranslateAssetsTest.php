<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * translate.js (Plan 38) follows the admin script rules (no markup, styles, setAttribute, requests or network URLs): it
 * uses only the browser's on-device Translator API behind feature detection, shows the server's hidden controls
 * through the hidden property, clones the panel's toast templates and applies the translation all or nothing. The
 * scripts it talks to: editor.js translates rich text through the model, autosave.js stops, slides.js refreshes rows.
 */
final class TranslateAssetsTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    private const string TEMPLATES = __DIR__ . '/../../../resources/admin/templates';

    public function testTheModuleNeverInjectsMarkupStylesOrRequests(): void
    {
        $module = self::module();

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'htmx.', 'import ', 'localStorage'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, $forbidden);
        }
        self::assertStringContainsString('template.content.firstElementChild.cloneNode(true)', $module, 'toasts are clones of the server\'s templates');
    }

    public function testItUsesOnlyTheOnDeviceTranslatorBehindFeatureDetection(): void
    {
        $module = self::module();

        self::assertStringContainsString("typeof globalThis.Translator === 'function'", $module);
        self::assertStringContainsString("const SHOWN = new Set(['available', 'downloadable', 'downloading']);", $module, '"unavailable" shows nothing');
        self::assertStringContainsString('globalThis.Translator.availability({ sourceLanguage: bcp47(from), targetLanguage: bcp47(to) })', $module);
        self::assertStringContainsString("monitor.addEventListener('downloadprogress', (event) => {", $module, 'the download shows its progress');
        self::assertStringContainsString("run.addEventListener('click', translate);", $module, 'the button is the user gesture a download needs');
        self::assertStringContainsString("const start = panel.hasAttribute('data-machine-start');", $module);
        self::assertStringContainsString("if (state === 'available' && start) {", $module, 'it starts by itself only when chosen and already on the device');
        self::assertStringContainsString('button.hidden = !SHOWN.has(state);', $module);
        self::assertStringContainsString('panel.hidden = false;', $module);
        self::assertStringContainsString("return String(code).trim().replace(/_/g, '-').toLowerCase();", $module, 'site codes are BCP 47 tags');
    }

    public function testItAppliesTheTranslationAllOrNothingAndStopsAutosaveFirst(): void
    {
        $module = self::module();

        $machine = strpos($module, "form.dispatchEvent(new CustomEvent('machine-translated', { bubbles: true }));");
        self::assertNotFalse($machine);
        $applied = (string) substr($module, $machine, (int) strpos($module, 'run.hidden = true;') - $machine);
        self::assertStringNotContainsString('await', $applied, 'every text is translated before the first change');
        self::assertLessThan((int) strpos($module, 'control.value = values[index];'), $machine, 'autosave hears "machine-translated" before the first change');
        self::assertStringContainsString("textarea.dispatchEvent(new CustomEvent('rich-text-translate', { detail }));", $module);
        self::assertStringContainsString("control.dispatchEvent(new Event('input', { bubbles: true }));", $module);
        self::assertStringContainsString("toast(panel.querySelector('template[data-machine-failed]'));", $module);
        self::assertStringContainsString('job.release();', $module, 'a failure unlocks the editors without applying');
        self::assertStringContainsString("const SLIDE_TEXTS = ['title', 'subtitle', 'button'];", $module, 'a slide\'s link is never translated');
    }

    public function testTheReviewWaveRules(): void
    {
        $module = self::module();

        self::assertStringContainsString("document.addEventListener('htmx:afterSettle', () => showOffers());", $module, 'the term dialog\'s machine segment arrives through htmx');
        self::assertStringContainsString('control.readOnly = true;', $module, 'nothing typed during the translation is overwritten');
        self::assertStringContainsString('control.readOnly = locked.get(control);', $module, 'restored afterwards');
        self::assertStringContainsString("dropQuery('start');", $module, 'Back or F5 never starts it again');
        self::assertStringContainsString("dropQuery('machine', 'start');", $module, 'nor shows the panel after a translation');
        self::assertStringContainsString("history.replaceState(history.state, '', url);", $module);
        $editors = (int) strpos($module, 'job.apply();');
        self::assertLessThan((int) strpos($module, 'control.value = values[index];'), $editors, 'the editors first: a failing one leaves the plain fields alone');
        self::assertStringContainsString('job.revert();', $module, 'an editor applied before a failing one is undone');
        self::assertStringContainsString("window.addEventListener('beforeunload', (event) => {", $module, 'the term form asks before leaving unsaved translated texts');

        $editor = (string) file_get_contents(self::ASSETS . '/editor.js');
        self::assertStringContainsString('const html = editor.data.stringify(block);', $editor, 'a block is translated as a whole, with its inline markup');
        self::assertStringContainsString('const fragment = editor.data.parse(translated, block);', $editor, 'parsed back by the editor\'s data pipeline, never through innerHTML');
        self::assertStringContainsString('if (sameMarkup(block, fragment)) {', $editor, 'the translation may not add or change markup or addresses');
        self::assertStringContainsString("editor.execute('undo', batch);", $editor);
    }

    public function testTheTextFilterKeepsAddressesAndNumbers(): void
    {
        $module = self::module();

        self::assertStringContainsString("if (value === '' || !/\\p{L}/u.test(value)) {", $module, 'numbers, dates and phones have no letter');
        self::assertStringContainsString('/^[a-z][a-z0-9+.-]*:\/\/\S*$/i.test(value)', $module, 'addresses');
        self::assertStringContainsString('!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)', $module, 'e-mail addresses');
        self::assertStringContainsString('const [, lead, core, trail] = /^(\s*)([\s\S]*?)(\s*)$/.exec(String(text));', $module, 'the white space around a text stays');
    }

    public function testTheOtherScriptsAnswerIt(): void
    {
        $editor = (string) file_get_contents(self::ASSETS . '/editor.js');
        self::assertStringContainsString("textarea.addEventListener('rich-text-translate', (event) => {", $editor);
        self::assertStringContainsString("if (item.is('element') && item.name !== 'codeBlock' && [...item.getChildren()].some((child) => child.is('\$text'))) {", $editor, 'blocks of text, never a code block');
        self::assertStringContainsString("const parts = texts.filter((text) => !text.hasAttribute('code')).map((text) => ({", $editor, 'never inline code');
        self::assertStringContainsString('editor.enableReadOnlyMode(TRANSLATION_LOCK);', $editor, 'nobody edits while the positions are kept');
        self::assertStringContainsString('return { apply, revert, release };', $editor, 'applied by translate.js, all fields or none');
        self::assertStringContainsString('writer.insertText(part.text, part.attributes, start);', $editor, 'the fallback keeps the attributes of each text');

        $autosave = (string) file_get_contents(self::ASSETS . '/autosave.js');
        self::assertMatchesRegularExpression("~form\.addEventListener\('machine-translated', \(\) => \{\s*stopped = true;~", $autosave);

        $slides = (string) file_get_contents(self::ASSETS . '/slides.js');
        self::assertStringContainsString("const input = event.target instanceof Element ? event.target.closest('[data-slides-input]') : null;", $slides);
    }

    public function testThePanelTemplateHoldsNoInlineCodeAndIsHidden(): void
    {
        $template = (string) file_get_contents(self::TEMPLATES . '/partials/machine-translation.php');
        foreach (['style=', '<style', '<script', 'hx-on', 'innerHTML'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $template, $forbidden);
        }
        self::assertStringContainsString(' hidden>', $template);
    }

    private static function module(): string
    {
        return (string) file_get_contents(self::ASSETS . '/translate.js');
    }
}
