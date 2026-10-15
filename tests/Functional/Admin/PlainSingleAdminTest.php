<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

final class PlainSingleAdminTest extends ContentFunctionalTestCase
{
    public function testThePlainFormShowsOnlyTheFieldsAndPublishesOnSave(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/welcome/new?locale=en')->getBody();

        self::assertStringContainsString('<h1>Welcome <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>', $form);
        self::assertStringContainsString('<div class="admin-form-layout admin-form-layout-wide">', $form);
        self::assertStringContainsString('<div class="admin-field admin-field-rich-text admin-field-tall">', $form);
        self::assertMatchesRegularExpression('~<textarea id="field-intro" name="fields\[intro\]" rows="24" data-rich-text~', $form);
        self::assertStringNotContainsString('admin-translations', $form, 'a new entry has no Translations line: the top bar switches its language');
        foreach (['name="title"', 'name="status"', 'name="published_at"', 'name="meta_title"', 'name="og_image_id"', 'id="tab-settings"', 'id="tab-seo"', 'id="tab-social"'] as $missing) {
            self::assertStringNotContainsString($missing, $form, $missing);
        }

        $response = $this->browser->post('/admin/content/welcome', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => '',
            'status' => 'draft',
            'fields' => ['intro' => '<p>Hello</p>'],
        ]);

        self::assertSame(303, $response->getStatusCode());
        $entry = $this->app->container()->service(EntryRepository::class)->first('welcome', 'en') ?? self::fail('Not saved');
        self::assertSame(['Welcome', EntryStatus::Published], [$entry->title, $entry->status]);
        $page = $this->browser->get('/welcome');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('<h1>Welcome</h1>', (string) $page->getBody());
    }

    public function testSavingNeedsThePublishPermission(): void
    {
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $response = $this->browser->post('/admin/content/welcome', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => '',
            'fields' => ['intro' => '<p>Hello</p>'],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('You cannot publish in this section.', (string) $response->getBody());
        self::assertNull($this->app->container()->service(EntryRepository::class)->first('welcome', 'en'));
    }
}
