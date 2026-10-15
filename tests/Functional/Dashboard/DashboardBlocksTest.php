<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Dashboard;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\UserRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\RevisionRepository;
use AtarCms\Media\MediaRepository;
use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Regex;
use DateTimeImmutable;

/**
 * The dashboard's numbers and charts: each block only for who may see it, the charts drawn by the server with the
 * same numbers in a table for screen readers, right to left in a Hebrew interface.
 */
final class DashboardBlocksTest extends ContentFunctionalTestCase
{
    public function testAnAdminSeesEveryBlock(): void
    {
        $this->entry('articles', 'Hello', ['terms' => ['tags' => ['News']]]);
        $this->message('Ann', '-1 hour');
        $admin = $this->createUser('admin@example.com', 'admin', 'Ann');
        $this->app->container()->service(AuditLog::class)->record('auth.login', $admin->id, 'user', $admin->id);
        $this->actingAs($admin);

        $body = (string) $this->browser->get('/admin')->getBody();

        foreach (['Published', 'Drafts and scheduled', 'Unread messages', 'Photos and files'] as $tile) {
            self::assertMatchesRegularExpression('~<h2 class="admin-dash-tile-label">(<a [^>]+>)?' . $tile . '(</a>)?</h2>~', $body, $tile);
        }
        $cards = [
            'dashboard-activity' => ['Activity', 'chart-no-axes-column'],
            'dashboard-content' => ['Content', 'layers'],
            'dashboard-translations' => ['Translations', 'languages'],
            'dashboard-tags' => ['Popular tags', 'tags'],
            'dashboard-messages' => ['Latest messages', 'inbox'],
            'dashboard-progress' => ['In progress', 'pencil-line'],
            'dashboard-feed' => ['Recent activity', 'history'],
        ];
        foreach ($cards as $id => [$title, $icon]) {
            self::assertMatchesRegularExpression('~<section class="admin-card admin-dash-[a-z]+[^"]*" id="' . $id . '" aria-labelledby="' . $id . '-title">\s*<div class="admin-dash-head">\s*<h2 class="admin-dash-title" id="' . $id . '-title"><svg class="admin-icon"[^>]*><use href="[^"]+\#' . $icon . '"></use></svg>' . $title . '</h2>~s', $body, $title . ' leads with its icon');
        }
        self::assertStringNotContainsString(' style=', $body);
        self::assertSame(1, substr_count($body, '<script nonce='), 'the theme bootstrap stays the only inline script');
    }

    public function testAnAuthorSeesNoMessagesNorTheHistory(): void
    {
        $this->message('Ann', '-1 hour');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertStringNotContainsString('Unread messages', $body);
        self::assertStringNotContainsString('id="dashboard-messages"', $body);
        self::assertStringNotContainsString('id="dashboard-feed"', $body);
        self::assertStringNotContainsString('Ann', $body);
        self::assertStringContainsString('Photos and files', $body, 'authors upload files');
        self::assertStringContainsString('id="dashboard-activity"', $body);
        self::assertMatchesRegularExpression('~<div class="admin-dash-split">\s*<section class="admin-card admin-dash-progress" id="dashboard-progress"~', $body);
    }

