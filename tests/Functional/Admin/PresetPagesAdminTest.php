<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Preset\PresetPages;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * The fixture's "info" pages have the preset "legal": the list marks it with a lock chip, its form shows the address
 * read-only, no status or publication date and a locked Delete, and no crafted post moves, unpublishes or deletes it.
 */
final class PresetPagesAdminTest extends ContentFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->container()->service(PresetPages::class)->sync();
        $this->actingAs($this->createUser('admin@example.com'));
    }

    public function testTheListMarksThePresetWithALockChip(): void
    {
        $this->entry('info', 'About', ['slug' => 'about']);

        $list = (string) $this->browser->get('/admin/content/info?locale=en')->getBody();

        self::assertMatchesRegularExpression('~<td data-label="Author"><span class="admin-chip admin-chip-preset" title="A preset page: always published, its address is fixed and it cannot be deleted"><svg class="admin-icon" aria-hidden="true"><use href="[^"]+#lock"></use></svg>Preset<span class="admin-visually-hidden">: A preset page: always published, its address is fixed and it cannot be deleted</span></span></td>~', $list, 'in the Author column: a preset page has no author');
        self::assertSame(1, substr_count($list, 'admin-chip-preset'), 'only the preset');
        self::assertStringContainsString('Правовая информация', (string) $this->browser->get('/admin/content/info?locale=ru')->getBody());
    }

    public function testThePresetFormHasAFixedAddressNoStatusAndALockedDelete(): void
    {
        $entry = $this->preset('en');

        $form = (string) $this->browser->get('/admin/content/info/' . $entry->id)->getBody();

        self::assertMatchesRegularExpression('~<input id="slug" name="slug" maxlength="190" dir="ltr" value="legal" readonly aria-describedby="slug-preset">\s*<span class="admin-help" id="slug-preset">A preset page keeps its address in every language\.</span>~', $form);
        self::assertStringNotContainsString('name="status"', $form);
        self::assertStringNotContainsString('name="published_at"', $form);
        self::assertStringNotContainsString('/admin/content/info/' . $entry->id . '/delete', $form, 'no link to the delete confirmation');
        self::assertStringContainsString('<button type="button" class="admin-button admin-button-link admin-button-danger admin-action-compact admin-locked" aria-disabled="true" title="A preset page cannot be deleted." aria-describedby="entry-delete-reason">', $form);
        self::assertStringContainsString('<span class="admin-visually-hidden" id="entry-delete-reason">A preset page cannot be deleted.</span>', $form);
        self::assertStringNotContainsString('data-autosave=', $form, 'published: no autosave');
        self::assertStringContainsString('name="title"', $form, 'the title is the editor\'s');
    }

    public function testACraftedPostKeepsTheAddressAndThePublication(): void
    {
        $entry = $this->preset('en');

        $response = $this->browser->post('/admin/content/info/' . $entry->id, [
            '_csrf' => $this->csrfToken(),
            'version' => $entry->updatedAt->format('Y-m-d H:i:s'),
            'locale' => 'en',
            'title' => 'Legal information',
            'slug' => 'moved',
            'status' => 'draft',
            'published_at' => '2031-01-01T00:00',
            'fields' => ['body' => '<p>Terms</p>'],
        ]);

        self::assertSame(303, $response->getStatusCode());
        $saved = $this->preset('en');
        self::assertSame(['Legal information', 'legal', EntryStatus::Published], [$saved->title, $saved->slug, $saved->status]);
        self::assertEquals($entry->publishedAt, $saved->publishedAt);
        self::assertSame(200, $this->browser->get('/legal')->getStatusCode());
    }

    public function testThePresetCannotBeDeleted(): void
    {
        $entry = $this->preset('ru');

        self::assertSame(403, $this->browser->get('/admin/content/info/' . $entry->id . '/delete')->getStatusCode());
        $response = $this->browser->post('/admin/content/info/' . $entry->id . '/delete', ['_csrf' => $this->csrfToken()]);

        self::assertSame(403, $response->getStatusCode());
        self::assertNotNull($this->app->container()->service(EntryRepository::class)->find($entry->id));
    }

    public function testNoOtherPageTakesThePresetAddress(): void
    {
        $response = $this->browser->post('/admin/content/info', [
            '_csrf' => $this->csrfToken(),
            'version' => '',
            'locale' => 'ru',
            'title' => 'Право',
            'slug' => 'legal',
            'status' => 'draft',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('id="slug-error"', (string) $response->getBody());
    }

    private function preset(string $locale): Entry
    {
        return $this->app->container()->service(EntryRepository::class)->presetPages('info', 'legal')[$locale] ?? self::fail('No preset');
    }
}
