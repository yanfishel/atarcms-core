<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Content\EntryStatus;
use AtarCms\Database\Connection;
use AtarCms\Kernel\Container;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Media\MediaUrls;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\QueryCounter;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

final class EntriesListTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
        $container->set(Roles::class, new Roles([...Roles::DEFAULTS, 'reader' => ['label' => 'Reader', 'permissions' => ['entries.*.view']]]));
    }

    public function testMenuListsTheCollectionsAUserCanView(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertMatchesRegularExpression(self::navLink('/admin/content/articles', 'Articles'), $body);
        self::assertMatchesRegularExpression(self::navLink('/admin/content/pages', 'pages'), $body);

        $this->actingAs($this->createUser('nobody@example.com', 'nobody'));
        self::assertStringNotContainsString('/admin/content/', (string) $this->browser->get('/admin')->getBody());
        self::assertSame(403, $this->browser->get('/admin/content/articles')->getStatusCode());
    }

    public function testListShowsEntriesOfTheLocaleEscaped(): void
    {
        $editor = $this->createUser('ed@example.com', 'editor', 'Edda');
        $hello = $this->entry('articles', 'Hello <b>', ['slug' => 'hello', 'author' => $editor]);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->entry('articles', 'Draft one', ['slug' => 'draft-one', 'status' => EntryStatus::Draft]);
        $this->actingAs($editor);

        $response = $this->browser->get('/admin/content/articles');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<tr class="admin-table-row">', $body);
        self::assertStringContainsString('<a class="admin-row-link" href="/admin/content/articles/' . $hello->id . '">Hello &lt;b&gt;</a>', $body);
        self::assertStringContainsString('<span class="admin-row-sub">hello</span>', $body);
        self::assertStringNotContainsString('Hello <b>', $body);
        self::assertStringContainsString('<span class="admin-chip admin-chip-draft">Draft</span>', $body);
        self::assertStringContainsString('<td data-label="Author">Edda</td>', $body);
        self::assertStringContainsString('<td data-label="Languages"><span class="admin-flags"><abbr class="admin-flag-code" title="English">EN</abbr><abbr class="admin-flag-code" title="Русский">RU</abbr></span></td>', $body, 'a theme without flags: the codes, named in themselves');
        self::assertMatchesRegularExpression(self::navLink('/admin/content/articles', 'Articles', true), $body);
        self::assertStringContainsString('href="/admin/content/articles/new?locale=en"', $body);
        self::assertStringNotContainsString('Привет', $body);
        self::assertStringContainsString('Привет', (string) $this->browser->get('/admin/content/articles?locale=ru')->getBody());
        self::assertStringContainsString('Привет', (string) $this->browser->get('/admin/content/articles?locale=de')->getBody(), 'an unsupported locale falls back to the remembered one');
        self::assertStringNotContainsString('Привет', (string) $this->browser->get('/admin/content/articles?locale=en')->getBody());
    }

    public function testFiltersAndSorting(): void
    {
        $this->entry('articles', 'Banana', ['slug' => 'banana']);
        $this->entry('articles', 'Apple', ['slug' => 'apple', 'status' => EntryStatus::Draft]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $published = (string) $this->browser->get('/admin/content/articles?status=published')->getBody();
        self::assertStringContainsString('Banana', $published);
        self::assertStringNotContainsString('Apple', $published);

        $search = (string) $this->browser->get('/admin/content/articles?q=%3Cscript%3Eapp')->getBody();
        self::assertStringContainsString('value="&lt;script&gt;app"', $search);
        self::assertStringNotContainsString('<script>app', $search);

        $byTitle = (string) $this->browser->get('/admin/content/articles?sort=title&dir=asc')->getBody();
        self::assertLessThan(strpos($byTitle, 'Banana'), strpos($byTitle, 'Apple'));
        self::assertStringContainsString('href="/admin/content/articles?locale=en&amp;sort=title&amp;dir=desc"', $byTitle);
        self::assertMatchesRegularExpression('~<th aria-sort="ascending"><a class="admin-sort" href="[^"]+">Title<svg class="admin-icon" aria-hidden="true"[^>]*><use href="[^"]+#chevron-up"></use></svg></a></th>~', $byTitle, 'an ascending column shows the up chevron');
        self::assertSame(1, substr_count($byTitle, 'aria-sort='), 'only the sorted column');
        $head = substr($byTitle, (int) strpos($byTitle, '<thead>'), (int) strpos($byTitle, '</thead>') - (int) strpos($byTitle, '<thead>'));
        self::assertSame(1, substr_count($head, '#chevron-'), 'the other headers carry no chevron');
        self::assertMatchesRegularExpression('~<th><a class="admin-sort" href="[^"]+">Updated</a></th>~', $byTitle);
    }

    public function testARowMarksAFilledGalleryAndYouTubeField(): void
    {
        $this->entry('articles', 'Rich', ['slug' => 'rich', 'fields' => ['images' => [$this->upload()->id, $this->upload()->id], 'video' => 'dQw4w9WgXcQ']]);
        $this->entry('articles', 'Bare', ['slug' => 'bare']);
        $this->entry('articles', 'Body video', ['slug' => 'body-video', 'fields' => ['body' => '<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>']]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/articles')->getBody();

        self::assertMatchesRegularExpression('~>Title(?:<svg.*?</svg>)?</a></th>\s*<th>Media</th>~s', $body, 'its own column, after the title');
        self::assertMatchesRegularExpression('~>Rich</a><span class="admin-row-sub">rich</span>.*?</td>\s*<td data-label="Media"><span class="admin-entry-media"><span class="admin-entry-media-item admin-entry-media-gallery" role="img" aria-label="Gallery: 2 photos" title="Gallery: 2 photos"><svg class="admin-icon" aria-hidden="true"><use href="[^"]+#images"></use></svg></span><span class="admin-entry-media-item admin-entry-media-video" role="img" aria-label="YouTube video" title="YouTube video"><svg class="admin-icon" aria-hidden="true"><use href="[^"]+#video"></use></svg></span></span></td>~s', $body);
        self::assertSame(1, substr_count($body, '<span class="admin-entry-media">'), 'a video in the text does not count (a user decision), an empty entry has no marks');
        self::assertSame(3, substr_count($body, '<td data-label="Media">'), 'every row has the cell');
        self::assertStringNotContainsString('data-label="Media"', (string) $this->browser->get('/admin/content/events')->getBody(), 'no column without a gallery or YouTube field');
    }

    public function testEachRowShowsTheMainPhotoOrAPlaceholder(): void
    {
        $cover = $this->upload();
        $galleryPhoto = $this->upload();
        $withCover = $this->entry('articles', 'With cover', ['slug' => 'with-cover', 'fields' => ['cover' => $cover->id]]);
        $this->entry('articles', 'From gallery', ['slug' => 'from-gallery', 'fields' => ['images' => [$galleryPhoto->id]]]);
        $this->entry('articles', 'Bare', ['slug' => 'bare']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $urls = $this->app->container()->service(MediaUrls::class);

        $body = (string) $this->browser->get('/admin/content/articles')->getBody();

        self::assertStringContainsString('<td><div class="admin-entry-title-cell"><img class="admin-entry-thumb" src="' . $this->e($urls->variant($cover, ImageVariants::ADMIN, true)) . '" alt="" loading="lazy" width="40" height="40"><div class="admin-entry-title-text"><a class="admin-row-link" href="/admin/content/articles/' . $withCover->id . '">With cover</a>', $body, 'the admin variant, decorative: the row link names the entry');
        self::assertStringContainsString('<img class="admin-entry-thumb" src="' . $this->e($urls->variant($galleryPhoto, ImageVariants::ADMIN, true)) . '"', $body, 'a gallery gives its first photo, like the share card');
        self::assertMatchesRegularExpression('~<div class="admin-entry-title-cell"><span class="admin-entry-thumb admin-entry-thumb-empty"><svg class="admin-icon" aria-hidden="true"[^>]*><use href="[^"]+#image-off"></use></svg></span><div class="admin-entry-title-text"><a class="admin-row-link" href="[^"]+">Bare</a>~', $body);
        self::assertSame(3, substr_count($body, '<div class="admin-entry-title-cell">'));
        self::assertSame(3, substr_count($body, '<td><div class="admin-entry-title-cell">'), 'no "Title" label above the picture in a phone card: the title is the card\'s heading');
    }

    public function testThePhotosCostOneQueryWhateverTheirNumber(): void
    {
        $this->entry('articles', 'One', ['slug' => 'one', 'fields' => ['cover' => $this->upload()->id]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $db = $this->app->container()->service(Connection::class);
        $once = QueryCounter::measure($db, fn(): mixed => $this->browser->get('/admin/content/articles'));

        $this->entry('articles', 'Two', ['slug' => 'two', 'fields' => ['cover' => $this->upload()->id]]);
        $this->entry('articles', 'Three', ['slug' => 'three', 'fields' => ['images' => [$this->upload()->id]]]);
        $thrice = QueryCounter::measure($db, fn(): mixed => $this->browser->get('/admin/content/articles'));

        self::assertSame($once, $thrice);
    }

    public function testACollectionWithoutPhotoFieldsShowsNoThumbnails(): void
    {
        $this->entry('events', 'Concert', ['slug' => 'concert']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/events')->getBody();

        self::assertStringContainsString('Concert', $body);
        self::assertStringNotContainsString('admin-entry-thumb', $body);
        self::assertStringNotContainsString('admin-entry-title-cell', $body);
    }

    public function testTheDefaultSortIsTheLatestUpdateFirstAndShowsInItsHeader(): void
    {
        $this->entry('articles', 'Older', ['slug' => 'older']);
        $this->clock->advance('+1 hour');
        $this->entry('articles', 'Newer', ['slug' => 'newer']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $list = (string) $this->browser->get('/admin/content/articles')->getBody();

        self::assertLessThan(strpos($list, '>Older<'), strpos($list, '>Newer<'), 'the latest update first');
        self::assertMatchesRegularExpression('~<th aria-sort="descending"><a class="admin-sort" href="/admin/content/articles\?locale=en&amp;sort=updated_at&amp;dir=asc">Updated<svg[^>]*><use href="[^"]+#chevron-down"></use></svg></a></th>~', $list, 'the header shows the default sort; a click turns it round');
        self::assertStringNotContainsString('name="sort"', $list, 'the default is not written into the filters');
        self::assertSame(1, substr_count($list, 'aria-sort='), 'only one column is sorted');
    }

    /**
     * The filter form is a GET form: without the current sort and direction in it, submitting it would drop them.
     */
    public function testReFilteringKeepsTheSortAndTheDirection(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $sorted = (string) $this->browser->get('/admin/content/articles?sort=title&dir=asc&status=draft')->getBody();
        $form = substr($sorted, (int) strpos($sorted, '<form class="admin-filters"'), 600);
        self::assertStringContainsString('<input type="hidden" name="sort" value="title">', $form);
        self::assertStringContainsString('<input type="hidden" name="dir" value="asc">', $form);

        $unsorted = (string) $this->browser->get('/admin/content/articles')->getBody();
        self::assertStringNotContainsString('name="sort"', $unsorted);
        self::assertStringNotContainsString('name="dir"', $unsorted);

        $forged = (string) $this->browser->get('/admin/content/articles?sort=%22%3E&dir=up')->getBody();
        self::assertStringNotContainsString('name="sort"', $forged, 'only known sorts are kept');
    }

    public function testTheFiltersSubmitThemselvesAndSwapOnlyTheResults(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $page = (string) $this->browser->get('/admin/content/articles')->getBody();

        self::assertMatchesRegularExpression('~<form class="admin-filters" method="get" action="/admin/content/articles" hx-get="/admin/content/articles" hx-target="#entries-results" hx-select="#entries-results" hx-swap="outerHTML" hx-push-url="true" hx-sync="this:replace" role="search" data-list-filters>~', $page);
        self::assertStringContainsString('<input type="search" id="entries-q" name="q" maxlength="100" value="" data-list-search>', $page);
        self::assertMatchesRegularExpression('~<button type="submit" class="admin-button admin-button-secondary admin-list-apply">~', $page, 'hidden under .js, kept without JavaScript');
        self::assertMatchesRegularExpression('~<div id="entries-results">\s*<p class="admin-muted admin-list-count">~', $page, 'the count, the table and the pages are swapped together');
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/list-filters\.js\?v=~', $page);
    }

    public function testAnEmptyListInvitesToCreateTheFirstEntry(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $empty = (string) $this->browser->get('/admin/content/articles')->getBody();
        self::assertStringContainsString('Add the first one', $empty);
        self::assertMatchesRegularExpression('~<div class="admin-card admin-empty">\s*<svg[^>]*>.*?</svg>\s*<p class="admin-empty-title">Nothing here yet</p>\s*<a class="admin-button admin-button-primary" href="/admin/content/articles/new\?locale=en">~s', $empty);

        $filtered = (string) $this->browser->get('/admin/content/articles?q=nothing')->getBody();
        self::assertStringContainsString('<p class="admin-empty-title">Nothing matches these filters.</p>', $filtered);
        self::assertStringNotContainsString('Add the first one', $filtered, 'a filtered list has no create invitation');

        $this->actingAs($this->createUser('re@example.com', 'reader'));
        $readOnly = $this->browser->get('/admin/content/articles');
        self::assertSame(200, $readOnly->getStatusCode());
        self::assertStringContainsString('<p class="admin-empty-title">Nothing here yet</p>', (string) $readOnly->getBody());
        self::assertStringNotContainsString('href="/admin/content/articles/new', (string) $readOnly->getBody(), 'only for users who may create');
        self::assertStringNotContainsString('Add the first one', (string) $readOnly->getBody());
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->entry('articles', 'Entry ' . $i, ['publishedAt' => $this->clock->now()->modify(sprintf('-%d minutes', $i))]);
        }
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $first = (string) $this->browser->get('/admin/content/articles')->getBody();
        self::assertStringContainsString('26 entries', $first);
        self::assertStringContainsString('<a class="admin-page-link" href="/admin/content/articles?locale=en&amp;page=2" aria-label="Page 2">2</a>', $first);
        self::assertStringContainsString('<a class="admin-page-link" href="/admin/content/articles?locale=en&amp;page=2" rel="next" title="Next page">', $first);
        self::assertStringContainsString('<span class="admin-page-link" aria-current="page">1</span>', $first);
        self::assertStringContainsString('Page 2 of 2', (string) $this->browser->get('/admin/content/articles?page=2')->getBody());
        self::assertStringContainsString('Page 1 of 2', (string) $this->browser->get('/admin/content/articles?page=abc')->getBody());
    }

    public function testSingletonsAndNonTranslatableCollections(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame('/admin/content/contact/new', $this->browser->get('/admin/content/contact')->getHeaderLine('Location'));
        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        self::assertSame('/admin/content/contact/' . $contact->id, $this->browser->get('/admin/content/contact')->getHeaderLine('Location'));

        $this->entry('events', 'Concert', ['slug' => 'concert']);
        $events = (string) $this->browser->get('/admin/content/events?locale=ru')->getBody();
        self::assertStringContainsString('Concert', $events);
        self::assertDoesNotMatchRegularExpression('~href="[^"]*[?&]locale=ru~', $events, 'no link carries the requested language');
    }

    public function testAuthorEntryListLinksOnlyToEditableEntries(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $ownDraft = $this->entry('articles', 'Mine draft', ['slug' => 'mine-draft', 'status' => EntryStatus::Draft, 'author' => $author]);
        $ownLive = $this->entry('articles', 'Mine live', ['slug' => 'mine-live', 'author' => $author]);
        $others = $this->entry('articles', 'Others', ['slug' => 'others']);
        $this->actingAs($author);

        $body = (string) $this->browser->get('/admin/content/articles')->getBody();

        self::assertStringContainsString('<a class="admin-row-link" href="/admin/content/articles/' . $ownDraft->id . '">Mine draft</a>', $body);
        self::assertStringNotContainsString('href="/admin/content/articles/' . $ownLive->id . '"', $body);
        self::assertStringContainsString('<span class="admin-row-title">Mine live</span>', $body);
        self::assertStringNotContainsString('href="/admin/content/articles/' . $others->id . '"', $body);
        self::assertStringContainsString('<span class="admin-row-title">Others</span>', $body);
        self::assertSame(1, substr_count($body, '<tr class="admin-table-row">'), 'only the editable row is a link');
    }

    public function testATermWithChildrenShowsTheEntriesOfItsWholeBranch(): void
    {
        $code = $this->term('topics', 'Code');
        $php = $this->term('topics', 'PHP', ['parent' => $code]);
        $go = $this->term('topics', 'Go');
        $this->entry('articles', 'About code', ['slug' => 'about-code', 'terms' => ['topics' => [$code->id]]]);
        $this->entry('articles', 'With PHP', ['slug' => 'with-php', 'terms' => ['topics' => [$php->id]]]);
        $this->entry('articles', 'With Go', ['slug' => 'with-go', 'terms' => ['topics' => [$go->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $branch = (string) $this->browser->get('/admin/content/articles?term=' . $code->id)->getBody();
        self::assertStringContainsString('About code', $branch);
        self::assertStringContainsString('With PHP', $branch, 'a subsection\'s entries belong to its parent too');
        self::assertStringNotContainsString('With Go', $branch);

        $leaf = (string) $this->browser->get('/admin/content/articles?term=' . $php->id)->getBody();
        self::assertStringContainsString('With PHP', $leaf);
        self::assertStringNotContainsString('About code', $leaf, 'a parent\'s entries do not belong to its child');
    }

    public function testFiltersByTerm(): void
    {
        $php = $this->term('topics', 'PHP <b>');
        $go = $this->term('topics', 'Go');
        $russian = $this->term('topics', 'Код', ['locale' => 'ru']);
        $this->entry('articles', 'With PHP', ['slug' => 'with-php', 'terms' => ['topics' => [$php->id]]]);
        $this->entry('articles', 'With Go', ['slug' => 'with-go', 'terms' => ['topics' => [$go->id]]]);
        $this->entry('articles', 'Tagged', ['slug' => 'tagged', 'terms' => ['tags' => ['News']]]);
        $news = $this->terms()->findBySlug('tags', 'en', 'news') ?? self::fail('The tag is missing');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/articles?term=' . $php->id)->getBody();
        self::assertStringContainsString('<option value="' . $php->id . '" selected>PHP &lt;b&gt;</option>', $body);
        self::assertStringContainsString('<optgroup label="Tags">', $body);
        self::assertStringContainsString('With PHP', $body);
        self::assertStringNotContainsString('With Go', $body);
        self::assertStringNotContainsString('>Tagged<', $body);
        self::assertStringContainsString('?locale=en&amp;term=' . $php->id . '&amp;sort=title&amp;dir=asc', $body);

        $tagged = (string) $this->browser->get('/admin/content/articles?term=' . $news->id)->getBody();
        self::assertStringContainsString('>Tagged<', $tagged);
        self::assertStringNotContainsString('With PHP', $tagged);

        foreach ([(string) $russian->id, 'abc', '99999'] as $ignored) {
            $all = (string) $this->browser->get('/admin/content/articles?term=' . $ignored)->getBody();
            self::assertStringContainsString('With PHP', $all, $ignored);
            self::assertStringContainsString('With Go', $all, $ignored);
        }
    }

    /**
     * The Term select only shows up when the collection's taxonomies actually have a term to pick — not just
     * when the collection declares a taxonomy (events uses "regions", which starts out empty).
     */
    public function testTermFilterIsHiddenWhenNoTermsExist(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/events')->getBody();

        self::assertStringNotContainsString('name="term"', $body);
        self::assertStringNotContainsString('>Category or tag<', $body);

        $this->term('regions', 'North');
        $body = (string) $this->browser->get('/admin/content/events')->getBody();

        self::assertStringContainsString('name="term"', $body);
    }

    public function testUnknownCollectionsAndGuests(): void
    {
        self::assertSame(302, $this->browser->get('/admin/content/articles')->getStatusCode());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame(404, $this->browser->get('/admin/content/ghost')->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/content/Articles')->getStatusCode());
    }

    private function upload(): Media
    {
        return $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(400, 300), 'photo.jpg', 'image/jpeg'), null, null);
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
