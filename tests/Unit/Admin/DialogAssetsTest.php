<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class DialogAssetsTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    public function testTheDialogModuleNeverInjectsMarkupOrStyles(): void
    {
        $dialog = (string) file_get_contents(self::ASSETS . '/dialog.js');

        // "innerHTML" as an htmx swap mode is fine; assigning markup through the DOM is not.
        foreach (['.innerHTML', '.outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'fetch(', 'XMLHttpRequest'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $dialog, $forbidden);
        }
    }

    /**
     * A plain click on a data-dialog-open link loads the link's own page into the dialog through htmx and opens it;
     * a click with a modifier key opens the page as usual, a fragment that fails to load opens the full page, and a
     * form of the dialog that fails (an expired session or token) reloads the screen. A swapped fragment (a refused
     * form too) gets the focus on its first invalid input or its autofocus element; closing empties the dialog and
     * focuses the link again.
     */
    public function testLinksOpenTheirPageInTheDialog(): void
    {
        $dialog = (string) file_get_contents(self::ASSETS . '/dialog.js');

        foreach ([
            "target?.closest('a[data-dialog-open]')",
            'event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey',
            "window.htmx.ajax('GET', link.pathname + link.search, { target: content, swap: 'innerHTML' });",
            "document.addEventListener('htmx:afterSettle',",
            'dialog.showModal();',
            "content.querySelector('.admin-field-invalid :is(input, select, textarea)') ?? content.querySelector('[autofocus]')",
            "document.addEventListener('htmx:responseError', fallBack);",
            "document.addEventListener('htmx:sendError', fallBack);",
            'window.location.assign(link.href);',
            'window.location.reload();',
            "target?.closest('[data-dialog-close]') && dialog.contains(target)",
            'content.replaceChildren();',
            'opener?.focus();',
        ] as $code) {
            self::assertStringContainsString($code, $dialog, $code);
        }
    }

    /**
     * One request at a time: while a fragment or a dialog post loads (from htmx:beforeRequest for the dialog's content to
     * the end of its request), further data-dialog-open clicks do nothing.
     */
    public function testClicksWhileLoadingAreIgnored(): void
    {
        $dialog = (string) file_get_contents(self::ASSETS . '/dialog.js');

        self::assertStringContainsString("document.addEventListener('htmx:beforeRequest',", $dialog);
        self::assertStringContainsString("event.detail.xhr.addEventListener('loadend', () => {\n                busy = false;", $dialog, 'the request itself ends it: a form emptied out of a closed dialog sends no htmx events up');
        self::assertMatchesRegularExpression('~event\.preventDefault\(\);\s*if \(busy\) \{\s*return;\s*\}~', $dialog, 'the click is swallowed, not followed');
    }

    /**
     * Closing the dialog while its request runs drops the answer: it is not swapped in (so htmx:afterSettle never
     * opens the dialog again) and a failure does not navigate. HX-Redirect (a finished save) still reloads the screen.
     */
    public function testAnAnswerAfterACloseIsDropped(): void
    {
        $dialog = (string) file_get_contents(self::ASSETS . '/dialog.js');

        self::assertStringContainsString("document.addEventListener('htmx:beforeSwap',", $dialog);
        self::assertStringContainsString('event.detail.shouldSwap = false;', $dialog);
        self::assertMatchesRegularExpression("~addEventListener\\('close', \\(\\) => \\{\\s*dropped = busy;~", $dialog);
        self::assertMatchesRegularExpression('~const fallBack = \(event\) => \{\s*if \(event\.detail\.target !== content \|\| dropped\) \{~', $dialog);
    }
}
