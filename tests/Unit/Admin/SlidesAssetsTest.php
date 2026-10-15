<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * slides.js follows the admin script rules (no markup, styles, setAttribute, requests or network URLs): it edits the
 * slides widget's own inputs, clones the server's <template> for a new slide and tells the entry form about changes.
 */
final class SlidesAssetsTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    private const string TEMPLATES = __DIR__ . '/../../../resources/admin/templates';

    public function testTheModuleNeverInjectsMarkupStylesOrRequests(): void
    {
        $module = (string) file_get_contents(self::ASSETS . '/slides.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'htmx'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, $forbidden);
        }
        self::assertStringContainsString("import Sortable from './vendor/sortable-1.15.7.esm.js';", $module, 'the vendored SortableJS, like tree.js');
        self::assertStringContainsString('.content.firstElementChild.cloneNode(true)', $module, 'a new slide is cloned from the server\'s template');
        self::assertStringContainsString("new Event('change', { bubbles: true })", $module, 'autosave.js hears every change');
        self::assertStringContainsString('dialog.showModal()', $module);
        self::assertStringContainsString("handle: '[data-slides-handle]'", $module);
        self::assertStringContainsString('.htmlFor = ', $module, 'labels follow the new ids through properties');
        self::assertStringContainsString("'admin-media-video'", $module, 'the chip built for a slide\'s saved video is the same player card');
        self::assertStringContainsString("dialog.querySelector('[data-slides-media=\"video\"] video')?.pause();", $module, 'a closed dialog stops the preview');
        self::assertStringContainsString("const MEDIA = ['image', 'video'];", $module, 'the picture and the optional video go through the same steps');
        self::assertStringContainsString("'[data-slides-media=\"' + part + '\"] [data-media-field]'", $module, 'each media widget of the dialog is found by its part');
        self::assertStringContainsString('marker.ariaLabel = ', $module, 'the row\'s video marker is named through properties');
    }

    public function testTheDialogPicksTheVideoFromTheVideosAndTheRowMarksIt(): void
    {
        $dialog = (string) file_get_contents(self::TEMPLATES . '/slides/dialog.php');
        $item = (string) file_get_contents(self::TEMPLATES . '/slides/item.php');

        self::assertStringContainsString("'kind' => 'video'", $dialog);
        self::assertStringContainsString("'kind' => 'image'", $dialog);
        self::assertStringContainsString('data-slides-input="video"', $item, 'the no-JS video number');
        self::assertStringContainsString('class="admin-slides-video" data-slides-video role="img"', $item, 'a labelled icon is role="img" with aria-label, never an SVG <title>');
    }

    public function testTheTemplatesUseTheSharedDialogLayoutAndNoInlineCode(): void
    {
        $dialog = (string) file_get_contents(self::TEMPLATES . '/slides/dialog.php');
        $widget = (string) file_get_contents(self::TEMPLATES . '/fields/slides.php');
        $item = (string) file_get_contents(self::TEMPLATES . '/slides/item.php');

        self::assertStringContainsString('<div class="admin-dialog-header">', $dialog);
        self::assertStringContainsString("\$this->partial('partials/dialog-close')", $dialog);
        self::assertStringContainsString('<div class="admin-dialog-body">', $dialog);
        self::assertStringContainsString('<div class="admin-dialog-footer">', $dialog);
        self::assertStringContainsString("\$this->partial('media/field'", $dialog, 'the library picker for the image');
        self::assertStringNotContainsString('<textarea', $dialog, 'a textarea invites line breaks, which copying into the row\'s single-line input would silently drop');
        self::assertStringNotContainsString('<video', $dialog, 'the player lives in the chosen chip (media/field.php and the scripts build it), not in the dialog itself');
        $field = (string) file_get_contents(self::TEMPLATES . '/media/field.php');
        self::assertStringContainsString('<video class="admin-media-video" controls preload="metadata" src=', $field, 'a chosen video\'s card is a player');
        foreach ([$dialog, $widget, $item] as $template) {
            foreach (['style=', '<style', '<script', 'hx-on', 'innerHTML'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $template, $forbidden);
            }
        }
    }
}
