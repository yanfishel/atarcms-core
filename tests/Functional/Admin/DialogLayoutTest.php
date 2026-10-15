<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Media\MediaFolderService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * Every admin modal has one layout (a user rule): a header with the title and the close button, the body, and a
 * footer with the actions; header and footer are separated by lines and stay, only the body scrolls. The dialogs of
 * dialog.js (an empty shell the fragments fill) and the media picker share the classes.
 */
final class DialogLayoutTest extends ContentFunctionalTestCase
{
    private const string CLOSE = '<form class="admin-dialog-close" method="dialog"><button type="submit" class="admin-button admin-button-link admin-button-icon" title="Close" aria-label="Close"><svg[^>]*><use href="[^"]+#x"></use></svg></button></form>';

    public function testTheDialogShellsAreEmptyContainersForTheFragments(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'admin'));

        foreach (['/admin/sections?locale=en', '/admin/media', '/admin/menus/main?locale=en', '/admin/users'] as $page) {
            self::assertMatchesRegularExpression(
                '~<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">\s*<div class="admin-dialog-content" data-dialog-content></div>\s*</dialog>~',
                (string) $this->browser->get($page)->getBody(),
                $page,
            );
        }
    }

    public function testEveryDialogFragmentHasAHeaderABodyAndAFooter(): void
    {
        $guides = $this->term('sections', 'Guides');
        $folder = $this->app->container()->service(MediaFolderService::class)->create('Photos', null, null, null);
        $home = $this->menuItem('main', 'Home', ['url' => '/']);
        $bob = $this->createUser('bob@example.com', 'editor');
        $this->actingAs($this->createUser('ann@example.com', 'admin'));
        $htmx = ['HX-Request' => 'true'];

        foreach ([
            '/admin/sections/new?locale=en' => 'Add section',
            '/admin/sections/' . $guides->id => 'Guides',
            '/admin/sections/' . $guides->id . '/delete' => 'Delete',
            '/admin/taxonomies/tags/new' => 'Add new · Tags',
            '/admin/media/folders/new' => 'New folder',
            '/admin/media/folders/' . $folder->id => 'Rename or move',
            '/admin/media/folders/' . $folder->id . '/delete' => 'Delete folder',
            '/admin/menus/main/new?locale=en' => 'Add item',
            '/admin/menus/main/' . $home->id => 'Home',
            '/admin/menus/main/' . $home->id . '/delete' => 'Delete menu item',
            '/admin/users/new' => 'Add user',
            '/admin/users/' . $bob->id => 'bob@example.com',
            '/admin/users/' . $bob->id . '/delete' => 'Delete user',
        ] as $url => $title) {
            $fragment = (string) $this->browser->get($url, $htmx)->getBody();
            self::assertMatchesRegularExpression(
                '~^\s*<div class="admin-dialog-header">\s*<h2 id="dialog-title">' . preg_quote($title, '~') . '(?: <span class="admin-locale-badge"[^>]*>[A-Z]+</span><span class="admin-visually-hidden">[^<]*</span>)?</h2>.*?' . self::CLOSE . '\s*</div>\s*'
                . '<form class="admin-dialog-form" method="post" action="[^"]+" hx-post="[^"]+" hx-target="closest \[data-dialog-content\]" hx-swap="innerHTML">.*?'
                . '<div class="admin-dialog-body">.*?</div>\s*<div class="admin-dialog-footer">.*?<button type="submit"[^>]*>.*?</div>\s*</form>\s*$~s',
                $fragment,
                $url,
            );
            self::assertSame(1, substr_count($fragment, 'class="admin-dialog-body"'), $url);
        }
    }

    public function testThePickerHasTheSameLayout(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/stories/new')->getBody();

        self::assertMatchesRegularExpression(
            '~<dialog class="admin-dialog admin-media-dialog" data-media-dialog data-picker-url="/admin/media/picker" aria-labelledby="media-dialog-title">\s*'
            . '<div class="admin-dialog-header">\s*<h2 id="media-dialog-title">Choose a file</h2>\s*' . self::CLOSE . '\s*</div>\s*'
            . '<div class="admin-dialog-body admin-media-dialog-body" data-media-dialog-body></div>\s*'
            . '<div class="admin-toasts" data-toasts aria-live="polite"></div>\s*'
            . '<div class="admin-dialog-footer" data-picker-footer ~',
            $body,
        );
    }

    /**
     * The shared rules: lines between the parts, only the body scrolls; no sticky actions inside a scrolling body.
     */
    public function testTheStylesheetGivesEveryDialogTheSameColumn(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../resources/admin/assets/admin.css');

        foreach ([
            '.admin-dialog[open] { display: flex; flex-direction: column; }',
            '.admin-dialog-content, .admin-dialog-form { display: flex; flex: 1 1 auto; flex-direction: column; min-height: 0; margin: 0; }',
            '.admin-dialog-header { position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); min-height: calc(var(--control-height) + 2 * var(--space-3)); padding-block: var(--space-3); padding-inline: var(--space-4) calc(var(--control-height) + 2 * var(--space-3)); border-bottom: 1px solid var(--border); }',
            '.admin-dialog-close { position: absolute; top: var(--space-3); inset-inline-end: var(--space-3); margin: 0; }',
            '.admin-dialog-body { display: grid; flex: 1 1 auto; align-content: start; gap: var(--space-4); min-height: 0; overflow: auto; padding: var(--space-4); }',
            '.admin-dialog-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2); margin: 0; padding: var(--space-3) var(--space-4); border-top: 1px solid var(--border); }',
        ] as $rule) {
            self::assertStringContainsString($rule, $css, $rule);
        }
        foreach (['admin-dialog-actions', 'admin-media-dialog-header', 'admin-media-dialog-footer', '.admin-media-dialog[open]'] as $gone) {
            self::assertStringNotContainsString($gone, $css, $gone);
        }
    }
}