    public function testTheTilesCountWhatTheListsShow(): void
    {
        $now = $this->clock->now();
        $this->entry('articles', 'Today');
        $this->entry('articles', 'Last week', ['publishedAt' => $now->modify('-7 days')]);
        $this->entry('articles', 'Long ago', ['publishedAt' => $now->modify('-60 days')]);
        $this->entry('articles', 'Привет', ['locale' => 'ru']);
        $this->entry('articles', 'Draft', ['status' => EntryStatus::Draft]);
        $this->entry('articles', 'Soon', ['status' => EntryStatus::Scheduled, 'publishedAt' => $now->modify('+1 day')]);
        $this->message('Ann', '-1 hour');
        $this->message('Bob', '-2 days', read: true);
        $this->message('Old', '-40 days');
        $media = $this->app->container()->service(MediaRepository::class);
        foreach ([1024, 2560] as $size) {
            $media->insert(['path' => bin2hex(random_bytes(8)) . '.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => $size, 'width' => 1, 'height' => 1, 'alt' => [], 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->actingAs($this->createUser('admin@example.com'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertSame('4', self::tile($body, 'Published', 'value'), 'every language; the scheduled one is not public yet');
        self::assertSame('+3 in 30 days', self::tile($body, 'Published', 'note'));
        self::assertMatchesRegularExpression('~<dt>Drafts</dt>\s*<dd>1</dd>\s*</div>\s*<div class="admin-dash-pair-item">\s*<dt>Scheduled</dt>\s*<dd>1</dd>~', $body);
        self::assertSame('2', self::tile($body, 'Unread messages', 'value'));
        self::assertSame('2 received in 30 days', self::tile($body, 'Unread messages', 'note'));
        self::assertSame('2', self::tile($body, 'Photos and files', 'value'));
        self::assertSame('3.5 KB used', self::tile($body, 'Photos and files', 'note'));
        self::assertMatchesRegularExpression('~<h2 class="admin-dash-tile-label"><a class="admin-dash-tile-link" href="/admin/messages">Unread messages</a></h2>~', $body);
        self::assertMatchesRegularExpression('~<svg class="admin-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path class="admin-spark-area" d="M[^"]+Z"/><path class="admin-spark-line" d="M[^"]+" vector-effect="non-scaling-stroke"/></svg>~', $body);
    }

    public function testThePublishedTileLeavesSinglesOutLikeTheContentCard(): void
    {
        $this->entry('articles', 'Article');
        $this->entry('contact', 'Contact', ['fields' => ['phone' => '123']]);
        $this->entry('welcome', 'Welcome');
        $this->actingAs($this->createUser('admin@example.com'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertSame('1', self::tile($body, 'Published', 'value'), 'the single and the plain single are pages, not content counted');
        self::assertSame('+1 in 30 days', self::tile($body, 'Published', 'note'));
        self::assertStringContainsString('<span class="admin-dash-stack-total">1</span>', self::between($body, 'id="dashboard-content"', '</section>'));
    }

    public function testTheScaleTicksAreWholeNumbersInTheLanguage(): void
    {
        $hello = $this->entry('articles', 'Hello');
        $revisions = $this->app->container()->service(RevisionRepository::class);
        foreach (range(1, 4) as $ignored) {
            $revisions->record($hello, null);
        }
        $ann = $this->createUser('ann@example.com');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'ru');
        $this->actingAs($this->app->container()->service(UserRepository::class)->find($ann->id) ?? self::fail('no user'));

        $chart = self::between((string) $this->browser->get('/admin')->getBody(), 'id="dashboard-activity"', '</section>');

        self::assertStringContainsString('<span class="admin-chart-tick admin-chart-tick-top" aria-hidden="true">10</span>', $chart, 'five edits: the scale is ten, never five');
        self::assertStringContainsString('<span class="admin-chart-tick admin-chart-tick-mid" aria-hidden="true">5</span>', $chart);
        self::assertStringContainsString('Правки <strong>5</strong>', $chart);
    }

    public function testTheActivityChartHasItsNumbersInATable(): void
    {
        $now = $this->clock->now();
        $hello = $this->entry('articles', 'Hello');
        $revisions = $this->app->container()->service(RevisionRepository::class);
        $revisions->record($hello, null);
        $this->entry('articles', 'Yesterday', ['publishedAt' => $now->modify('-1 day')]);
        $this->actingAs($this->createUser('admin@example.com'));

        $chart = self::between((string) $this->browser->get('/admin')->getBody(), 'id="dashboard-activity"', '</section>');

        self::assertMatchesRegularExpression('~<svg class="admin-chart-svg" viewBox="0 0 300 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">~', $chart, 'thirty days of ten units');
        self::assertSame(1, substr_count($chart, 'class="admin-chart-bar admin-chart-bar-edits"'), 'the edits of today');
        self::assertSame(2, substr_count($chart, 'class="admin-chart-bar admin-chart-bar-publications"'), 'today and yesterday');
        self::assertMatchesRegularExpression('~<ol class="admin-chart-hits" aria-hidden="true">~', $chart);
        self::assertSame(30, substr_count($chart, '<li class="admin-chart-hit" title="'));
        self::assertStringContainsString('<li class="admin-chart-hit" title="Sep 28: 3 edits, 1 publication"></li>', $chart, 'creating each entry stored a version too');
        self::assertMatchesRegularExpression('~<table class="admin-visually-hidden">\s*<caption>Edits and publications per day, last 30 days</caption>~', $chart);
        self::assertStringContainsString('<tr><th scope="row">Sep 28</th><td>3</td><td>1</td></tr>', $chart);
        self::assertStringContainsString('<tr><th scope="row">Sep 27</th><td>0</td><td>1</td></tr>', $chart);
        self::assertSame(30, substr_count($chart, '<th scope="row">'));
        self::assertMatchesRegularExpression('~<li><span>Sep 28</span></li>\s*</ol>~', $chart, 'today\'s label is the last one, in a span the stylesheet keeps inside the plot');
        self::assertMatchesRegularExpression('~<span class="admin-dash-key admin-dash-key-edits" aria-hidden="true"></span>Edits <strong>3</strong>~', $chart, 'the legend names each series with its total');
    }

    public function testTheRangeSwitchIsPlainLinksThatHtmxMaySwap(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $thirty = (string) $this->browser->get('/admin')->getBody();
        self::assertMatchesRegularExpression('~<nav class="admin-dash-range" aria-label="Period">\s*<a id="dashboard-range-7" href="/admin\?days=7" hx-get="/admin\?days=7" hx-select="#dashboard-activity" hx-target="#dashboard-activity" hx-swap="outerHTML" hx-push-url="true">7 days</a>\s*<a id="dashboard-range-30" href="/admin\?days=30" [^>]*aria-current="true">30 days</a>\s*<a id="dashboard-range-90" href="/admin\?days=90" [^>]*>90 days</a>\s*</nav>~', $thirty, 'ids let htmx keep the focus on the link after the swap');

        $week = self::between((string) $this->browser->get('/admin?days=7')->getBody(), 'id="dashboard-activity"', '</section>');
        self::assertSame(7, substr_count($week, '<th scope="row">'));
        self::assertStringContainsString('<a id="dashboard-range-7" href="/admin?days=7" hx-get="/admin?days=7" hx-select="#dashboard-activity" hx-target="#dashboard-activity" hx-swap="outerHTML" hx-push-url="true" aria-current="true">7 days</a>', $week);

        $quarter = self::between((string) $this->browser->get('/admin?days=90')->getBody(), 'id="dashboard-activity"', '</section>');
        self::assertSame(13, substr_count($quarter, '<th scope="row">'), 'thirteen weeks');
        self::assertStringContainsString('<caption>Edits and publications per week, last 90 days</caption>', $quarter);
        self::assertStringContainsString('<th scope="row">Sep 22 – Sep 28</th>', $quarter);

        self::assertSame(30, substr_count(self::between((string) $this->browser->get('/admin?days=12')->getBody(), 'id="dashboard-activity"', '</section>'), '<th scope="row">'), 'an unknown range shows thirty days');
    }

    public function testTimeRunsRightToLeftInAHebrewInterface(): void
    {
        $this->entry('articles', 'Hello');
        $he = $this->createUser('he@example.com');
        $this->app->container()->service(UserRepository::class)->setLocale($he->id, 'he');
        $this->actingAs($this->createUser('en@example.com'));
        $ltr = Regex::match('~<rect class="admin-chart-bar admin-chart-bar-publications" x="([\d.]+)"~', (string) $this->browser->get('/admin')->getBody());
        $this->actingAs($this->app->container()->service(UserRepository::class)->find($he->id) ?? self::fail('no user'));
        $rtl = Regex::match('~<rect class="admin-chart-bar admin-chart-bar-publications" x="([\d.]+)"~', (string) $this->browser->get('/admin')->getBody());

        self::assertGreaterThan(290, (float) $ltr[1], 'today on the right');
        self::assertLessThan(10, (float) $rtl[1], 'today on the left');
    }

    public function testContentByCollectionCountsEachStatus(): void
    {
        $this->entry('articles', 'One');
        $this->entry('articles', 'Two', ['status' => EntryStatus::Draft]);
        $this->entry('articles', 'Three', ['status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        $this->entry('notes', 'Note', ['fields' => ['text' => 'x']]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $card = self::between((string) $this->browser->get('/admin')->getBody(), 'id="dashboard-content"', '</section>');

        self::assertMatchesRegularExpression('~<li class="admin-dash-stack admin-tone-1" title="Published: 1, scheduled: 1, drafts: 1">\s*<div class="admin-dash-stack-head">\s*<span class="admin-dash-dot" aria-hidden="true"></span>\s*<a href="/admin/content/articles">Articles</a>\s*<span class="admin-dash-stack-total">3</span>~', $card);
        self::assertStringContainsString('<span class="admin-visually-hidden">Published: 1, scheduled: 1, drafts: 1</span>', $card);
        self::assertStringContainsString('<a href="/admin/content/notes">notes</a>', $card);
        self::assertStringNotContainsString('/admin/content/contact"', $card, 'a single is one page, no row');
        self::assertMatchesRegularExpression('~<svg class="admin-dash-stack-bar" viewBox="0 0 3 1" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="admin-dash-seg admin-dash-seg-published" x="0" y="0" width="1" height="1" vector-effect="non-scaling-stroke"/><rect class="admin-dash-seg admin-dash-seg-scheduled" x="1" y="0" width="1" height="1" vector-effect="non-scaling-stroke"/><rect class="admin-dash-seg admin-dash-seg-draft" x="2" y="0" width="1" height="1" vector-effect="non-scaling-stroke"/></svg>~', $card, 'the scale is the largest collection');
    }

    public function testTranslationsTagsMessagesAndTheFeed(): void
    {
        $hello = $this->entry('articles', 'Hello', ['terms' => ['tags' => ['News', 'Art']]]);
        $this->entry('articles', 'World', ['terms' => ['tags' => ['News']]]);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->message('Ann <b>', '-1 hour', "First line\nSecond line");
        $this->message('Bob', '-2 hours', read: true);
        $admin = $this->createUser('admin@example.com', 'admin', 'Zoe');
        $audit = $this->app->container()->service(AuditLog::class);
        $audit->record('entry.created', $admin->id, 'entry', $hello->id, ['title' => 'Hello <i>']);
        $audit->record('shop.paid', null, 'order', 12);
        $this->actingAs($admin);

        $body = (string) $this->browser->get('/admin')->getBody();

        $translations = self::between($body, 'id="dashboard-translations"', '</section>');
        self::assertMatchesRegularExpression('~<abbr class="admin-flag-code" title="English">EN</abbr>\s*</span>\s*<span class="admin-dash-meter-value">2 of 2</span>~', $translations);
        self::assertMatchesRegularExpression('~<abbr class="admin-flag-code" title="Русский">RU</abbr>\s*</span>\s*<span class="admin-dash-meter-value">1 of 2</span>~', $translations);

        $tags = self::between($body, 'id="dashboard-tags"', '</section>');
        self::assertMatchesRegularExpression('~<span class="admin-dash-meter-name" lang="en" dir="ltr">News</span>\s*</span>\s*<span class="admin-dash-meter-value" title="2 entries">2</span>.*<span class="admin-dash-meter-name" lang="en" dir="ltr">Art</span>~s', $tags);

        $messages = self::between($body, 'id="dashboard-messages"', '</section>');
        self::assertMatchesRegularExpression('~<li class="admin-dash-message admin-dash-message-unread">\s*<a class="admin-dash-message-link" href="/admin/messages/\d+">\s*<span class="admin-dash-message-name"><bdi>Ann &lt;b&gt;</bdi><span class="admin-visually-hidden"> \(Unread\)</span></span>\s*<span class="admin-dash-message-text"><bdi>First line</bdi></span>~', $messages, 'the row follows the interface direction; only the visitor\'s texts are isolated');
        self::assertMatchesRegularExpression('~<li class="admin-dash-message">\s*<a class="admin-dash-message-link" href="/admin/messages/\d+">\s*<span class="admin-dash-message-name"><bdi>Bob</bdi></span>~', $messages);
        self::assertStringContainsString('<a class="admin-dash-more" href="/admin/messages">All messages</a>', $messages);

        $feed = self::between($body, 'id="dashboard-feed"', '</section>');
        self::assertMatchesRegularExpression('~<span class="admin-avatar admin-role admin-role-admin" aria-hidden="true">Z</span>\s*<div class="admin-dash-event-text">\s*<p class="admin-dash-event-what"><strong>Zoe</strong> <span title="entry.created">Created</span></p>\s*<p class="admin-dash-event-item">Entry: “Hello &lt;i&gt;”</p>~', $feed, 'the log screen\'s words');
        self::assertStringContainsString('<strong>Someone</strong> <code>shop.paid</code>', $feed, 'an action without a user or words stays as recorded');
        self::assertStringContainsString('<a class="admin-dash-more" href="/admin/audit">Open the activity history</a>', $feed);
    }

    public function testATagsTaxonomyOfNoViewableCollectionIsLeftOut(): void
    {
        $this->actingAs($this->createUser('nobody@example.com', 'nobody'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertStringNotContainsString('id="dashboard-tags"', $body);
        self::assertStringNotContainsString('id="dashboard-translations"', $body);
        self::assertStringNotContainsString('id="dashboard-content"', $body);
    }

    private function message(string $name, string $age, string $body = 'Hello', bool $read = false): int
    {
        $created = new DateTimeImmutable('2026-09-28 12:00:00')->modify($age);

        return $this->app->container()->service(MessageRepository::class)->insert([
            'form' => 'contact',
            'locale' => 'en',
            'name' => $name,
            'email' => 'visitor@example.com',
            'phone' => null,
            'body' => $body,
            'page_url' => 'https://localhost/contact',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => $created,
            'read_at' => $read ? $created : null,
        ]);
    }

    /**
     * The value or the note of the KPI tile labelled $label.
     */
    private static function tile(string $body, string $label, string $part): string
    {
        $tile = self::between(self::between($body, '<section class="admin-dash-tiles"', '</section>'), $label . '</', '</article>');
        preg_match('~<p class="admin-dash-tile-' . $part . '">([^<]+)</p>~', $tile, $match);

        return $match[1] ?? '';
    }

    private static function between(string $body, string $start, string $end): string
    {
        $from = strpos($body, $start);
        self::assertNotFalse($from, $start);
        $to = strpos($body, $end, $from);
        self::assertNotFalse($to, $end);

        return substr($body, $from, $to - $from);
    }
}
