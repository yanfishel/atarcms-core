<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Profile;

use AtarCms\Auth\UserRepository;
use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\FunctionalTestCase;
use AtarCms\Tests\Support\Regex;

/**
 * The Profile (/admin/profile): a read-only card of the signed-in user under the page header, then the form with one
 * tab "Interface" (the interface language and the admin colour) and one Save.
 */
final class ProfilePageTest extends FunctionalTestCase
{
    public function testTheCardShowsWhoIsSignedInOutsideTheForm(): void
    {
        $ann = $this->createUser('ann@example.com', 'editor', 'Ann <b>Lee</b>');
        $this->actingAs($ann);

        $page = (string) $this->browser->get('/admin/profile')->getBody();

        $card = Regex::match('~<h1>Profile</h1>\s*</div>\s*<div class="admin-entry-form">\s*<div class="admin-form-layout admin-profile-layout">\s*(<section class="admin-card admin-profile-card".*?</section>)\s*<form class="admin-profile-form" method="post" action="/admin/profile">~s', $page, 'first in the two-column layout (shown in the side column), outside the form');
        self::assertStringContainsString('<span class="admin-avatar admin-avatar-large admin-role admin-role-editor" aria-hidden="true">A&lt;</span>', $card[1], 'the initials (escaped) with the role dot');
        self::assertStringContainsString('<h2 class="admin-profile-name" id="profile-name">Ann &lt;b&gt;Lee&lt;/b&gt;</h2>', $card[1]);
        self::assertStringContainsString('<p class="admin-user-meta">ann@example.com</p>', $card[1]);
        self::assertStringContainsString('<span class="admin-chip admin-role admin-role-editor">Editor</span>', $card[1]);
        self::assertStringContainsString('<p class="admin-user-meta">Last sign-in: never</p>', $card[1]);
        self::assertStringNotContainsString('<b>', $page);
    }

    /**
     * The card is first in the markup ("who am I" first when the columns stack) and sits in the side column beside the
     * form (grid columns follow the direction: on the left in Hebrew).
     */
    public function testTheCardTakesTheSideColumnAndStacksFirst(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/admin/assets/admin.css');

        self::assertStringContainsString('.admin-profile-layout > .admin-profile-card { grid-column: 2; grid-row: 1; }', $css);
        self::assertMatchesRegularExpression('~@container entry-form \(max-width: 879px\) \{\s*\.admin-form-layout \{ grid-template-columns: minmax\(0, 1fr\); \}\s*\.admin-profile-layout > \.admin-profile-card \{ grid-column: auto; grid-row: auto; \}~', $css);
    }

    public function testTheCardShowsThePhotoAndTheLastSignIn(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $users = $this->app->container()->service(UserRepository::class);
        $users->setAvatarVersion($ann->id, 'abc123');
        $users->recordLogin($ann->id);
        $this->actingAs($ann);

        $page = (string) $this->browser->get('/admin/profile')->getBody();

        self::assertStringContainsString('<span class="admin-avatar admin-avatar-large admin-role admin-role-admin" aria-hidden="true"><img class="admin-avatar-image" src="/admin/avatar/' . $ann->id . '?v=abc123" alt="" width="72" height="72"></span>', $page);
        self::assertMatchesRegularExpression('~<p class="admin-user-meta">Last sign-in: [^<]*\d{4}[^<]*\d{2}:\d{2}</p>~u', $page, 'the readable date with the time');
    }

    public function testOneInterfaceTabHoldsTheLanguageAndTheColour(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(themeColor: '#1e5e55'), null, null);
        $this->actingAs($this->createUser('ann@example.com'));

