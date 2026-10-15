<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\AiGuide;

use AtarCms\Auth\AuditLog;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Content\Taxonomy;
use AtarCms\Media\MediaService;
use AtarCms\Messages\MessageService;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\FunctionalTestCase;

/**
 * The AI agent guide (/admin/ai-guide): one plain-text markdown document that describes the admin to an AI browser
 * agent, built from a static part and the site's own collections, taxonomies, menus and languages, limited to what the
 * signed-in user may open.
 */
final class AiGuideTest extends FunctionalTestCase
{
    public function testAGuestIsSentToTheLoginPage(): void
    {
        $response = $this->browser->get('/admin/ai-guide');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login?redirect=%2Fadmin%2Fai-guide', $response->getHeaderLine('Location'));
    }

    public function testASignedInUserGetsAPlainTextMarkdownDocument(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'author'));

        $response = $this->browser->get('/admin/ai-guide');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('# ', $body, 'a markdown document');
        self::assertStringContainsString('without JavaScript', $body, 'the static part describes the admin');
        self::assertStringContainsString('toast', $body);
    }

    public function testTheDocumentListsTheCollectionsWithTheirFields(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin/ai-guide')->getBody();

        self::assertStringContainsString('### Articles (`articles`)', $body);
        self::assertStringContainsString('- List: /admin/content/articles', $body);
        self::assertStringContainsString('- New entry: /admin/content/articles/new', $body);
        self::assertStringContainsString('- `summary`: textarea, optional, max 500 characters', $body);
        self::assertStringContainsString('- `rating`: number, optional, min 0, max 10', $body, 'a number limits its value');
        self::assertStringContainsString('- `related`: relation, optional, max 3 items, entries of `articles`', $body);
        self::assertStringContainsString('- `hero`: slides, optional, label "Slides", max 3 items', $body);
        self::assertStringContainsString('- `hero`: slides, optional, label "Slides", max 10 items', $body, 'the default limit of slides');
        self::assertStringContainsString('help "Leave it empty to show the whole story on its section page."', $body);
        self::assertStringContainsString('- `body`: richText, optional', $body);
        self::assertStringContainsString('- `cover`: image, optional, tab "media"', $body);
        self::assertStringContainsString('### contact (`contact`)', $body, 'a collection without a label is named by its name');
        self::assertStringContainsString('- `phone`: text, required, max 255 characters', $body, 'the default limit of text');
        self::assertStringContainsString('- Open: /admin/content/contact', $body, 'a single has one entry per language, opened from its list address');
        self::assertStringNotContainsString('/admin/content/contact/new', $body);
    }

    public function testTheDocumentListsTaxonomiesMenusAndLanguages(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin/ai-guide')->getBody();

        self::assertStringContainsString('- Site languages: en (default), ru', $body);
        self::assertStringContainsString('- Topics (`topics`, hierarchical): /admin/taxonomies/topics', $body);
        self::assertStringContainsString('- Tags (`tags`): /admin/taxonomies/tags', $body);
        self::assertStringContainsString('- Sections (`sections`, hierarchical): /admin/sections', $body, 'the root tree has its own screen');
        self::assertStringContainsString('- Main menu (`main`): /admin/menus/main', $body);
        self::assertStringContainsString('- Footer (`footer`): /admin/menus/footer', $body, 'a translation key as the label reads in English');
    }

    public function testSectionsTheUserMayNotOpenAreLeftOut(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/ai-guide')->getBody();

        self::assertStringNotContainsString('/admin/users', $body);
        self::assertStringNotContainsString('## Users', $body);
        self::assertStringNotContainsString('/admin/settings', $body);
        self::assertStringNotContainsString('/admin/audit', $body);
        self::assertStringNotContainsString('/admin/messages', $body, 'an editor of the default roles reads no messages');
        self::assertStringNotContainsString('## Messages', $body);
        self::assertStringContainsString('/admin/media', $body, 'an editor uploads files');
        self::assertStringContainsString('### Articles (`articles`)', $body);
    }

    /**
     * A plain single is always published, so its sidebar item needs the publish permission: an author, who may not
     * publish, gets neither the item nor the content type.
     */
    public function testAPlainSingleNeedsThePublishPermission(): void
    {
        $this->actingAs($this->createUser('al@example.com', 'author'));
        $author = (string) $this->browser->get('/admin/ai-guide')->getBody();
        $this->actingAs($this->createUser('ann@example.com'));
        $admin = (string) $this->browser->get('/admin/ai-guide')->getBody();

        self::assertStringNotContainsString('/admin/content/welcome', $author);
        self::assertStringNotContainsString('(`welcome`)', $author);
        self::assertStringContainsString('/admin/content/welcome', $admin);
        self::assertStringContainsString('(`welcome`)', $admin);
    }

    public function testASingleOfTheDefaultLanguageOnlyIsOneEntry(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin/ai-guide')->getBody();

        self::assertStringContainsString("### settings (`settings`)\n\n- Kind: single (one entry, in the default language only)\n", $body);
        self::assertStringContainsString("### contact (`contact`)\n\n- Kind: single (one entry per language), translatable\n", $body);
    }

    /**
     * The gating comments of resources/admin/ai-guide.md never reach the document, whoever reads it.
     */
    public function testNoRoleSeesAComment(): void
    {
        foreach (['admin', 'editor', 'author'] as $role) {
            $this->actingAs($this->createUser($role . '@example.com', $role));

            $response = $this->browser->get('/admin/ai-guide');

            self::assertSame(200, $response->getStatusCode(), $role);
            self::assertStringNotContainsString('<!--', (string) $response->getBody(), $role);
        }
    }

    /**
     * Every permission that gates a section of the static part is one the code checks, so a typo cannot hide a
     * section from everyone.
     */
    public function testEveryGatingPermissionIsKnown(): void
    {
        $known = [
            MediaService::UPLOAD,
            MediaService::DELETE,
            Taxonomy::PERMISSION,
            MenuService::PERMISSION,
            MessageService::PERMISSION,
            SettingsService::PERMISSION,
            AuditLog::PERMISSION,
            'users.manage',
        ];
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/admin/ai-guide.md');

        preg_match_all('/<!--\s*requires:\s*(\S+)\s*-->/', $source, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $permission) {
            self::assertContains($permission, $known);
        }
        self::assertSame(substr_count($source, '<!--'), count($matches[1]), 'the static part holds no other comments');
    }

    public function testAnAuthorSeesNeitherTaxonomiesNorMenus(): void
    {
        $this->actingAs($this->createUser('al@example.com', 'author'));

        $body = (string) $this->browser->get('/admin/ai-guide')->getBody();

        self::assertStringNotContainsString('/admin/taxonomies', $body);
        self::assertStringNotContainsString('/admin/menus', $body);
        self::assertStringNotContainsString('/admin/sections', $body);
        self::assertStringContainsString('### Articles (`articles`)', $body);
    }

    public function testAnAdminGetsTheUsersSection(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $body = (string) $this->browser->get('/admin/ai-guide')->getBody();

        self::assertStringContainsString('## Users', $body);
        self::assertStringContainsString('- Users: /admin/users', $body);
        self::assertStringContainsString('/admin/settings', $body);
        self::assertStringContainsString('/admin/audit', $body);
        self::assertStringContainsString('/admin/messages', $body);
    }

    public function testTheShellLinksTheGuide(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'author'));

        $body = (string) $this->browser->get('/admin')->getBody();

        self::assertStringContainsString('<link rel="help" href="/admin/ai-guide">', $body);
        self::assertMatchesRegularExpression('~<div class="admin-sidebar-footer">\s*<a class="admin-sidebar-guide" href="/admin/ai-guide">AI guide</a>~', $body);
    }

    public function testTheLoginPageDoesNotLinkTheGuide(): void
    {
        $body = (string) $this->browser->get('/admin/login')->getBody();

        self::assertStringNotContainsString('ai-guide', $body);
    }
}
