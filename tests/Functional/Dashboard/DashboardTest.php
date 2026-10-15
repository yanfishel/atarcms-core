<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Dashboard;

use AtarCms\Auth\Roles;
use AtarCms\Content\EntryStatus;
use AtarCms\Kernel\Container;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use Dom\HTMLDocument;

final class DashboardTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'reporter' => ['label' => 'Reporter', 'permissions' => ['entries.articles.view', 'entries.articles.create', 'entries.pages.view', 'entries.contact.*']],
        ]));
    }

    public function testTheGreetingHasTodaysDate(): void
    {
        $this->actingAs($this->createUser('admin@example.com', 'admin', 'Ann'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertMatchesRegularExpression('~<div class="admin-dash-hello">\s*<h1>Hello, Ann!</h1>\s*<p class="admin-dash-date">Monday, September 28, 2026</p>\s*</div>~', $body, 'the frozen clock, in the site zone and the interface language');
        self::assertStringNotContainsString('Signed in as', $body, 'the account is in the top bar');
    }

    public function testAHiddenSiteIsMentionedToSettingsManagers(): void
    {
        $line = '~<div class="admin-alert admin-alert-warning" role="status"><svg[^>]*>.*?</svg><p>The site is hidden from search engines\. <a href="/admin/settings">Change it in the site settings</a></p></div>\s*<header class="admin-dash-header">~';
        $this->actingAs($this->createUser('admin@example.com'));
        self::assertDoesNotMatchRegularExpression($line, (string) $this->browser->get('/admin')->getBody(), 'not while the site is visible');

        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(hideFromSearch: true), null, null);

        self::assertMatchesRegularExpression($line, (string) $this->browser->get('/admin')->getBody());
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertStringNotContainsString('hidden from search engines', (string) $this->browser->get('/admin')->getBody(), 'only for users who may change it');
    }

    public function testInProgressShowsRecentDraftAndScheduledEntriesInTabs(): void
    {
        $draft = $this->entry('articles', 'Work in progress', ['status' => EntryStatus::Draft]);
        $this->entry('articles', 'Coming soon', ['status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        $this->entry('articles', 'Live <b>');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertMatchesRegularExpression('~<div class="admin-form-column" data-tabs="admin\.tabs\.dashboard">\s*<div class="admin-tab-list" role="tablist" aria-label="In progress">~', $body);
        self::assertMatchesRegularExpression('~<button type="button" class="admin-tab" role="tab" id="tab-recent" aria-controls="panel-recent" aria-selected="true" tabindex="0" data-tab="recent">Recently updated</button>~', $body);
        self::assertMatchesRegularExpression('~<button type="button" class="admin-tab" role="tab" id="tab-drafts" aria-controls="panel-drafts" aria-selected="false" tabindex="-1" data-tab="drafts">Drafts <span class="admin-tab-count">1</span></button>~', $body, 'the count pill is the total, not the shown five');
        self::assertMatchesRegularExpression('~<section class="admin-tab-panel admin-tab-panel-open" role="tabpanel" id="panel-recent" aria-labelledby="tab-recent">\s*<h3 class="admin-tab-heading">Recently updated</h3>~', $body);
        self::assertMatchesRegularExpression('~<section class="admin-tab-panel" role="tabpanel" id="panel-drafts" aria-labelledby="tab-drafts">\s*<h3 class="admin-tab-heading">Drafts</h3>~', $body, 'without JavaScript every list shows under its heading');
        self::assertStringContainsString('<span class="admin-chip admin-chip-draft">Draft</span>', $body);
        self::assertMatchesRegularExpression('~<li class="admin-panel-item admin-tone-1">\s*<span class="admin-dash-dot" aria-hidden="true"></span>\s*<div class="admin-panel-text">\s*<a href="/admin/content/articles/' . $draft->id . '">Work in progress</a>\s*<span class="admin-panel-meta">Articles · EN</span>\s*</div>\s*<span class="admin-chip admin-chip-draft">Draft</span>\s*</li>~', $body, 'the dot of its collection, the title and its collection, the status');
        self::assertStringContainsString('Coming soon', $body);
        self::assertStringContainsString('2026-09-29 12:00', $body);
        self::assertStringNotContainsString('(UTC)', $body);
        self::assertStringContainsString('Live &lt;b&gt;', $body);
        self::assertStringNotContainsString('Live <b>', $body);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/tabs\.js\?v=[0-9a-f]{12}" nonce="[^"]+"></script>~', $body, 'the tabs pattern of the forms');
    }

    public function testEachListShowsAtMostFiveEntries(): void
    {
        foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six'] as $title) {
            $this->entry('articles', 'Draft ' . $title, ['status' => EntryStatus::Draft]);
            $this->entry('articles', 'Soon ' . $title, ['status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        }
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin')->getBody();

        preg_match_all('~<section class="admin-tab-panel[^"]*" role="tabpanel" id="panel-([a-z]+)"[^>]*>(.*?)</section>~s', $body, $panels);
        self::assertSame(['recent', 'drafts', 'scheduled'], $panels[1]);
        foreach ($panels[2] as $index => $panel) {
            self::assertSame(5, substr_count($panel, '<li class="admin-panel-item'), $panels[1][$index]);
        }
        self::assertSame(5, preg_match_all('~>Draft (One|Two|Three|Four|Five|Six)</a>~', $panels[2][1]), 'a sixth draft is not listed');
        self::assertSame(5, preg_match_all('~>Soon (One|Two|Three|Four|Five|Six)</a>~', $panels[2][2]), 'a sixth scheduled entry is not listed');
        self::assertStringContainsString('data-tab="drafts">Drafts <span class="admin-tab-count">6</span>', $body);
        self::assertStringContainsString('data-tab="scheduled">Scheduled <span class="admin-tab-count">6</span>', $body);
    }

    public function testUsersSeeOnlyWhatTheyMayViewAndLinksOnlyWhereTheyMayEdit(): void
    {
        $this->entry('articles', 'Someone else draft', ['status' => EntryStatus::Draft]);

        $this->actingAs($this->createUser('au@example.com', 'author'));
        $author = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('Someone else draft', $author);
        self::assertStringNotContainsString('>Someone else draft</a>', $author);

        $this->actingAs($this->createUser('nobody@example.com', 'nobody'));
        $nobody = (string) $this->browser->get('/admin')->getBody();
        self::assertStringNotContainsString('Someone else draft', $nobody);
        self::assertSame(3, substr_count($nobody, 'Nothing here yet.'));
        self::assertStringNotContainsString('admin-dash-quick', $nobody, 'no quick start without a collection to add to');
        self::assertStringNotContainsString('admin-dash-tiles', $nobody, 'no numbers without a collection, messages or files to count');
        self::assertStringNotContainsString('id="dashboard-activity"', $nobody);
    }

    public function testQuickStartOffersTheCollectionsTheUserMayAddTo(): void
    {
        $this->actingAs($this->createUser('re@example.com', 'reporter'));

        $body = (string) $this->browser->get('/admin')->getBody();

        // Read from the DOM, so the icon's markup may change.
        $links = HTMLDocument::createFromString($body, LIBXML_NOERROR)->querySelectorAll('.admin-dash-quick a.admin-dash-quick-link');
        $buttons = [];
        foreach ($links as $link) {
            $buttons[] = [
                'href' => $link->getAttribute('href'),
                'tone' => preg_match('/\badmin-tone-(\d)\b/', (string) $link->getAttribute('class'), $tone) === 1 ? $tone[1] : null,
                'name' => $link->getAttribute('aria-label'),
                'icon' => (string) preg_replace('/^.*#/', '', (string) $link->querySelector('.admin-dash-quick-icon use')?->getAttribute('href')),
                'text' => trim((string) $link->textContent),
            ];
        }
        self::assertSame(['/admin/content/articles/new', '/admin/content/contact'], array_column($buttons, 'href'), 'pages are viewable but not creatable');
        self::assertSame(['1', '3'], array_column($buttons, 'tone'), 'the colour of each collection by its place in the config');
        self::assertSame(['Add new: Articles', 'Edit: contact'], array_column($buttons, 'name'), 'the visible label is part of the name');
        self::assertSame(['plus', 'pencil'], array_column($buttons, 'icon'));
        self::assertSame(['Articles', 'contact'], array_column($buttons, 'text'));
    }
}