        $page = (string) $this->browser->get('/admin/profile')->getBody();

        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/tabs\.js\?v=[0-9a-f]+"~', $page);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/media\.js\?v=[0-9a-f]+"~', $page);
        self::assertStringContainsString('<div class="admin-form-column" data-tabs="">', $page);
        self::assertMatchesRegularExpression('~<div class="admin-tab-list" role="tablist" aria-label="Profile settings">\s*<button type="button" class="admin-tab" role="tab" id="tab-interface" aria-controls="panel-interface" aria-selected="true" tabindex="0" data-tab="interface" data-errors="0">\s*Interface\s*</button>\s*</div>~', $page, 'one tab');
        $panel = Regex::match('~<section class="admin-tab-panel admin-card admin-tab-panel-open" role="tabpanel" id="panel-interface" aria-labelledby="tab-interface">\s*<h2 class="admin-tab-heading">Interface</h2>.*?</section>~s', $page);
        self::assertMatchesRegularExpression('~<span class="admin-field-label" id="locale-label"><svg[^>]*><use href="[^"]+#languages"></use></svg>Interface language</span>\s*<details class="admin-menu admin-media-dropdown" data-media-dropdown>\s*<summary class="admin-media-dropdown-trigger" title="Interface language: English" aria-label="Interface language: English"~', $panel[0], 'a dropdown like the colour\'s');
        self::assertSame(5, preg_match_all('~<label class="admin-media-option"><input type="radio" class="admin-visually-hidden" name="locale" value="([a-z]+)"( checked)? aria-describedby="locale-help"><span class="admin-media-option-icon" data-option-icon>(.*?)</span><span class="admin-media-option-text" data-option-text lang="\1" dir="(ltr|rtl)">([^<]+)</span>~', $panel[0], $languages));
        self::assertSame(['en', 'ru', 'he', 'de', 'fr'], $languages[1]);
        self::assertSame([' checked', '', '', '', ''], $languages[2]);
        self::assertSame(['English', 'Русский', 'עברית', 'Deutsch', 'Français'], $languages[5], 'each named in itself');
        self::assertSame(['ltr', 'ltr', 'rtl', 'ltr', 'ltr'], $languages[4]);
        foreach ($languages[3] as $index => $mark) {
            self::assertMatchesRegularExpression('~^(<img class="admin-locale-flag" src="[^"]+/flags/' . $languages[1][$index] . '\.png[^"]*" alt="" width="20" height="20">|<svg[^>]*><use href="[^"]+#languages"></use></svg>)$~', $mark, 'the theme\'s round flag, else the languages icon');
        }
        self::assertMatchesRegularExpression('~<span class="admin-field-label" id="accent-label"><svg[^>]*><use href="[^"]+#palette"></use></svg>Admin color</span>~', $panel[0]);
        self::assertSame(9, preg_match_all('~<label class="admin-media-option"><input type="radio" class="admin-visually-hidden" name="accent" value="([a-z]*)"( checked)? aria-describedby="accent-help"><span class="admin-media-option-icon" data-option-icon><span class="admin-accent-swatch admin-accent-swatch-([a-z]+)"></span></span><span class="admin-media-option-text" data-option-text>([^<]+)</span>~', $panel[0], $options));
        self::assertSame(['', 'green', 'teal', 'blue', 'indigo', 'violet', 'cherry', 'terracotta', 'graphite'], $options[1]);
        self::assertSame(' checked', $options[2][0], 'without a choice the site colour is chosen');
        self::assertSame('teal', $options[3][0], 'its swatch is the site colour');
        self::assertSame('Site color (Teal)', $options[4][0]);
        self::assertStringContainsString('title="Admin color: Site color (Teal)"', $panel[0]);
        self::assertStringContainsString('<span class="admin-help" id="accent-help">Only for you. The site color follows the theme color in the site settings.</span>', $panel[0]);
        self::assertMatchesRegularExpression('~</section>\s*</div>\s*<div class="admin-action-bar">.*?Save</button>~s', $page, 'one Save after the tabs');
    }

    public function testTheUsersChoiceIsChecked(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->app->container()->service(UserRepository::class)->setAccent($ann->id, AdminAccent::Indigo);
        $this->actingAs($ann);

        $page = (string) $this->browser->get('/admin/profile')->getBody();

        self::assertStringContainsString('name="accent" value="indigo" checked aria-describedby="accent-help">', $page);
        self::assertStringContainsString('name="accent" value="" aria-describedby="accent-help"><span', $page);
        self::assertStringContainsString('data-accent="indigo"', $page);
    }

    public function testHebrewLaysTheProfileOutRightToLeft(): void
    {
        $ann = $this->createUser('ann@example.com', 'author', 'Dana');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'he');
        $this->actingAs($ann);

        $page = (string) $this->browser->get('/admin/profile')->getBody();

        self::assertStringContainsString('<html lang="he" dir="rtl"', $page);
        self::assertStringContainsString('>ממשק', $page);
        self::assertStringContainsString('כניסה אחרונה: אף פעם', $page);
        self::assertStringContainsString('admin-role-author', $page);
    }
}
