<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Content\EntryRepository;
use AtarCms\Kernel\Container;
use AtarCms\Media\MediaRepository;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\Cast;

final class EntryMediaFormTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Roles::class, new Roles([...Roles::DEFAULTS, 'writer' => ['label' => 'Writer', 'permissions' => ['entries.*.*']]]));
    }

    public function testTheFormHasMediaWidgetsAndThePicker(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/content/articles/new');
        $body = (string) $response->getBody();
        preg_match("~script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'~", $response->getHeaderLine('Content-Security-Policy'), $csp);

        self::assertStringContainsString('data-media-field data-multiple="0" data-kind="image"', $body);
        self::assertStringContainsString('data-media-field data-multiple="1" data-kind="image"', $body);
        self::assertStringContainsString('data-media-field data-multiple="0" data-kind="all"', $body);
        self::assertStringContainsString('id="field-cover" name="fields[cover]"', $body);
        self::assertStringContainsString('id="field-images" name="fields[images]"', $body);
        self::assertStringNotContainsString('og_image_id', $body, 'the sharing image is made by the site');
        self::assertStringContainsString('data-media-choose', $body);
        self::assertSame(3, preg_match_all('~<button type="button" class="admin-button admin-button-secondary admin-button-compact admin-media-choose" data-media-choose><svg[^>]*><use href="[^"]+#folder-open"~', $body), 'every Choose button is a compact one that opens the library with one icon');
        self::assertMatchesRegularExpression('~<label for="field-images"><svg[^>]*><use href="[^"]+#images"~', $body, 'a gallery has its own icon');
        self::assertStringContainsString('<dialog class="admin-dialog admin-media-dialog" data-media-dialog data-picker-url="/admin/media/picker"', $body);
        self::assertStringContainsString('<div class="admin-dialog-footer" data-picker-footer data-selected="Selected: #" data-none="Nothing selected" data-choose="Choose" data-add="Add (#)" data-already="Already in the gallery">', $body);
        self::assertStringContainsString('<p class="admin-media-picker-status" role="status" data-picker-status>Nothing selected</p>', $body);
        self::assertStringContainsString('<button type="button" class="admin-button admin-button-link" hidden data-picker-clear>Clear selection</button>', $body);
        self::assertStringContainsString('<button type="button" class="admin-button admin-button-secondary" data-picker-cancel>Cancel</button>', $body);
        self::assertStringContainsString('<button type="button" class="admin-button admin-button-primary" disabled data-picker-choose>Choose</button>', $body);
        self::assertStringNotContainsString('data-main-label', $body, 'a gallery shows no "Main" marker (a user decision)');
        self::assertStringNotContainsString('data-remove-label', $body, 'media.js clones the remove button instead of building it from a label');
        self::assertSame(3, preg_match_all('~<template data-media-remove-template><button type="button" class="admin-media-remove" data-media-remove title="Remove" aria-label="Remove"><svg[^>]*aria-hidden="true"[^>]*><use href="[^"]+#x"></use></svg></button></template>~', $body), 'one per widget: cover, images and attachment');
        self::assertMatchesRegularExpression('~<dialog class="admin-dialog admin-media-dialog" [^>]*>.*<div class="admin-toasts" data-toasts aria-live="polite"></div>.*</dialog>~s', $body, 'the modal dialog has its own toast stack: the page stack lies under the top layer');
        self::assertStringContainsString('<span class="admin-help admin-media-order-help">Drag the photos to change their order.</span>', $body, 'the gallery explains its order (admin.css shows it only while the gallery holds a photo and media.js can sort)');
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/media\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($csp[1] ?? '', '~') . '"></script>~', $body);
        self::assertStringNotContainsString(' style=', $body);
    }

    public function testUsersWithoutUploadPermissionKeepTheIdsButGetNoPicker(): void
    {
        $writer = new Browser($this->app);
        $this->actingAs($this->createUser('wr@example.com', 'writer'), $writer);

        $body = (string) $writer->get('/admin/content/articles/new')->getBody();

        self::assertStringContainsString('name="fields[cover]"', $body);
        self::assertStringNotContainsString('data-media-choose', $body);
        self::assertStringNotContainsString('data-media-dialog', $body);
    }

    public function testFormsWithoutMediaWidgetsDoNotLoadTheScript(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/notes/new')->getBody();

        self::assertStringNotContainsString('media.js', $body);
        self::assertStringNotContainsString('data-media-dialog', $body);
    }

    public function testSavesAndShowsTheChosenMedia(): void
    {
        $sea = $this->insertMedia('Sea <b>.jpg');
        $sky = $this->insertMedia('sky.jpg');
        $pdf = $this->insertMedia('prices.pdf', 'application/pdf');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'With media',
            'status' => 'draft',
            'fields' => ['cover' => (string) $sea, 'images' => $sky . ',' . $sea, 'attachment' => (string) $pdf],
        ]);

        self::assertSame(303, $response->getStatusCode());
        $entry = $this->app->container()->service(EntryRepository::class)->findBySlug('articles', 'en', 'with-media') ?? self::fail('not saved');
        self::assertSame(['featured' => false, 'cover' => $sea, 'images' => [$sky, $sea], 'attachment' => $pdf], $entry->data);

        $form = (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody();
        self::assertStringContainsString('name="fields[images]" value="' . $sky . ',' . $sea . '"', $form);
        self::assertStringContainsString('Sea &lt;b&gt;.jpg', $form);
        self::assertMatchesRegularExpression('~<li data-id="' . $sea . '" data-name="Sea &lt;b&gt;\.jpg">\s*<img class="admin-media-thumb" src="/media/admin/2026/09/[0-9a-f]{32}\.jpg\.webp\?v=[0-9a-f]{8}"~', $form);
        self::assertStringContainsString('<span class="admin-media-thumb admin-media-file">PDF</span>', $form);
        self::assertMatchesRegularExpression('~<li data-id="' . $pdf . '" data-name="prices\.pdf">\s*<span class="admin-media-thumb admin-media-file">PDF</span>\s*<span class="admin-media-name" title="prices\.pdf">prices\.pdf</span>\s*<button type="button" class="admin-media-remove" data-media-remove title="Remove" aria-label="Remove"><svg[^>]*><use href="[^"]+#x"></use></svg></button>\s*</li>~', $form, 'an icon-only remove button in the corner');
        self::assertStringNotContainsString('</svg>Remove</button>', $form);
        self::assertStringNotContainsString('admin-media-main', $form, 'no "Main" marker on the first gallery photo (a user decision)');
        self::assertStringContainsString('<span data-tab-counter><span class="admin-tab-count" aria-hidden="true" data-tab-count>4</span><span class="admin-visually-hidden">Files chosen: <span data-tab-count>4</span></span></span>', $form, 'the Media tab counts the chosen files; media.js updates the numbers');
    }

    /**
     * Each widget's label names its ids input (the control without JavaScript), and an empty media tab has a hidden
     * counter for media.js to fill.
     */
    public function testTheWidgetsAreLabelledAndTheMediaTabHasACounter(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/articles/new')->getBody();

        foreach (['field-cover' => 'cover', 'field-images' => 'images', 'field-attachment' => 'attachment'] as $id => $name) {
            self::assertMatchesRegularExpression('~<label for="' . $id . '"><svg[^>]*>.*?</svg>[^<]+</label>~', $body, $name);
            self::assertStringContainsString('<input class="admin-media-ids" id="' . $id . '" name="fields[' . $name . ']"', $body, $name);
        }
        self::assertStringContainsString('<span data-tab-counter hidden><span class="admin-tab-count" aria-hidden="true" data-tab-count>0</span>', $body);
        self::assertSame(1, substr_count($body, 'data-tab-counter'), 'only the media tab counts');
    }

    public function testRejectsUnknownMediaAndNonImages(): void
    {
        $pdf = $this->insertMedia('prices.pdf', 'application/pdf');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $unknown = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Broken',
            'fields' => ['cover' => '999999', 'images' => (string) $pdf],
        ]);
        $body = (string) $unknown->getBody();

        self::assertSame(422, $unknown->getStatusCode());
        self::assertStringContainsString('name="fields[cover]" value="999999"', $body);
        self::assertStringContainsString('Choose files that already exist in “Photos and files”.', $body);
        self::assertStringContainsString('<span class="admin-media-name">#999999</span>', $body);
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));

        $wrongType = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Broken',
            'fields' => ['images' => (string) $pdf],
        ]);
        self::assertSame(422, $wrongType->getStatusCode());
        self::assertStringContainsString('Choose images only.', (string) $wrongType->getBody());
    }

    private function insertMedia(string $name, string $mime = 'image/jpeg'): int
    {
        $now = $this->clock->now();

        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . ($mime === 'application/pdf' ? '.pdf' : '.jpg'),
            'original_name' => $name,
            'mime' => $mime,
            'size' => 100,
            'width' => $mime === 'application/pdf' ? null : 64,
            'height' => $mime === 'application/pdf' ? null : 48,
            'alt' => [],
            'uploaded_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
