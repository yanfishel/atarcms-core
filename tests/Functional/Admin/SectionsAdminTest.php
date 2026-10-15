<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Auth\UserRepository;
use AtarCms\Content\Collections;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Container;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;

/**
 * The Sections screen with the fixture's sections and a main menu of them with "welcome" (-1) and "stories" (-2)
 * pinned ('main_menu' => ['welcome', 'stories']).
 */
final class SectionsAdminTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $config = $container->service(Config::class);
        $config = new Config([...$config->all(), 'content' => [...$config->array('content'), 'main_menu' => ['welcome', 'stories']]]);
        $container->set(Config::class, $config);
        $container->set(MainMenu::class, MainMenu::fromConfig($config, $container->service(Collections::class)));
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'organizer' => ['label' => 'Organizer', 'permissions' => ['taxonomies.manage']],
            'reader' => ['label' => 'Reader', 'permissions' => ['taxonomies.manage', 'entries.welcome.view']],
        ]));
    }

    /**
     * The pinned items are labelled like the public main menu: in the language of the sections shown (the content
     * language), not in the interface language.
     */
    public function testThePinnedItemsSpeakTheContentLanguage(): void
    {
        $ann = $this->createUser('ann@example.com', 'editor');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        $english = (string) $this->browser->get('/admin/sections?locale=en')->getBody();
        self::assertStringContainsString('<html lang="ru"', $english, 'a Russian interface');
        self::assertMatchesRegularExpression('~<li class="admin-tree-item" data-id="-1" data-tree-pinned>.*?>Welcome</~s', $english);
        self::assertDoesNotMatchRegularExpression('~data-id="-1" data-tree-pinned>(?:(?!</li>).)*Добро пожаловать~su', $english, 'the sidebar keeps the interface language; the tree does not');

        $russian = (string) $this->browser->get('/admin/sections?locale=ru')->getBody();
        self::assertMatchesRegularExpression('~<li class="admin-tree-item" data-id="-1" data-tree-pinned>.*?>Добро пожаловать</~s', $russian);
    }

    public function testTheScreenShowsTheSectionsWithThePinnedItems(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP <b>', ['parent' => $guides]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $page = (string) $this->browser->get('/admin/sections?locale=en')->getBody();

        self::assertMatchesRegularExpression(self::navLink('/admin/sections', 'Sections', true), $page);
        self::assertStringNotContainsString('/admin/taxonomies/sections', $page);
        self::assertStringContainsString('<a class="admin-button admin-button-primary" href="/admin/sections/new?locale=en" data-dialog-open>', $page);
        self::assertStringContainsString('<li class="admin-tree-item" data-id="-1" data-tree-pinned>', $page);
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/welcome/new?locale=en">Welcome</a>', $page, 'a single without its entry yet: "Add new"');
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/stories?locale=en">Stories</a>', $page, 'an archive: its list');
        self::assertStringNotContainsString('admin-tree-meta', $page, 'one line per item');
        self::assertStringContainsString('name="move" value="-1:down"', $page);
        self::assertStringNotContainsString('name="move" value="-1:indent"', $page, 'a pinned item only moves up and down');
        $level = '“Welcome” always stays at the top level of the menu.';
        self::assertMatchesRegularExpression('~<button type="submit" name="move" value="-1:down" title="[^"]+" aria-label="[^"]+"><svg[^>]*>.*?</svg></button>\s*<button type="button" class="admin-locked" aria-disabled="true" title="' . $level . '" aria-label="Move “Welcome” out of its parent" aria-describedby="tree--1-outdent-reason"><svg[^>]*><use href="[^"]+#arrow-left"></use></svg></button><span class="admin-visually-hidden" id="tree--1-outdent-reason">' . $level . '</span>\s*<button type="button" class="admin-locked" aria-disabled="true" title="' . $level . '" aria-label="Move “Welcome” into the item above" aria-describedby="tree--1-indent-reason"><svg[^>]*><use href="[^"]+#arrow-right"></use></svg></button><span class="admin-visually-hidden" id="tree--1-indent-reason">' . $level . '</span>~', $page, 'its other two moves keep their places, locked with the reason, so the columns line up');
        $pinned = '“Welcome” is always in the menu. To change its content, open it by its name.';
        self::assertMatchesRegularExpression('~<span class="admin-row-actions">\s*<button type="button" class="admin-row-action admin-locked" aria-disabled="true" title="' . $pinned . '" aria-label="Order the articles of “Welcome”" aria-describedby="tree--1-order-reason"><svg[^>]*><use href="[^"]+#arrow-down-wide-narrow"></use></svg></button><span class="admin-visually-hidden" id="tree--1-order-reason">' . $pinned . '</span>\s*<button type="button" class="admin-row-action admin-locked" aria-disabled="true" title="' . $pinned . '" aria-label="Add a subsection to “Welcome”" aria-describedby="tree--1-add-reason"><svg[^>]*><use href="[^"]+#plus"></use></svg></button><span class="admin-visually-hidden" id="tree--1-add-reason">' . $pinned . '</span>\s*<button type="button" class="admin-row-action admin-locked" aria-disabled="true" title="' . $pinned . '" aria-label="Edit “Welcome”" aria-describedby="tree--1-edit-reason"><svg[^>]*><use href="[^"]+#pencil"></use></svg></button><span class="admin-visually-hidden" id="tree--1-edit-reason">' . $pinned . '</span>\s*<button type="button" class="admin-row-action admin-row-action-danger admin-locked" aria-disabled="true" title="' . $pinned . '" aria-label="Delete “Welcome”" aria-describedby="tree--1-delete-reason"><svg[^>]*><use href="[^"]+#trash-2"></use></svg></button><span class="admin-visually-hidden" id="tree--1-delete-reason">' . $pinned . '</span>\s*</span>~', $page, 'locked actions stay in the Tab order (aria-disabled, never disabled) and name their reason');
        self::assertDoesNotMatchRegularExpression('~<button[^>]* disabled[ >]~', $page, 'the server disables nothing: a locked button stays reachable, tree.js disables the useless moves');
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/stories?locale=en&amp;term=' . $php->id . '">PHP &lt;b&gt;</a>', $page, 'a section: the stories filtered by it');
        self::assertMatchesRegularExpression('~<span class="admin-row-actions">\s*<a class="admin-row-action" href="/admin/sections/' . $php->id . '/order" title="Order the articles of “PHP &lt;b&gt;”" aria-label="Order the articles of “PHP &lt;b&gt;”"><svg[^>]*><use href="[^"]+#arrow-down-wide-narrow"></use></svg></a>\s*<a class="admin-row-action" href="/admin/sections/new\?locale=en&amp;parent=' . $php->id . '" title="Add a subsection to “PHP &lt;b&gt;”" aria-label="Add a subsection to “PHP &lt;b&gt;”" data-dialog-open data-tree-opens><svg[^>]*><use href="[^"]+#plus"></use></svg></a>\s*<a class="admin-row-action" href="/admin/sections/' . $php->id . '" title="Edit “PHP &lt;b&gt;”" aria-label="Edit “PHP &lt;b&gt;”" data-dialog-open><svg[^>]*><use href="[^"]+#pencil"></use></svg></a>\s*<a class="admin-row-action admin-row-action-danger" href="/admin/sections/' . $php->id . '/delete" title="Delete “PHP &lt;b&gt;”" aria-label="Delete “PHP &lt;b&gt;”" data-dialog-open><svg[^>]*><use href="[^"]+#trash-2"></use></svg></a>\s*</span>~', $page, 'the same four slots in the same order on every row');
        self::assertMatchesRegularExpression('~<button type="button" class="admin-row-action admin-row-action-danger admin-locked" aria-disabled="true" title="Delete or move the items inside it first\." aria-label="Delete “Guides”" aria-describedby="tree-' . $guides->id . '-delete-reason"><svg[^>]*><use href="[^"]+#trash-2"></use></svg></button><span class="admin-visually-hidden" id="tree-' . $guides->id . '-delete-reason">Delete or move the items inside it first\.</span>~', $page, 'a section with subsections cannot be deleted');
        self::assertStringNotContainsString('href="/admin/sections/' . $guides->id . '/delete"', $page);
        self::assertMatchesRegularExpression('~<span class="admin-tree-text">\s*<a class="admin-tree-label" href="[^"]*term=' . $guides->id . '">Guides</a>\s*<button type="button" class="admin-tree-toggle" data-tree-toggle aria-expanded="false" aria-controls="tree-' . $guides->id . '-items"><span data-tree-count>1 subsection</span><svg[^>]*><use href="[^"]+#chevron-right"></use></svg></button>\s*</span>\s*<span class="admin-tree-controls">\s*<span class="admin-segmented admin-tree-moves"~', $page, 'the toggle is a text button at the end of the label line (right before the moves on a wide screen, opposite the name on a phone), its visible text its name');
        self::assertStringContainsString('<ol class="admin-tree" data-tree-list id="tree-' . $guides->id . '-items">', $page);
        self::assertStringContainsString('aria-controls="tree-' . $php->id . '-items"><span data-tree-count>0 subsections</span>', $page, 'a leaf has it too (not drawn until it has children)');
        self::assertMatchesRegularExpression('~<span class="admin-tree-handle" data-tree-handle title="Drag to move"><svg[^>]*>.*?</svg></span>\s*<span class="admin-tree-text">\s*<a class="admin-tree-label" href="/admin/content/welcome/new\?locale=en">~', $page, 'nothing between the handle and the label');
        self::assertMatchesRegularExpression('~<span class="admin-tree-handle" data-tree-handle title="Drag to move"><svg[^>]*>.*?</svg></span>\s*<span class="admin-tree-text">\s*<a class="admin-tree-label" href="/admin/content/stories\?locale=en&amp;term=' . $guides->id . '">~', $page);
        self::assertSame(2, substr_count($page, 'data-tree-toggle'), 'none on the pinned items');
        self::assertStringNotContainsString('Show or hide', $page, 'no name besides the visible text');
        self::assertStringContainsString('data-tree-counts="{&quot;other&quot;:&quot;# subsections&quot;,&quot;one&quot;:&quot;# subsection&quot;}"', $page, 'the plural forms of the interface language, for tree.js');
        self::assertMatchesRegularExpression('~data-tree-input>\s*<script src="/admin-assets/tree-open\.js\?v=[0-9a-f]{12}" nonce="[^"]+"></script>\s*<ol class="admin-tree" data-tree-list>~', $page, 'the top level has no id; tree-open.js (classic, nonce\'d) right before it');
        self::assertStringContainsString('name="move" value="' . $php->id . ':outdent"', $page);
        self::assertSame(404, $this->browser->get('/admin/taxonomies/sections')->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/taxonomies/sections/' . $guides->id)->getStatusCode());
        self::assertSame(200, $this->browser->get('/admin/taxonomies/tags')->getStatusCode());
    }

    /**
     * The number of subsections speaks the interface language with its plural forms (Russian has three for whole
     * numbers), on the server and, through data-tree-counts, in tree.js after a drag.
     */
    public function testTheNumberOfSubsectionsIsPluralisedInTheInterfaceLanguage(): void
    {
        $parents = [1 => $this->term('sections', 'Один'), 2 => $this->term('sections', 'Два'), 5 => $this->term('sections', 'Пять')];
        foreach ($parents as $count => $parent) {
            for ($i = 1; $i <= $count; ++$i) {
                $this->term('sections', $parent->name . ' ' . $i, ['parent' => $parent]);
            }
        }
        $ann = $this->createUser('ann@example.com', 'editor');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        $page = (string) $this->browser->get('/admin/sections?locale=en')->getBody();

        foreach ([1 => '1 подраздел', 2 => '2 подраздела', 5 => '5 подразделов'] as $count => $text) {
            self::assertStringContainsString('aria-controls="tree-' . $parents[$count]->id . '-items"><span data-tree-count>' . $text . '</span>', $page);
        }
        self::assertStringContainsString('data-tree-counts="{&quot;many&quot;:&quot;# подразделов&quot;,&quot;one&quot;:&quot;# подраздел&quot;,&quot;few&quot;:&quot;# подраздела&quot;}"', $page);
    }

    /**
     * Delete is disabled up front for a section the server would refuse to delete, per language: one that stories
     * take their address from, one with subsections; the server still refuses such a delete.
     */
    public function testDeleteIsDisabledForSectionsThatCannotBeDeleted(): void
    {
        $guides = $this->term('sections', 'Guides');
        $notes = $this->term('sections', 'Notes');
        $empty = $this->term('sections', 'Empty');
        $notesRu = $this->term('sections', 'Заметки', ['locale' => 'ru']);
        $this->term('sections', 'Sub', ['parent' => $guides]);
        $this->entry('stories', 'Hello', ['terms' => ['sections' => [$notes->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $english = (string) $this->browser->get('/admin/sections?locale=en')->getBody();
        self::assertMatchesRegularExpression('~<button type="button" class="admin-row-action admin-row-action-danger admin-locked" aria-disabled="true" title="Delete or move the items inside it first\." aria-label="Delete “Guides”" aria-describedby="tree-' . $guides->id . '-delete-reason">~', $english);
        self::assertMatchesRegularExpression('~<button type="button" class="admin-row-action admin-row-action-danger admin-locked" aria-disabled="true" title="Entries take their web address from this item\. Move them to another item first\." aria-label="Delete “Notes”" aria-describedby="tree-' . $notes->id . '-delete-reason">~', $english);
        self::assertStringContainsString('href="/admin/sections/' . $empty->id . '/delete" title="Delete “Empty”"', $english);
        $russian = (string) $this->browser->get('/admin/sections?locale=ru')->getBody();
        self::assertStringContainsString('href="/admin/sections/' . $notesRu->id . '/delete"', $russian, 'the stories of another language do not count');

        $refused = $this->browser->post('/admin/sections/' . $notes->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $refused->getStatusCode(), 'the server guard stays');
        self::assertNotNull($this->terms()->find($notes->id));
    }

    /**
     * "Add a subsection" opens the new-section form with the row's section chosen as the parent, in the dialog or,
     * without JavaScript, as the full page. Only a section of this list and language is taken; anything else leaves
     * the parent empty. The form needs "taxonomies.manage" like the screen.
     */
    public function testAddSubsectionPreselectsTheParent(): void
    {
        $guides = $this->term('sections', 'Guides');
        $russian = $this->term('sections', 'Гайды', ['locale' => 'ru']);
        $topic = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $selected = '<option value="' . $guides->id . '" selected>Guides</option>';

        $dialog = (string) $this->browser->get('/admin/sections/new?locale=en&parent=' . $guides->id, ['HX-Request' => 'true'])->getBody();
        self::assertStringContainsString('<h2 id="dialog-title">Add section <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>', $dialog);
        self::assertStringContainsString($selected, $dialog);
        self::assertStringContainsString($selected, (string) $this->browser->get('/admin/sections/new?locale=en&parent=' . $guides->id)->getBody(), 'the full page without JavaScript');

        foreach ([(string) $russian->id, (string) $topic->id, '999999', 'x', '-1'] as $parent) {
            $page = (string) $this->browser->get('/admin/sections/new?locale=en&parent=' . $parent)->getBody();
            self::assertStringNotContainsString(' selected>', $page, $parent);
        }

        $this->actingAs($this->createUser('au@example.com', 'author'));
        self::assertSame(403, $this->browser->get('/admin/sections/new?locale=en&parent=' . $guides->id)->getStatusCode());
    }

    /**
     * The heading names the language with its pill (the top bar switches it) and ends with "Add section", as on the other
     * list screens, still a plain link to the full form for users without JavaScript.
     */
    public function testTheHeaderNamesTheLanguageAndEndsWithAddSection(): void
    {
        $this->term('sections', 'Guides');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $page = (string) $this->browser->get('/admin/sections?locale=en')->getBody();

        self::assertMatchesRegularExpression('~<div class="admin-page-header">\s*<h1>Sections <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span><span class="admin-visually-hidden">\(Content language: English\)</span></h1>\s*'
            . '<div class="admin-page-actions">\s*<a class="admin-button admin-button-primary" href="/admin/sections/new\?locale=en" data-dialog-open><svg[^>]*><use href="[^"]+#plus"></use></svg>Add section</a>\s*</div>\s*</div>~', $page, '"Add section" ends the title row, as on the other list screens');
        self::assertStringNotContainsString('admin-switch', $page);
        self::assertStringNotContainsString('admin-list-switch', $page);
        self::assertStringNotContainsString('admin-tree-add', $page, 'nothing below the tree');
        self::assertSame(1, substr_count($page, '>Add section</a>'));
    }

    /**
     * The names lead to the content where the user may see it: a single's entry in the screen's language (else the
     * default one's form, which offers the translation); without the right to see the content a name is plain text,
     * while Edit and Delete stay (the screen itself needs "taxonomies.manage").
     */
    public function testTheNamesLeadToTheContentTheUserMaySee(): void
    {
        $guides = $this->term('sections', 'Guides');
        $welcome = $this->entry('welcome', 'Welcome', ['fields' => ['intro' => '<p>Hi</p>']]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $english = (string) $this->browser->get('/admin/sections?locale=en')->getBody();
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/welcome/' . $welcome->id . '">Welcome</a>', $english);
        $russian = (string) $this->browser->get('/admin/sections?locale=ru')->getBody();
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/welcome/' . $welcome->id . '?translate=ru">Добро пожаловать</a>', $russian, 'labelled in the content language; no Russian entry yet: the English form offers the translation');
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/stories?locale=ru">Stories</a>', $russian);

        $this->actingAs($this->createUser('org@example.com', 'organizer'));
        $page = (string) $this->browser->get('/admin/sections?locale=en')->getBody();
        self::assertStringContainsString('<span class="admin-tree-label">Welcome</span>', $page);
        self::assertStringContainsString('<span class="admin-tree-label">Stories</span>', $page);
        self::assertStringContainsString('<span class="admin-tree-label">Guides</span>', $page);
        self::assertStringContainsString('href="/admin/sections/' . $guides->id . '" title="Edit “Guides”"', $page);
        self::assertSame(403, $this->browser->get('/admin/content/stories?term=' . $guides->id)->getStatusCode());
    }

    /**
     * A pinned single the user may view but neither create nor edit has no form to open: its name is plain text,
     * before and after its entry exists.
     */
    public function testAPinnedSingleTheUserMayOnlyViewIsPlainText(): void
    {
        $this->actingAs($this->createUser('reader@example.com', 'reader'));
        self::assertStringContainsString('<span class="admin-tree-label">Welcome</span>', (string) $this->browser->get('/admin/sections?locale=en')->getBody(), 'no entry yet, and no right to create it');

        $this->entry('welcome', 'Welcome', ['fields' => ['intro' => '<p>Hi</p>']]);
        self::assertStringContainsString('<span class="admin-tree-label">Welcome</span>', (string) $this->browser->get('/admin/sections?locale=en')->getBody(), 'the entry exists, but may not be edited');
    }

    public function testTheArrowsSaveTheOrderWithoutJavaScript(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/sections/order', [...$this->treeForm(), 'move' => '-1:down']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/sections?locale=en', $response->getHeaderLine('Location'));
        self::assertSame(['stories' => 0, 'welcome' => 1], $this->app->container()->service(SiteSettings::class)->mainMenuOrder());

        $refused = $this->browser->post('/admin/sections/order', [...$this->treeForm(), 'move' => $guides->id . ':indent']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertMatchesRegularExpression('~<div class="admin-toasts" data-toasts aria-live="polite">\s*<div class="admin-toast admin-toast-error" role="alert" data-toast><svg[^>]*>.*?</svg><p class="admin-toast-text">Fixed menu items stay at the top level and cannot hold sections\.</p>~', (string) $refused->getBody(), 'every message is a toast');
        self::assertStringNotContainsString('admin-alert', (string) $refused->getBody());
        self::assertNull($this->terms()->find($guides->id)?->parentId);
    }

    /**
     * A drag on the Sections screen is saved in the background like on every order screen; the version of the
     * answer covers the sections and the pinned positions.
     */
    public function testADraggedTreeIsSavedInTheBackground(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $json = ['Accept' => 'application/json'];

        $saved = $this->browser->post('/admin/sections/order', [...$this->treeForm(), 'tree' => sprintf('[{"id":-2},{"id":%d},{"id":-1}]', $guides->id)], $json);

        self::assertSame(200, $saved->getStatusCode());
        self::assertSame(['version' => $this->treeForm()['version']], json_decode((string) $saved->getBody(), true));
        self::assertSame(['stories' => 0, 'welcome' => 2], $this->app->container()->service(SiteSettings::class)->mainMenuOrder());

        $refused = $this->browser->post('/admin/sections/order', [...$this->treeForm(), 'tree' => sprintf('[{"id":-2,"children":[{"id":%d}]},{"id":-1}]', $guides->id)], $json);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['error' => 'Fixed menu items stay at the top level and cannot hold sections.'], json_decode((string) $refused->getBody(), true));
        self::assertNull($this->terms()->find($guides->id)?->parentId);
    }

    public function testTheSectionFormsLiveUnderTheSectionsScreen(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/sections/new?locale=en')->getBody();
        self::assertStringContainsString('<form class="admin-form-card" method="post" action="/admin/sections">', $form);
        self::assertStringContainsString('<a href="/admin/sections?locale=en">Sections</a>', $form);

        $created = $this->browser->post('/admin/sections', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => '', 'name' => 'Guides', 'slug' => '', 'parent_id' => '']);
        $guides = $this->terms()->findBySlug('sections', 'en', 'guides') ?? self::fail('Not created');
        self::assertSame('/admin/sections/' . $guides->id, $created->getHeaderLine('Location'));

        $edit = (string) $this->browser->get('/admin/sections/' . $guides->id)->getBody();
        self::assertMatchesRegularExpression(self::navLink('/admin/sections', 'Sections', true), $edit);
        self::assertStringContainsString('action="/admin/sections/' . $guides->id . '/translate"', $edit);
        self::assertStringContainsString('href="/admin/sections/' . $guides->id . '/delete"', $edit);

        $this->entry('stories', 'Hello', ['terms' => ['sections' => [$guides->id]]]);
        $refused = $this->browser->post('/admin/sections/' . $guides->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame('/admin/sections/' . $guides->id, $refused->getHeaderLine('Location'), 'a refused delete returns to the edit page');
        self::assertMatchesRegularExpression('~admin-toast-error.*Entries take their web address from this item\.~s', (string) $this->browser->get('/admin/sections/' . $guides->id)->getBody());

        $empty = $this->term('sections', 'Empty');
        $deleted = $this->browser->post('/admin/sections/' . $empty->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame('/admin/sections?locale=en', $deleted->getHeaderLine('Location'));
    }

    /**
     * "Add section", Edit and Delete are plain links to the full pages (without JavaScript), marked for dialog.js,
     * which needs the screen's dialog and its script.
     */
    public function testTheScreenOpensItsFormsInADialog(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/sections?locale=en');
        $page = (string) $response->getBody();
        preg_match("~script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'~", $response->getHeaderLine('Content-Security-Policy'), $csp);

        self::assertStringContainsString('<a class="admin-button admin-button-primary" href="/admin/sections/new?locale=en" data-dialog-open>', $page);
        self::assertStringContainsString('href="/admin/sections/' . $guides->id . '" title="Edit “Guides”" aria-label="Edit “Guides”" data-dialog-open>', $page);
        self::assertStringContainsString('href="/admin/sections/' . $guides->id . '/delete" title="Delete “Guides”" aria-label="Delete “Guides”" data-dialog-open>', $page);
        self::assertMatchesRegularExpression('~<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">\s*<div class="admin-dialog-content" data-dialog-content></div>\s*</dialog>~', $page, 'the fragments bring their header (with the close button), body and footer');
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/dialog\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($csp[1] ?? '', '~') . '"></script>~', $page);
        self::assertStringNotContainsString(' style=', $page);
    }

    /**
     * Requested by htmx (HX-Request), the term pages answer with the dialog's fragment: no layout, the heading that
     * names the dialog, a form posting through htmx. A refused form comes back with its errors (200: htmx swaps no
     * error response); a saved one sends the browser to the Sections screen of its language, with the flash.
     */
    public function testTheDialogAddsAndEditsSections(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $htmx = ['HX-Request' => 'true'];

        $new = (string) $this->browser->get('/admin/sections/new?locale=ru', $htmx)->getBody();
        self::assertStringNotContainsString('<html', $new);
        self::assertStringContainsString('<h2 id="dialog-title">Add section <span class="admin-locale-badge" title="Content language: Русский" aria-hidden="true">RU</span>', $new);
        self::assertStringContainsString('<form class="admin-dialog-form" method="post" action="/admin/sections" hx-post="/admin/sections" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">', $new);
        self::assertStringContainsString('<input type="hidden" name="locale" value="ru">', $new);
        self::assertStringContainsString('<input id="name" name="name" required maxlength="200" value="" autofocus lang="ru" dir="ltr">', $new);
        self::assertStringContainsString('<button type="button" class="admin-button admin-button-secondary" data-dialog-close>Cancel</button>', $new);
        self::assertStringNotContainsString('hreflang=', $new, 'a new section has no Translations line');

        $form = ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'version' => '', 'slug' => '', 'parent_id' => ''];
        $refused = $this->browser->post('/admin/sections', [...$form, 'name' => ''], $htmx);
        self::assertSame(200, $refused->getStatusCode());
        self::assertSame('', $refused->getHeaderLine('HX-Redirect'));
        self::assertStringNotContainsString('<html', (string) $refused->getBody());
        self::assertStringContainsString('<div class="admin-field admin-field-invalid">', (string) $refused->getBody());
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM terms')));

        $created = $this->browser->post('/admin/sections', [...$form, 'name' => 'Гайды', 'slug' => 'gaidy'], $htmx);
        self::assertSame(200, $created->getStatusCode());
        self::assertSame('/admin/sections?locale=ru', $created->getHeaderLine('HX-Redirect'));
        $guides = $this->terms()->findBySlug('sections', 'ru', 'gaidy') ?? self::fail('Not created');
        self::assertStringContainsString('Saved.', (string) $this->browser->get('/admin/sections?locale=ru')->getBody());

        $edit = (string) $this->browser->get('/admin/sections/' . $guides->id, $htmx)->getBody();
        self::assertStringContainsString('<h2 id="dialog-title">Гайды <span class="admin-locale-badge" title="Content language: Русский" aria-hidden="true">RU</span>', $edit);
        self::assertStringContainsString('hx-post="/admin/sections/' . $guides->id . '"', $edit);
        self::assertStringNotContainsString('/translate"', $edit, 'the dialog has no Translations line');
        preg_match('~name="version" value="([^"]+)"~', $edit, $version);

        $stale = $this->browser->post('/admin/sections/' . $guides->id, [...$form, 'name' => 'Guides', 'slug' => 'gaidy', 'version' => 'old'], $htmx);
        self::assertSame(200, $stale->getStatusCode());
        self::assertSame('/admin/sections?locale=ru', $stale->getHeaderLine('HX-Redirect'), 'a refusal without a field reloads the screen');
        self::assertMatchesRegularExpression('~admin-toast-error.*Someone else saved this item after you opened it\.~s', (string) $this->browser->get('/admin/sections?locale=ru')->getBody());
        self::assertSame('Гайды', $this->terms()->find($guides->id)?->name);

        $saved = $this->browser->post('/admin/sections/' . $guides->id, [...$form, 'name' => 'Путеводители', 'slug' => 'gaidy', 'version' => $version[1] ?? ''], $htmx);
        self::assertSame('/admin/sections?locale=ru', $saved->getHeaderLine('HX-Redirect'));
        self::assertSame('Путеводители', $this->terms()->find($guides->id)?->name);
    }

    /**
     * The delete confirmation in the dialog: Cancel takes the focus; a done delete and a refused one (entries take
     * their address from the section, or it has subsections: the screen was out of date) send the browser back to the
     * Sections screen, with a success toast or the reason as an error toast.
     */
    public function testTheDialogDeletesSections(): void
    {
        $guides = $this->term('sections', 'Guides');
        $empty = $this->term('sections', 'Empty');
        $this->entry('stories', 'Hello', ['terms' => ['sections' => [$guides->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $htmx = ['HX-Request' => 'true'];

        $confirm = (string) $this->browser->get('/admin/sections/' . $guides->id . '/delete', $htmx)->getBody();
        self::assertStringNotContainsString('<html', $confirm);
        self::assertStringContainsString('<h2 id="dialog-title">Delete</h2>', $confirm);
        self::assertStringContainsString('hx-post="/admin/sections/' . $guides->id . '/delete" hx-target="closest [data-dialog-content]"', $confirm);
        self::assertStringContainsString('data-dialog-close autofocus>Cancel</button>', $confirm);

        $refused = $this->browser->post('/admin/sections/' . $guides->id . '/delete', ['_csrf' => $this->csrfToken()], $htmx);
        self::assertSame(200, $refused->getStatusCode(), 'htmx swaps no error response');
        self::assertSame('/admin/sections?locale=en', $refused->getHeaderLine('HX-Redirect'), 'the screen reloads');
        self::assertSame('', (string) $refused->getBody());
        self::assertNotNull($this->terms()->find($guides->id));
        $screen = (string) $this->browser->get('/admin/sections?locale=en')->getBody();
        self::assertMatchesRegularExpression('~<div class="admin-toast admin-toast-error" role="alert" data-toast><svg[^>]*>.*?</svg><p class="admin-toast-text">Entries take their web address from this item\. Move them to another item first\.</p>~', $screen);

        $parent = $this->term('sections', 'Parent');
        $this->term('sections', 'Child', ['parent' => $parent]);
        $withChildren = $this->browser->post('/admin/sections/' . $parent->id . '/delete', ['_csrf' => $this->csrfToken()], $htmx);
        self::assertSame('/admin/sections?locale=en', $withChildren->getHeaderLine('HX-Redirect'));
        self::assertStringContainsString('<p class="admin-toast-text">Delete or move the items inside it first.</p>', (string) $this->browser->get('/admin/sections?locale=en')->getBody());
        self::assertNotNull($this->terms()->find($parent->id));

        $page = $this->browser->post('/admin/sections/' . $parent->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame('/admin/sections/' . $parent->id, $page->getHeaderLine('Location'), 'without JavaScript the section form shows the reason');
        self::assertStringContainsString('<p class="admin-toast-text">Delete or move the items inside it first.</p>', (string) $this->browser->get('/admin/sections/' . $parent->id)->getBody());

        $deleted = $this->browser->post('/admin/sections/' . $empty->id . '/delete', ['_csrf' => $this->csrfToken()], $htmx);
        self::assertSame('/admin/sections?locale=en', $deleted->getHeaderLine('HX-Redirect'));
        self::assertNull($this->terms()->find($empty->id));
        self::assertStringContainsString('admin-toast-success', (string) $this->browser->get('/admin/sections?locale=en')->getBody());
    }

    public function testTheMenusScreenLeavesTheMainMenuToTheSections(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame('/admin/menus/footer', $this->browser->get('/admin/menus')->getHeaderLine('Location'));
        self::assertSame(404, $this->browser->get('/admin/menus/main')->getStatusCode());
        self::assertStringNotContainsString('/admin/menus/main', (string) $this->browser->get('/admin/menus/footer')->getBody());
    }

    /**
     * The tree form of the Sections screen as the page renders it.
     *
     * @return array<string, string>
     */
    private function treeForm(): array
    {
        $page = (string) $this->browser->get('/admin/sections?locale=en')->getBody();
        preg_match('~name="version" value="([^"]+)"~', $page, $version);
        preg_match('~name="tree" value="([^"]+)"~', $page, $tree);

        return [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => $version[1] ?? self::fail('No version'),
            'tree' => html_entity_decode($tree[1] ?? self::fail('No tree')),
        ];
    }
}
