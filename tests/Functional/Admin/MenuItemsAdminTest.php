<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Content\Menu\MenuItem;
use AtarCms\Content\Menu\MenuTarget;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;

final class MenuItemsAdminTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        // Manages menus and may view pages, but not articles.
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'menu_editor' => ['label' => 'Menu editor', 'permissions' => ['menus.manage', 'entries.pages.view']],
        ]));
    }

    public function testEveryItemRouteNeedsMenusManageAndAnItemOfTheLocation(): void
    {
        $home = $this->menuItem('main', 'Home', ['url' => '/']);
        $imprint = $this->menuItem('footer', 'Imprint', ['url' => '/imprint']);
        $this->actingAs($this->createUser('au@example.com', 'author'));

        foreach (['/admin/menus/main/new', '/admin/menus/main/' . $home->id, '/admin/menus/main/' . $home->id . '/delete'] as $path) {
            self::assertSame(403, $this->browser->get($path)->getStatusCode(), $path);
        }
        $form = ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => '2026-09-28 12:00:00', 'label' => 'Hacked', 'target_type' => 'url', 'url' => '/x'];
        foreach (['/admin/menus/main', '/admin/menus/main/' . $home->id, '/admin/menus/main/' . $home->id . '/delete'] as $path) {
            self::assertSame(403, $this->browser->post($path, $form)->getStatusCode(), $path);
        }

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $form['_csrf'] = $this->csrfToken();
        foreach (['/admin/menus/ghost/new', '/admin/menus/main/' . $imprint->id, '/admin/menus/main/999999', '/admin/menus/main/' . $imprint->id . '/delete'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
        self::assertSame(404, $this->browser->post('/admin/menus/main/' . $imprint->id, $form)->getStatusCode());
        self::assertSame(404, $this->browser->post('/admin/menus/main/' . $imprint->id . '/delete', $form)->getStatusCode());
        self::assertSame(404, $this->browser->post('/admin/menus/ghost', $form)->getStatusCode());

        self::assertSame(['Home', 'Imprint'], [$this->menus()->find($home->id)?->label, $this->menus()->find($imprint->id)?->label]);
        self::assertSame(2, Cast::int($this->db->scalar('SELECT COUNT(*) FROM menu_items')));
    }

    public function testCreatesAnEntryItemFromEscapedPickers(): void
    {
        $hello = $this->entry('articles', 'Hello <b>', ['slug' => 'hello']);
        $this->entry('articles', 'Привет', ['slug' => 'privet', 'locale' => 'ru']);
        $php = $this->term('topics', 'PHP & Co');
        $this->term('regions', 'North');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/menus/main/new?locale=en')->getBody();
        self::assertStringContainsString('<input type="hidden" name="locale" value="en">', $form);
        self::assertStringContainsString('<optgroup label="Articles">', $form);
        self::assertStringContainsString('<option value="' . $hello->id . '">Hello &lt;b&gt;</option>', $form);
        self::assertStringNotContainsString('Привет', $form);
        self::assertStringNotContainsString('<optgroup label="notes">', $form, 'notes have no route');
        self::assertStringContainsString('<optgroup label="Topics">', $form);
        self::assertStringContainsString('<option value="' . $php->id . '">PHP &amp; Co</option>', $form);
        self::assertStringNotContainsString('North', $form, 'regions have no route');
        self::assertStringContainsString('<input type="radio" name="target_type" value="entry" checked>', $form);
        foreach (['entry_id' => 'entry', 'term_id' => 'term', 'url' => 'url'] as $field => $type) {
            self::assertMatchesRegularExpression('~<div class="admin-field" data-target="' . $type . '">\s*<label for="' . $field . '">~', $form, $field);
        }

        $response = $this->browser->post('/admin/menus/main', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => '',
            'label' => '',
            'target_type' => 'entry',
            'entry_id' => (string) $hello->id,
            'term_id' => (string) $php->id,
            'url' => 'javascript:ignored',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/menus/main?locale=en', $response->getHeaderLine('Location'));
        $item = $this->menus()->forLocation('main', 'en')[0] ?? self::fail('No item stored');
        self::assertSame([MenuTarget::Entry, $hello->id, null, null, ''], [$item->target, $item->entryId, $item->termId, $item->url, $item->label]);
        $tree = (string) $this->browser->get('/admin/menus/main')->getBody();
        self::assertStringContainsString('Menu item created.', $tree);
        self::assertStringContainsString('<span class="admin-tree-label">Hello &lt;b&gt;</span>', $tree);
        self::assertStringContainsString('href="/admin/menus/main/new?locale=en"', $tree);
        $actions = $this->auditActions();
        self::assertSame('menu_item.created', end($actions));
    }

    public function testRejectsUnsafeUrlsAndUnknownTargetsAndReRendersEscaped(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:alert(1)', "java\tscript:alert(1)", '&#106;avascript:alert(1)', 'data:text/html,x', '//evil.example'] as $url) {
            $response = $this->browser->post('/admin/menus/main', [
                '_csrf' => $this->csrfToken(),
                'locale' => 'en',
                'version' => '',
                'label' => 'Evil <script>',
                'target_type' => 'url',
                'url' => $url,
            ]);
            $body = (string) $response->getBody();

            self::assertSame(422, $response->getStatusCode(), $url);
            self::assertStringContainsString('Enter an http(s) address, a path starting with “/”, an #anchor, or a mailto: or tel: link.', $body, $url);
            self::assertStringContainsString('value="Evil &lt;script&gt;"', $body);
            self::assertStringNotContainsString('Evil <script>', $body);
        }

        $unknown = $this->browser->post('/admin/menus/main', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'label' => 'X', 'target_type' => 'ftp', 'url' => '/x']);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertStringContainsString('Choose what the item links to.', (string) $unknown->getBody());
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM menu_items')));
    }

    public function testNewlyChosenEntriesMustBeViewableButTheLinkedEntryIsKept(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $news = $this->menuItem('main', 'News', ['entry' => $hello]);
        $this->actingAs($this->createUser('me@example.com', 'menu_editor'));

        $new = (string) $this->browser->get('/admin/menus/main/new')->getBody();
        self::assertStringContainsString('<optgroup label="pages">', $new);
        self::assertStringNotContainsString('<optgroup label="Articles">', $new);

        $forged = $this->browser->post('/admin/menus/main', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'label' => 'X',
            'target_type' => 'entry',
            'entry_id' => (string) $hello->id,
        ]);
        self::assertSame(422, $forged->getStatusCode());
        self::assertStringContainsString('Choose an existing entry of this language with a public address.', (string) $forged->getBody());
        self::assertStringNotContainsString('Hello', (string) $forged->getBody());

        $edit = (string) $this->browser->get('/admin/menus/main/' . $news->id)->getBody();
        self::assertStringContainsString('<option value="' . $hello->id . '" selected>#' . $hello->id . '</option>', $edit);
        self::assertStringNotContainsString('>Hello<', $edit);

        $saved = $this->browser->post('/admin/menus/main/' . $news->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'label' => 'Latest news',
            'target_type' => 'entry',
            'entry_id' => (string) $hello->id,
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame(['Latest news', $hello->id], [$this->menus()->find($news->id)?->label, $this->menus()->find($news->id)?->entryId]);

        $page = $this->browser->post('/admin/menus/main', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'label' => '',
            'target_type' => 'entry',
            'entry_id' => (string) $about->id,
        ]);
        self::assertSame(303, $page->getStatusCode());
    }

    /**
     * The "Inside" select, like the sections': the top level, then the items of the same menu and language in tree
     * order (an item without its own label by its target's title), leaving out those too deep to hold the item.
     * "Add inside" (?parent=) preselects one; the new item is appended after its last child. A refused parent (another
     * menu or language, gone, too deep) is an error at the select, which then shows the top level, so the next submit
     * goes through.
     */
    public function testAddsAnItemInsideAnother(): void
    {
        $about = $this->entry('pages', 'About <i>', ['slug' => 'about']);
        $top = $this->menuItem('main', 'Top <b>', ['url' => '/']);
        $first = $this->menuItem('main', '', ['entry' => $about, 'parent' => $top, 'position' => 0]);
        $deep = $this->menuItem('main', 'Deep', ['url' => '/deep', 'parent' => $first, 'position' => 0]);
        $footer = $this->menuItem('footer', 'Imprint', ['url' => '/imprint']);
        $russian = $this->menuItem('main', 'Главная', ['url' => '/ru', 'locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/menus/main/new?locale=en&parent=' . $top->id)->getBody();
        self::assertStringContainsString('<h1>Add a subitem to “Top &lt;b&gt;”', $form);
        self::assertMatchesRegularExpression(
            '~<label for="parent_id"><svg[^>]*>.*?</svg>Inside</label>\s*<select id="parent_id" name="parent_id">\s*'
            . '<option value="">— top level —</option>\s*'
            . '<option value="' . $top->id . '" selected>Top &lt;b&gt;</option>\s*'
            . '<option value="' . $first->id . '">— About &lt;i&gt;</option>\s*'
            . '</select>~s',
            $form,
            'Deep (third level) cannot hold an item',
        );
        self::assertLessThan((int) strpos($form, 'id="label"'), (int) strpos($form, 'id="parent_id"'), '"Inside" is the first field');
        self::assertStringNotContainsString('Imprint', $form);
        self::assertStringNotContainsString('Главная', $form);
        foreach ([(string) $footer->id, (string) $russian->id, '999999', 'x'] as $parent) {
            $page = (string) $this->browser->get('/admin/menus/main/new?locale=en&parent=' . $parent)->getBody();
            self::assertStringNotContainsString(' selected>', self::select($page), $parent);
            self::assertStringContainsString('<h1>Add item', $page, $parent);
        }

        $post = ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => '', 'label' => 'Second', 'target_type' => 'url', 'url' => '/second'];
        $created = $this->browser->post('/admin/menus/main', [...$post, 'parent_id' => (string) $top->id]);
        self::assertSame(303, $created->getStatusCode());
        self::assertSame('/admin/menus/main?locale=en', $created->getHeaderLine('Location'));
        $second = array_values(array_filter($this->menus()->forLocation('main', 'en'), static fn(MenuItem $item): bool => $item->label === 'Second'))[0] ?? self::fail('Not created');
        self::assertSame([$top->id, 1], [$second->parentId, $second->position]);

        $wrong = 'Choose an item of this menu and language, other than this item and the items inside it.';
        $doomed = $this->menuItem('main', 'Doomed', ['url' => '/doomed']);
        $this->menus()->delete($doomed->id);
        foreach ([[$footer->id, $wrong], [$russian->id, $wrong], [$doomed->id, $wrong], [$deep->id, 'Items can be nested at most 3 levels deep.']] as [$id, $message]) {
            $parent = (string) $id;
            $refused = (string) $this->browser->post('/admin/menus/main', [...$post, 'label' => 'Forged', 'parent_id' => $parent])->getBody();
            self::assertStringContainsString('<div class="admin-alert" role="alert">', $refused, $parent);
            self::assertMatchesRegularExpression('~<div class="admin-field admin-field-invalid">\s*<label for="parent_id">.*?<span class="admin-field-error" id="parent_id-error">' . preg_quote($message, '~') . '</span>~s', $refused, $parent);
            self::assertStringNotContainsString(' selected>', self::select($refused), 'the select falls back to the top level: ' . $parent);
            self::assertStringContainsString('<h1>Add item', $refused);
        }
        self::assertSame(6, Cast::int($this->db->scalar('SELECT COUNT(*) FROM menu_items')));

        // What the re-rendered select submits (its first option) goes through, at the top level.
        $resubmitted = $this->browser->post('/admin/menus/main', [...$post, 'label' => 'Orphan', 'parent_id' => '']);
        self::assertSame(303, $resubmitted->getStatusCode());
        $orphan = array_values(array_filter($this->menus()->forLocation('main', 'en'), static fn(MenuItem $item): bool => $item->label === 'Orphan'))[0] ?? self::fail('Not created');
        self::assertNull($orphan->parentId);
    }

    /**
     * Editing offers neither the item nor its subitems, nor a place where its subitems would be too deep; a new parent
     * moves the item (with its subitems) to the end of its children, or of the top level; forged ones are refused at
     * the select.
     */
    public function testEditMovesAnItemUnderAnotherParent(): void
    {
        $news = $this->menuItem('main', 'News', ['url' => '/news']);
        $archive = $this->menuItem('main', 'Archive', ['url' => '/archive', 'parent' => $news, 'position' => 0]);
        $about = $this->menuItem('main', 'About', ['url' => '/about', 'position' => 1]);
        $team = $this->menuItem('main', 'Team', ['url' => '/team', 'parent' => $about, 'position' => 0]);
        $jobs = $this->menuItem('main', 'Jobs', ['url' => '/jobs', 'position' => 2]);
        $openings = $this->menuItem('main', 'Openings', ['url' => '/openings', 'parent' => $jobs, 'position' => 0]);
        $footer = $this->menuItem('footer', 'Imprint', ['url' => '/imprint']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $select = self::select((string) $this->browser->get('/admin/menus/main/' . $jobs->id)->getBody());
        self::assertStringContainsString('<option value="">— top level —</option>', $select);
        self::assertStringContainsString('<option value="' . $news->id . '">News</option>', $select);
        self::assertStringContainsString('<option value="' . $about->id . '">About</option>', $select);
        self::assertStringNotContainsString('Jobs', $select, 'not itself');
        self::assertStringNotContainsString('Openings', $select, 'nor its subitems');
        self::assertStringNotContainsString('Archive', $select, 'Openings would reach a fourth level');
        self::assertStringContainsString('<option value="' . $about->id . '" selected>About</option>', self::select((string) $this->browser->get('/admin/menus/main/' . $team->id)->getBody()));

        $form = ['_csrf' => $this->csrfToken(), 'version' => '2026-09-28 12:00:00', 'label' => 'Jobs', 'target_type' => 'url', 'url' => '/jobs'];
        $moved = $this->browser->post('/admin/menus/main/' . $jobs->id, [...$form, 'parent_id' => (string) $about->id]);
        self::assertSame(303, $moved->getStatusCode());
        $stored = $this->menus()->find($jobs->id);
        self::assertSame([$about->id, 1], [$stored?->parentId, $stored?->position], 'last among About\'s children');
        self::assertSame($jobs->id, $this->menus()->find($openings->id)?->parentId);

        $back = $this->browser->post('/admin/menus/main/' . $team->id, [...$form, 'label' => 'Team', 'url' => '/team', 'parent_id' => '']);
        self::assertSame(303, $back->getStatusCode());
        self::assertSame([null, 2], [$this->menus()->find($team->id)?->parentId, $this->menus()->find($team->id)?->position], 'last at the top level (News, About)');

        $wrong = 'Choose an item of this menu and language, other than this item and the items inside it.';
        foreach ([[$about->id, $wrong], [$jobs->id, $wrong], [$footer->id, $wrong], [$archive->id, 'Items can be nested at most 3 levels deep.']] as [$id, $message]) {
            $version = $this->menus()->find($about->id)?->updatedAt->format('Y-m-d H:i:s') ?? '';
            $refused = $this->browser->post('/admin/menus/main/' . $about->id, [...$form, 'version' => $version, 'label' => 'About', 'url' => '/about', 'parent_id' => (string) $id]);
            self::assertSame(422, $refused->getStatusCode(), (string) $id);
            self::assertStringContainsString('<span class="admin-field-error" id="parent_id-error">' . $message . '</span>', (string) $refused->getBody(), (string) $id);
        }
        self::assertNull($this->menus()->find($about->id)?->parentId);
    }

    /**
     * Requested by htmx (HX-Request), the item pages answer with the dialog's fragment. Field errors come back into the
     * dialog (200: htmx swaps no error response); a save, a delete and a refusal without a field send the browser to
     * the menu screen of the item's language, with the flash as a toast.
     */
    public function testTheDialogAddsEditsAndDeletesItems(): void
    {
        $top = $this->menuItem('main', 'Top <b>', ['url' => '/']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $htmx = ['HX-Request' => 'true'];

        $new = (string) $this->browser->get('/admin/menus/main/new?locale=en&parent=' . $top->id, $htmx)->getBody();
        self::assertStringNotContainsString('<html', $new);
        self::assertStringContainsString('<h2 id="dialog-title">Add a subitem to “Top &lt;b&gt;”', $new);
        self::assertStringContainsString('<form class="admin-dialog-form" method="post" action="/admin/menus/main" hx-post="/admin/menus/main" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">', $new);
        self::assertStringContainsString('<option value="' . $top->id . '" selected>Top &lt;b&gt;</option>', $new);
        self::assertStringContainsString('<input type="radio" name="target_type" value="entry" checked>', $new);
        self::assertStringContainsString('<button type="button" class="admin-button admin-button-secondary" data-dialog-close>Cancel</button>', $new);

        $post = ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => '', 'target_type' => 'url', 'parent_id' => (string) $top->id];
        $refused = $this->browser->post('/admin/menus/main', [...$post, 'label' => 'Evil', 'url' => 'javascript:alert(1)'], $htmx);
        self::assertSame(200, $refused->getStatusCode());
        self::assertSame('', $refused->getHeaderLine('HX-Redirect'));
        self::assertStringNotContainsString('<html', (string) $refused->getBody());
        self::assertStringContainsString('<div class="admin-field admin-field-invalid" data-target="url">', (string) $refused->getBody());
        self::assertStringContainsString('<div class="admin-alert" role="alert">', (string) $refused->getBody());
        self::assertStringContainsString('<option value="' . $top->id . '" selected>Top &lt;b&gt;</option>', (string) $refused->getBody(), 'a field error keeps the valid parent');

        $created = $this->browser->post('/admin/menus/main', [...$post, 'label' => 'Child', 'url' => '/child'], $htmx);
        self::assertSame(200, $created->getStatusCode());
        self::assertSame('/admin/menus/main?locale=en', $created->getHeaderLine('HX-Redirect'));
        $child = array_values(array_filter($this->menus()->forLocation('main', 'en'), static fn(MenuItem $item): bool => $item->label === 'Child'))[0] ?? self::fail('Not created');
        self::assertSame($top->id, $child->parentId);
        self::assertStringContainsString('Menu item created.', (string) $this->browser->get('/admin/menus/main')->getBody());

        $edit = (string) $this->browser->get('/admin/menus/main/' . $child->id, $htmx)->getBody();
        self::assertStringContainsString('<h2 id="dialog-title">Child', $edit);
        self::assertStringContainsString('hx-post="/admin/menus/main/' . $child->id . '"', $edit);
        self::assertStringContainsString('<option value="' . $top->id . '" selected>Top &lt;b&gt;</option>', $edit, 'its parent is chosen');
        $edited = ['_csrf' => $this->csrfToken(), 'target_type' => 'url', 'url' => '/child', 'parent_id' => (string) $top->id];
        $stale = $this->browser->post('/admin/menus/main/' . $child->id, [...$edited, 'label' => 'Stale', 'version' => 'old'], $htmx);
        self::assertSame('/admin/menus/main?locale=en', $stale->getHeaderLine('HX-Redirect'), 'a refusal without a field reloads the screen');
        self::assertMatchesRegularExpression('~admin-toast-error.*Someone else saved this menu item after you opened it\.~s', (string) $this->browser->get('/admin/menus/main')->getBody());
        $saved = $this->browser->post('/admin/menus/main/' . $child->id, [...$edited, 'label' => 'Renamed', 'version' => '2026-09-28 12:00:00'], $htmx);
        self::assertSame('/admin/menus/main?locale=en', $saved->getHeaderLine('HX-Redirect'));
        self::assertSame('Renamed', $this->menus()->find($child->id)?->label);

        $confirm = (string) $this->browser->get('/admin/menus/main/' . $top->id . '/delete', $htmx)->getBody();
        self::assertStringNotContainsString('<html', $confirm);
        self::assertStringContainsString('<h2 id="dialog-title">Delete menu item</h2>', $confirm);
        self::assertStringContainsString('Delete “Top &lt;b&gt;” (English) and its 1 subitem?', $confirm);
        self::assertStringContainsString('hx-post="/admin/menus/main/' . $top->id . '/delete" hx-target="closest [data-dialog-content]"', $confirm);
        self::assertStringContainsString('data-dialog-close autofocus>Cancel</button>', $confirm);
        $deleted = $this->browser->post('/admin/menus/main/' . $top->id . '/delete', ['_csrf' => $this->csrfToken()], $htmx);
        self::assertSame(200, $deleted->getStatusCode());
        self::assertSame('/admin/menus/main?locale=en', $deleted->getHeaderLine('HX-Redirect'));
        self::assertSame([], $this->menus()->forLocation('main', 'en'));
        self::assertStringContainsString('Menu item deleted.', (string) $this->browser->get('/admin/menus/main')->getBody());
    }

    /**
     * From the dialog a refused parent is a field error like any other: the fragment comes back with it at the select.
     */
    public function testTheDialogShowsARefusedParentAtTheSelect(): void
    {
        $top = $this->menuItem('main', 'Top', ['url' => '/']);
        $child = $this->menuItem('main', 'Child', ['url' => '/child', 'parent' => $top, 'position' => 0]);
        $deep = $this->menuItem('main', 'Deep', ['url' => '/deep', 'parent' => $child, 'position' => 0]);
        $footer = $this->menuItem('footer', 'Imprint', ['url' => '/imprint']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $htmx = ['HX-Request' => 'true'];
        $post = ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => '', 'label' => 'Forged', 'target_type' => 'url', 'url' => '/forged'];

        foreach ([
            [$footer->id, 'Choose an item of this menu and language, other than this item and the items inside it.'],
            [$deep->id, 'Items can be nested at most 3 levels deep.'],
        ] as [$id, $message]) {
            $refused = $this->browser->post('/admin/menus/main', [...$post, 'parent_id' => (string) $id], $htmx);
            $body = (string) $refused->getBody();
            self::assertSame(200, $refused->getStatusCode(), (string) $id);
            self::assertSame('', $refused->getHeaderLine('HX-Redirect'), (string) $id);
            self::assertStringNotContainsString('<html', $body);
            self::assertStringContainsString('<span class="admin-field-error" id="parent_id-error">' . $message . '</span>', $body, (string) $id);
            self::assertStringContainsString('<select id="parent_id" name="parent_id" aria-invalid="true" aria-describedby="parent_id-error">', $body);
        }
        self::assertSame(4, Cast::int($this->db->scalar('SELECT COUNT(*) FROM menu_items')));
    }

    /**
     * The "Inside" select of a form page or fragment.
     */
    private static function select(string $body): string
    {
        return preg_match('~<select id="parent_id"[^>]*>(.*?)</select>~s', $body, $match) === 1 ? $match[1] : self::fail('No parent select');
    }

    public function testEditsWithAStaleCheckAndDeletesWithSubitems(): void
    {
        $parent = $this->menuItem('main', 'Parent <b>', ['url' => 'https://example.com', 'newTab' => true]);
        $child = $this->menuItem('main', 'Child', ['url' => '/child', 'parent' => $parent, 'position' => 0]);
        $this->menuItem('main', 'Grandchild', ['url' => '/grandchild', 'parent' => $child, 'position' => 0]);
        $other = $this->menuItem('main', 'Other', ['url' => '/other']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/menus/main/' . $parent->id)->getBody();
        self::assertStringContainsString('<input type="hidden" name="version" value="2026-09-28 12:00:00">', $form);
        self::assertStringContainsString('value="Parent &lt;b&gt;"', $form);
        self::assertStringContainsString('<input type="radio" name="target_type" value="url" checked>', $form);
        self::assertStringContainsString('value="https://example.com"', $form);
        self::assertStringContainsString('<input type="checkbox" name="new_tab" value="1" checked>', $form);

        $this->clock->advance('+1 minute');
        $saved = $this->browser->post('/admin/menus/main/' . $parent->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'label' => 'Parent <i>',
            'target_type' => 'url',
            'url' => '/parent',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $stored = $this->menus()->find($parent->id);
        self::assertSame(['Parent <i>', '/parent', false], [$stored?->label, $stored?->url, $stored?->newTab]);
        self::assertStringContainsString('Changes saved.', (string) $this->browser->get('/admin/menus/main')->getBody());

        $stale = $this->browser->post('/admin/menus/main/' . $parent->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'label' => 'Overwrite',
            'target_type' => 'url',
            'url' => '/overwrite',
        ]);
        self::assertSame(422, $stale->getStatusCode());
        $staleBody = (string) $stale->getBody();
        self::assertMatchesRegularExpression('~admin-toast-error.*Someone else saved this menu item after you opened it\.~s', $staleBody, 'a refusal without a field is a toast');
        self::assertStringNotContainsString('class="admin-alert"', $staleBody);
        self::assertSame('Parent <i>', $this->menus()->find($parent->id)?->label);

        $confirm = (string) $this->browser->get('/admin/menus/main/' . $parent->id . '/delete')->getBody();
        self::assertStringContainsString('Delete “Parent &lt;i&gt;” (English) and its 2 subitems?', $confirm);

        $deleted = $this->browser->post('/admin/menus/main/' . $parent->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/menus/main?locale=en', $deleted->getHeaderLine('Location'));
        self::assertSame([$other->id], array_map(static fn(MenuItem $item): int => $item->id, $this->menus()->forLocation('main', 'en')));
        self::assertStringContainsString('Menu item deleted.', (string) $this->browser->get('/admin/menus/main')->getBody());
        $actions = $this->auditActions();
        self::assertSame(['menu_item.updated', 'menu_item.deleted'], array_slice($actions, -2));
    }
}
