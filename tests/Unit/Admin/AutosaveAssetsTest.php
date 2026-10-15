<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * autosave.js follows the admin script rules (no markup, styles, setAttribute or network URLs) and talks only to the
 * address the server put in the form; the editors and media widgets report their changes to it.
 */
final class AutosaveAssetsTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    public function testTheModuleNeverInjectsMarkupOrStylesAndCallsOnlyTheFormsAutosaveAddress(): void
    {
        $module = (string) file_get_contents(self::ASSETS . '/autosave.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'sendBeacon'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, $forbidden);
        }
        self::assertSame(1, substr_count($module, 'fetch('), 'one request site');
        self::assertStringContainsString('const response = await fetch(url, {', $module);
        self::assertStringContainsString('let url = form.dataset.autosave ?? null;', $module);
        self::assertStringContainsString("headers: { Accept: 'application/json' },", $module);
        self::assertStringContainsString('body: new FormData(form),', $module, 'the form\'s own CSRF field and version');
        self::assertStringContainsString('keepalive,', $module);
        self::assertStringContainsString("failed.content.firstElementChild.cloneNode(true)", $module, 'toasts are cloned from the server\'s template');
        self::assertStringContainsString('new Intl.PluralRules(', $module);
        self::assertStringContainsString("window.history.replaceState(window.history.state, '', json.edit);", $module);
        self::assertStringContainsString("preview.formAction = json.preview;", $module, 'a property, not setAttribute');
        self::assertStringContainsString("new CustomEvent('entry-form-sync', { cancelable: true })", $module);
        self::assertStringContainsString("window.addEventListener('beforeunload',", $module);
        self::assertStringContainsString("document.addEventListener('visibilitychange',", $module);
        self::assertStringContainsString("window.addEventListener('pagehide',", $module);
        self::assertStringContainsString("return checked === null || checked.value === 'draft';", $module, 'only a draft autosaves');
        self::assertStringContainsString('preview.formTarget = json.previewTarget;', $module, 'a created entry previews in its own tab');
        self::assertStringContainsString('signal: AbortSignal.timeout(REQUEST_TIMEOUT),', $module, 'a held manual save never hangs');
        self::assertStringContainsString("} else if (response.status === 419 || response.redirected) {", $module, 'the login page of an expired session');
        self::assertStringContainsString('if (dirty && !leaving && (lastFailed || !active())) {', $module, 'unsaved invalid drafts ask before leaving');
        self::assertStringContainsString('if (event.persisted && url !== null && ((leaveSaved && !dirty) || stale)) {', $module);
        self::assertMatchesRegularExpression('/const saved = \(json, keepalive\) => \{\n(?:[^\n]+\n)*?\s+shown\?\.remove\(\);/', $module, 'a success removes the last error toast');
    }

    public function testEditorsAndMediaWidgetsReportTheirChanges(): void
    {
        $editor = (string) file_get_contents(self::ASSETS . '/editor.js');
        $media = (string) file_get_contents(self::ASSETS . '/media.js');

        self::assertStringContainsString("form.addEventListener('entry-form-sync', (event) => flush(event, true));", $editor);
        self::assertStringContainsString("textarea.dispatchEvent(new Event('input', { bubbles: true }));", $editor);
        self::assertStringContainsString("input.dispatchEvent(new Event('change', { bubbles: true }));", $media);
    }
}
