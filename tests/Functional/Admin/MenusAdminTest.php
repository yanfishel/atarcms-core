<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\EntryStatus;
use AtarCms\Content\Menu\MenuItem;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Regex;
use Psr\Http\Message\ResponseInterface;

final class MenusAdminTest extends ContentFunctionalTestCase
{
    public function testUsersWhoCannotManageMenusGetNoMenuScreens(): void
    {
        $home = $this->menuItem('main', 'Home', ['url' => '/']);
        $this->actingAs($this->createUser('au@example.com', 'author'));

        self::assertStringNotContainsString('/admin/menus', (string) $this->browser->get('/admin')->getBody());
        foreach (['/admin/menus', '/admin/menus/main', '/admin/menus/ghost'] as $path) {
            self::assertSame(403, $this->browser->get($path)->getStatusCode(), $path);
        }
        $response = $this->browser->post('/admin/menus/main/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => str_repeat('0', 40),
            'tree' => '[]',
        ]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame([null, 0], $this->placement($home));
    }

    public function testShowsTheEscapedTreeOfOneLocationAndLanguage(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $draft = $this->entry('pages', 'Draft <i>', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $note = $this->entry('notes', 'Note', ['fields' => ['text' => 'x']]);
        $home = $this->menuItem('main', 'Home <b>', ['url' => '/']);
        $aboutItem = $this->menuItem('main', '', ['entry' => $about]);
        $draftItem = $this->menuItem('main', 'Soon', ['entry' => $draft, 'parent' => $aboutItem, 'position' => 0]);
        // "notes" has no route, so it can never be linked through the form (MenuService::isLinkableEntry()
        // rejects it); repoint an otherwise valid item directly, as data drift (the collection losing its route
        // after the item was created) would.
        $noteItem = $this->menuItem('main', '', ['entry' => $about]);
        $this->menus()->update($noteItem->id, ['entry_id' => $note->id]);
        $noteItem = $this->menus()->find($noteItem->id) ?? self::fail('The menu item disappeared');
        $this->menuItem('footer', 'Imprint', ['url' => '/imprint']);
        $this->menuItem('main', 'Главная', ['url' => '/ru', 'locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $redirect = $this->browser->get('/admin/menus');
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame('/admin/menus/main', $redirect->getHeaderLine('Location'));

        $response = $this->browser->get('/admin/menus/main');
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(self::navLink('/admin/menus', 'Menus', true), $body);
        self::assertStringContainsString('<h1>Main menu <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>', $body);
        self::assertStringContainsString('<a href="/admin/menus/footer?locale=en">Footer</a>', $body);
        self::assertStringNotContainsString('hreflang=', $body, 'the top bar switches the language');
        self::assertStringContainsString('<li class="admin-tree-item" data-id="' . $home->id . '">', $body);
        self::assertStringContainsString('<span class="admin-tree-label">Home &lt;b&gt;</span>', $body, 'an item has no page of its own: the pencil edits it');
        self::assertStringContainsString('title="Move “Home &lt;b&gt;” up"', $body);
        self::assertMatchesRegularExpression('~<span class="admin-segmented admin-tree-moves" role="group" aria-label="Move">\s*<button type="submit" name="move" value="' . $home->id . ':up" title="[^"]+" aria-label="[^"]+"><svg[^>]*><use href="[^"]+#arrow-up"></use></svg></button>~', $body);
        self::assertStringContainsString('<span class="admin-tree-handle" data-tree-handle title="Drag to move"><svg', $body);
        self::assertMatchesRegularExpression('~<template data-tree-saved><div class="admin-toast admin-toast-success" data-toast><svg[^>]*>.*?</svg><p class="admin-toast-text">Order saved\.</p><button type="button" class="admin-toast-close" data-toast-close>~', $body);
        self::assertMatchesRegularExpression('~<template data-tree-failed><div class="admin-toast admin-toast-error" role="alert" data-toast><svg[^>]*>.*?</svg><div class="admin-toast-body"><p class="admin-toast-text">The order could not be saved\. Reload the page and try again\.</p><button type="button" class="admin-button admin-button-secondary admin-button-small" data-toast-action>Reload the page</button></div><button type="button" class="admin-toast-close" data-toast-close>~', $body);
        self::assertStringNotContainsString('admin-action-bar', $body, 'no status bar: the results are toasts');
        self::assertStringNotContainsString('Save order', $body, 'a drag saves itself');
        self::assertStringNotContainsString('Drag items by the handle', $body);
        self::assertStringNotContainsString('Home <b>', $body);
        self::assertStringContainsString('<span class="admin-tree-label">About</span>', $body);
        self::assertStringContainsString('name="move" value="' . $aboutItem->id . ':indent"', $body);
        self::assertStringContainsString('aria-controls="tree-' . $aboutItem->id . '-items"><span data-tree-count>1 subitem</span>', $body);
        self::assertStringContainsString('data-tree-counts="{&quot;other&quot;:&quot;# subitems&quot;,&quot;one&quot;:&quot;# subitem&quot;}"', $body);
        $draftRow = self::row($body, $draftItem);
        self::assertStringContainsString('<span class="admin-tree-label">Soon</span>', $draftRow);
        self::assertStringContainsString('<span class="admin-muted admin-tree-meta">Draft &lt;i&gt;</span>', $draftRow);
        self::assertStringContainsString('<span class="admin-chip admin-chip-draft">Not shown on the site</span>', $draftRow);
        self::assertStringContainsString('Not shown on the site', self::row($body, $noteItem), 'the "notes" collection has no route, so the entry is never public');
        self::assertStringNotContainsString('Not shown on the site', self::row($body, $home));
        self::assertStringContainsString(
            sprintf(
                'name="tree" value="[{&quot;id&quot;:%d,&quot;children&quot;:[]},{&quot;id&quot;:%d,&quot;children&quot;:[{&quot;id&quot;:%d,&quot;children&quot;:[]}]},{&quot;id&quot;:%d,&quot;children&quot;:[]}]" data-tree-input',
                $home->id,
                $aboutItem->id,
                $draftItem->id,
                $noteItem->id,
            ),
            $body,
        );
        self::assertStringContainsString('data-max-depth="3"', $body);
        self::assertStringNotContainsString('Imprint', $body);
        self::assertStringNotContainsString('Главная', $body);
        self::assertStringContainsString('Главная', (string) $this->browser->get('/admin/menus/main?locale=ru')->getBody());
        self::assertStringContainsString('<h1>Footer <span class="admin-locale-badge" title="Content language: Русский" aria-hidden="true">RU</span>', (string) $this->browser->get('/admin/menus/footer')->getBody());
        self::assertSame(404, $this->browser->get('/admin/menus/ghost')->getStatusCode());
    }

    /**
     * Like the Sections screen: every row ends with Add inside, Edit and Delete, which (like "Add item") open in the
     * screen's dialog; Add inside is an empty slot on an item whose children would be too deep.
     */
    public function testEveryRowAddsInsideEditsAndDeletesInTheDialog(): void
    {
        $top = $this->menuItem('main', 'Top <b>', ['url' => '/']);
        $child = $this->menuItem('main', 'Child', ['url' => '/child', 'parent' => $top, 'position' => 0]);
        $grandchild = $this->menuItem('main', 'Grandchild', ['url' => '/grandchild', 'parent' => $child, 'position' => 0]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/menus/main?locale=en');
        $body = (string) $response->getBody();
        preg_match("~script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'~", $response->getHeaderLine('Content-Security-Policy'), $csp);

        self::assertMatchesRegularExpression(
            '~<span class="admin-row-actions">\s*'
            . '<a class="admin-row-action" href="/admin/menus/main/new\?locale=en&amp;parent=' . $top->id . '" title="Add a subitem to “Top &lt;b&gt;”" aria-label="Add a subitem to “Top &lt;b&gt;”" data-dialog-open data-tree-opens><svg[^>]*><use href="[^"]+#plus"></use></svg></a>\s*'
            . '<a class="admin-row-action" href="/admin/menus/main/' . $top->id . '" title="Edit “Top &lt;b&gt;”" aria-label="Edit “Top &lt;b&gt;”" data-dialog-open><svg[^>]*><use href="[^"]+#pencil"></use></svg></a>\s*'
            . '<a class="admin-row-action admin-row-action-danger" href="/admin/menus/main/' . $top->id . '/delete" title="Delete “Top &lt;b&gt;”" aria-label="Delete “Top &lt;b&gt;”" data-dialog-open><svg[^>]*><use href="[^"]+#trash-2"></use></svg></a>\s*'
            . '</span>~',
            self::row($body, $top),
        );
        self::assertStringContainsString('href="/admin/menus/main/new?locale=en&amp;parent=' . $child->id . '"', self::row($body, $child));
        $deepest = self::row($body, $grandchild);
        self::assertStringNotContainsString('/new?', $deepest, 'a fourth level does not fit');
        self::assertMatchesRegularExpression('~<span class="admin-row-actions">\s*<span class="admin-row-slot"></span>\s*<a class="admin-row-action" href="/admin/menus/main/' . $grandchild->id . '"~', $deepest);
        self::assertMatchesRegularExpression('~<div class="admin-page-actions">\s*<a class="admin-button admin-button-primary" href="/admin/menus/main/new\?locale=en" data-dialog-open><svg[^>]*><use href="[^"]+#plus"></use></svg>Add item</a>\s*</div>\s*</div>~', $body, '"Add item" of this location ends the title row, like on the Sections screen');
        self::assertStringNotContainsString('admin-tree-add', $body, 'nothing below the tree');
        self::assertMatchesRegularExpression('~<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">\s*<div class="admin-dialog-content" data-dialog-content></div>\s*</dialog>~', $body);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/dialog\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($csp[1] ?? '', '~') . '"></script>~', $body);

        $this->menus()->delete($top->id);
        $empty = (string) $this->browser->get('/admin/menus/main?locale=en')->getBody();
        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-primary" href="/admin/menus/main/new\?locale=en" data-dialog-open>\s*<svg~', $empty, 'the empty screen\'s button too');
        self::assertStringContainsString('data-dialog-content', $empty);
    }

    /**
     * The Structure group starts with the sections, the menus come right after them, then the other taxonomies in
     * config order.
     */
    public function testTheSidebarListsMenusRightAfterTheSections(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin')->getBody();
        preg_match_all('~<a class="admin-nav-link" href="(/admin/(?:sections|menus|taxonomies/[a-z]+))"~', $body, $links);

        self::assertSame(['/admin/sections', '/admin/menus', '/admin/taxonomies/topics', '/admin/taxonomies/tags', '/admin/taxonomies/regions'], $links[1]);
        self::assertMatchesRegularExpression('~Structure</[a-z0-9]+>\s*<ul[^>]*>\s*<li[^>]*>\s*<a class="admin-nav-link" href="/admin/sections"~', $body);
    }

    public function testTheTreeScriptIsANonceModuleAndThePageHasNoInlineCode(): void
    {
        $this->menuItem('main', 'Home', ['url' => '/']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/menus/main');
        $body = (string) $response->getBody();
        $csp = Regex::match("~script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'~", $response->getHeaderLine('Content-Security-Policy'));

        self::assertNotEmpty($csp[1] ?? null);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/tree\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($csp[1], '~') . '"></script>~', $body);
        preg_match_all('~<script\b[^>]*>~', $body, $scripts);
        foreach ($scripts[0] as $tag) {
            self::assertTrue(str_contains($tag, ' src="') || $tag === '<script nonce="' . $csp[1] . '">', 'no inline script but the theme bootstrap: ' . $tag);
        }
        self::assertStringNotContainsString('<style', $body);
        self::assertStringNotContainsString(' style=', $body);
    }

    public function testSavesADraggedTree(): void
    {
        $a = $this->menuItem('main', 'A', ['url' => '/a']);
        $b = $this->menuItem('main', 'B', ['url' => '/b']);
        $c = $this->menuItem('main', 'C', ['url' => '/c']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $version = self::version((string) $this->browser->get('/admin/menus/main')->getBody());

        $response = $this->browser->post('/admin/menus/main/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => $version,
            'tree' => sprintf('[{"id":%d,"children":[{"id":%d}]},{"id":%d}]', $c->id, $a->id, $b->id),
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/menus/main?locale=en', $response->getHeaderLine('Location'));
        self::assertSame([null, 0], $this->placement($c));
        self::assertSame([$c->id, 0], $this->placement($a));
        self::assertSame([null, 1], $this->placement($b));
        self::assertStringContainsString('Order saved.', (string) $this->browser->get('/admin/menus/main')->getBody());
        self::assertContains('menu.reordered', $this->auditActions());
    }

    /**
     * tree.js saves a drop in the background: the answer is JSON with the stored tree's new version (no flash, no
     * redirect), which its next save sends. A refused save answers 422 with the reason and changes nothing.
     */
    public function testABackgroundSaveAnswersWithTheNewVersion(): void
    {
        $a = $this->menuItem('main', 'A', ['url' => '/a']);
        $b = $this->menuItem('main', 'B', ['url' => '/b']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $loaded = self::version((string) $this->browser->get('/admin/menus/main')->getBody());
        $save = fn(string $tree, string $version): ResponseInterface => $this->browser->post('/admin/menus/main/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => $version,
            'tree' => $tree,
        ], ['Accept' => 'application/json']);

        $saved = $save(sprintf('[{"id":%d},{"id":%d}]', $b->id, $a->id), $loaded);

        self::assertSame(200, $saved->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $saved->getHeaderLine('Content-Type'));
        $page = (string) $this->browser->get('/admin/menus/main')->getBody();
        self::assertSame(['version' => self::version($page)], json_decode((string) $saved->getBody(), true));
        self::assertStringContainsString('<div class="admin-toasts" data-toasts aria-live="polite"></div>', $page, 'no flash for a background save');
        self::assertSame([[null, 1], [null, 0]], [$this->placement($a), $this->placement($b)]);

        $stale = $save(sprintf('[{"id":%d},{"id":%d}]', $a->id, $b->id), $loaded);
        self::assertSame(422, $stale->getStatusCode());
        self::assertSame(['error' => 'Someone else changed the order after you opened this page, so yours was not saved. Reload the page to see the current order.'], json_decode((string) $stale->getBody(), true));
        self::assertSame([[null, 1], [null, 0]], [$this->placement($a), $this->placement($b)]);

        $again = $save(sprintf('[{"id":%d},{"id":%d}]', $a->id, $b->id), self::version($page));
        self::assertSame(200, $again->getStatusCode());
        self::assertSame([[null, 0], [null, 1]], [$this->placement($a), $this->placement($b)]);

        $tampered = $save(sprintf('[{"id":%d}]', $a->id), self::version((string) $this->browser->get('/admin/menus/main')->getBody()));
        self::assertSame(422, $tampered->getStatusCode());
        self::assertSame(['error' => 'The submitted order does not match the current items. Reload the page and try again.'], json_decode((string) $tampered->getBody(), true));
        self::assertSame([[null, 0], [null, 1]], [$this->placement($a), $this->placement($b)]);
    }

    public function testArrowButtonsWorkWithoutJavaScript(): void
    {
        $a = $this->menuItem('main', 'A', ['url' => '/a']);
        $b = $this->menuItem('main', 'B', ['url' => '/b']);
        $c = $this->menuItem('main', 'C', ['url' => '/c']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame(303, $this->move($b->id . ':indent')->getStatusCode());
        self::assertSame([[null, 0], [$a->id, 0], [null, 1]], [$this->placement($a), $this->placement($b), $this->placement($c)]);
        self::assertSame(303, $this->move($c->id . ':up')->getStatusCode());
        self::assertSame([[null, 1], [null, 0]], [$this->placement($a), $this->placement($c)]);
        self::assertSame(303, $this->move($b->id . ':outdent')->getStatusCode());
        self::assertSame([null, 2], $this->placement($b));
        self::assertSame(422, $this->move('999999:up')->getStatusCode());
        self::assertSame(422, $this->move($a->id . ':sideways')->getStatusCode());
    }

    public function testRejectsTamperedStaleAndTooDeepTreesWithoutChangingAnything(): void
    {
        $a = $this->menuItem('main', 'A', ['url' => '/a']);
        $b = $this->menuItem('main', 'B', ['url' => '/b']);
        $c = $this->menuItem('main', 'C', ['url' => '/c']);
        $d = $this->menuItem('main', 'D', ['url' => '/d']);
        $footer = $this->menuItem('footer', 'F', ['url' => '/f']);
        $russian = $this->menuItem('main', 'R', ['url' => '/r', 'locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $version = self::version((string) $this->browser->get('/admin/menus/main')->getBody());
        $before = $this->db->select('SELECT id, parent_id, position, updated_at FROM menu_items ORDER BY id');
        $all = sprintf('{"id":%d},{"id":%d},{"id":%d},{"id":%d}', $a->id, $b->id, $c->id, $d->id);

        foreach ([
            sprintf('[%s,{"id":%d}]', $all, $footer->id),
            sprintf('[%s,{"id":%d}]', $all, $russian->id),
            sprintf('[{"id":%d}]', $a->id),
            sprintf('[{"id":%d,"children":[{"id":%d}]},{"id":%d},{"id":%d},{"id":%d}]', $a->id, $a->id, $b->id, $c->id, $d->id),
            '[{"id":"1"}]',
            'not json',
        ] as $tree) {
            $response = $this->postOrder($version, ['tree' => $tree]);
            self::assertSame(422, $response->getStatusCode(), $tree);
            self::assertStringContainsString('The submitted order does not match the current items.', (string) $response->getBody(), $tree);
        }

        $deep = $this->postOrder($version, ['tree' => sprintf('[{"id":%d,"children":[{"id":%d,"children":[{"id":%d,"children":[{"id":%d}]}]}]}]', $a->id, $b->id, $c->id, $d->id)]);
        self::assertSame(422, $deep->getStatusCode());
        self::assertStringContainsString('Items can be nested at most 3 levels deep.', (string) $deep->getBody());

        $stale = $this->postOrder($version, ['tree' => sprintf('[%s]', $all), 'version' => sha1('old')]);
        self::assertSame(422, $stale->getStatusCode());
        self::assertStringContainsString('Someone else changed the order after you opened this page.', (string) $stale->getBody());
        self::assertStringContainsString('<li class="admin-tree-item" data-id="' . $a->id . '">', (string) $stale->getBody(), 'the stored tree is shown again');

        $withoutToken = $this->browser->post('/admin/menus/main/order', ['locale' => 'en', 'version' => $version, 'tree' => sprintf('[%s]', $all)]);
        self::assertSame(419, $withoutToken->getStatusCode());

        self::assertSame($before, $this->db->select('SELECT id, parent_id, position, updated_at FROM menu_items ORDER BY id'));
        self::assertNotContains('menu.reordered', $this->auditActions());
    }

    /**
     * Submits the stored tree of the "main" menu with an arrow button, as a browser without JavaScript does.
     */
    private function move(string $move): ResponseInterface
    {
        $body = (string) $this->browser->get('/admin/menus/main')->getBody();
        preg_match('~name="tree" value="([^"]*)"~', $body, $tree);

        return $this->browser->post('/admin/menus/main/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => self::version($body),
            'tree' => html_entity_decode($tree[1] ?? '', ENT_QUOTES | ENT_HTML5),
            'move' => $move,
        ]);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function placement(MenuItem $item): array
    {
        $stored = $this->menus()->find($item->id);

        return [$stored?->parentId, $stored?->position];
    }

    private static function version(string $body): string
    {
        return preg_match('~name="version" value="([0-9a-f]{40})"~', $body, $match) === 1 ? $match[1] : self::fail('No tree version on the page');
    }

    /**
     * The row (handle, label, meta, badge, buttons) of one item in the tree editor.
     */
    private static function row(string $body, MenuItem $item): string
    {
        return preg_match('~data-id="' . $item->id . '">\s*<div class="admin-tree-row">(.*?)</div>~s', $body, $match) === 1
            ? $match[1]
            : self::fail('No row for item #' . $item->id);
    }

    /**
     * @param array<string, string> $data
     */
    private function postOrder(string $version, array $data): ResponseInterface
    {
        return $this->browser->post('/admin/menus/main/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => $version,
            ...$data,
        ]);
    }
}
