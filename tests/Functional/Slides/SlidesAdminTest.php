<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Slides;

use AtarCms\Content\EntryRepository;
use AtarCms\Media\MediaRepository;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * A slides field (Field::slides()) on the entry form: a main tab of its own, named by the field's label.
 */
final class SlidesAdminTest extends ContentFunctionalTestCase
{
    public function testAPlainFormWithSlidesHasTheContentAndSlidesTabs(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/welcome/new?locale=en')->getBody();

        self::assertStringContainsString('<div class="admin-form-layout admin-form-layout-wide">', $form, 'still one column: no side tabs');
        self::assertSame(['content' => 'Content', 'slides-hero' => 'Slides'], self::tabs($form));
        self::assertMatchesRegularExpression('~<section class="admin-tab-panel admin-card" role="tabpanel" id="panel-slides-hero" aria-labelledby="tab-slides-hero">\s*<h2 class="admin-tab-heading">Slides</h2>~', $form);
    }

    public function testAnyCollectionGetsTheSlidesTabAfterItsOwnTabs(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/pages/new?locale=en')->getBody();

        self::assertSame(['content' => 'Content', 'slides-hero' => 'Slides', 'settings' => 'Settings', 'seo' => 'SEO'], self::tabs($form));
    }

    public function testASlidesErrorCountsOnTheSlidesTab(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/pages', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => '',
            'title' => 'About',
            'status' => 'draft',
            'fields' => ['hero' => ['0' => ['image' => '', 'title' => 'No photo']]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $form = (string) $response->getBody();
        self::assertMatchesRegularExpression('~id="tab-slides-hero" aria-controls="panel-slides-hero" aria-selected="true" tabindex="0" data-tab="slides-hero" data-errors="1"~', $form);
        self::assertMatchesRegularExpression('~id="tab-content" aria-controls="panel-content" aria-selected="false" tabindex="-1" data-tab="content" data-errors="0"~', $form);
        self::assertStringContainsString('Slide 1: choose an image.', $form);
    }

    public function testTheWidgetIsARepeaterWithoutJavaScriptAndTheDialogFollowsTheForm(): void
    {
        $sea = $this->insertMedia('sea.jpg');
        $page = $this->entry('pages', 'About', ['fields' => ['hero' => [['image' => $sea, 'title' => 'Sea <b>', 'subtitle' => 'Blue', 'url' => '/news', 'button' => 'Read']]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/content/pages/' . $page->id);
        $form = (string) $response->getBody();
        preg_match("~script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'~", $response->getHeaderLine('Content-Security-Policy'), $csp);

        self::assertMatchesRegularExpression('~<div class="admin-slides" id="field-hero" data-slides="hero" data-next="2" data-max="3" data-untitled="Untitled slide" data-has-video="Video: #">~', $form);
        foreach (['image' => (string) $sea, 'title' => 'Sea &lt;b&gt;', 'subtitle' => 'Blue', 'url' => '/news', 'button' => 'Read', 'position' => '1'] as $part => $value) {
            self::assertMatchesRegularExpression('~name="fields\[hero\]\[0\]\[' . $part . '\]"[^>]* value="' . preg_quote($value, '~') . '"~', $form, $part);
        }
        self::assertStringContainsString('name="fields[hero][0][delete]" value="1"', $form, 'the no-JS delete box');
        self::assertMatchesRegularExpression('~<li class="admin-slides-item admin-slides-blank" data-slides-item data-slides-blank data-file="" data-video-file="" data-video-url="">~', $form, 'one empty group for a new slide');
        self::assertStringContainsString('name="fields[hero][1][title]"', $form);
        self::assertStringNotContainsString('name="fields[hero][1][delete]"', $form, 'nothing to delete in the new group');
        self::assertMatchesRegularExpression('~<span class="admin-slides-title" data-slides-title>Sea &lt;b&gt;</span>~', $form);
        self::assertMatchesRegularExpression('~<span class="admin-slides-preview" data-slides-preview><img class="admin-media-thumb" src="/media/admin/[^"]+"~', $form);
        self::assertMatchesRegularExpression('~<template data-slides-template><li class="admin-slides-item" data-slides-item data-file="" data-video-file="" data-video-url="">.*name="fields\[hero\]\[__index__\]\[title\]".*</li></template>~s', $form);
        self::assertMatchesRegularExpression('~<button type="button" class="admin-button admin-button-secondary admin-slides-add" data-slides-add hidden>~', $form);

        // The dialog sits after the entry form (its close button is a form of its own) in the shared layout.
        $after = substr($form, (int) strpos($form, '</form>', (int) strpos($form, 'data-entry-form')));
        self::assertMatchesRegularExpression(
            '~<dialog class="admin-dialog admin-slides-dialog" data-slides-dialog="hero" aria-labelledby="slides-hero-heading" data-title-add="Add slide" data-title-edit="Edit slide">\s*'
            . '<div class="admin-dialog-header">\s*<h2 id="slides-hero-heading">Add slide</h2>\s*<form class="admin-dialog-close" method="dialog">.*?</form>\s*</div>\s*'
            . '<div class="admin-dialog-body">.*?data-media-field data-multiple="0" data-kind="image".*?</div>\s*'
            . '<div class="admin-dialog-footer">\s*<button type="button" class="admin-button admin-button-secondary" data-slides-cancel>Cancel</button>\s*<button type="button" class="admin-button admin-button-primary" data-slides-done>Done</button>\s*</div>\s*</dialog>~s',
            $after,
        );
        foreach (['title', 'subtitle', 'url', 'button'] as $part) {
            self::assertMatchesRegularExpression('~id="slides-hero-' . $part . '"[^>]* data-slides-field="' . $part . '"~', $after, $part);
        }
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/slides\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($csp[1] ?? '', '~') . '"></script>~', $form);
        self::assertStringContainsString('data-media-dialog', $form, 'the picker for the slides\' images');
        self::assertStringNotContainsString(' style=', $form);
    }

    public function testWithoutJavaScriptPositionsReorderAndDeleteRemoves(): void
    {
        [$a, $b, $c] = [$this->insertMedia('a.jpg'), $this->insertMedia('b.jpg'), $this->insertMedia('c.jpg')];
        $page = $this->entry('pages', 'About', ['fields' => ['hero' => [['image' => $a], ['image' => $b]]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/pages/' . $page->id, [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => $page->updatedAt->format('Y-m-d H:i:s'),
            'title' => 'About',
            'slug' => 'about',
            'status' => 'published',
            'fields' => ['hero' => [
                '0' => ['image' => (string) $a, 'title' => 'A', 'position' => '2', 'delete' => '1'],
                '1' => ['image' => (string) $b, 'title' => 'B', 'position' => '3'],
                '2' => ['image' => (string) $c, 'title' => 'C', 'position' => '1'],
            ]],
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame([['image' => $c, 'title' => 'C'], ['image' => $b, 'title' => 'B']], $this->app->container()->service(EntryRepository::class)->find($page->id)?->data['hero']);
    }

    /**
     * Autosave posts the form's fields as they are: nested slide rows round-trip into the draft and back into the form.
     */
    public function testAnAutosavedDraftKeepsItsSlides(): void
    {
        $sea = $this->insertMedia('sea.jpg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/pages/autosave', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Draft page',
            'status' => 'draft',
            'fields' => ['hero' => ['7' => ['image' => (string) $sea, 'title' => 'Typed', 'subtitle' => '', 'url' => '', 'button' => '', 'position' => '1']]],
        ], ['Accept' => 'application/json']);

        self::assertSame(200, $response->getStatusCode());
        $draft = $this->app->container()->service(EntryRepository::class)->findBySlug('pages', 'en', 'draft-page') ?? self::fail('Not autosaved');
        self::assertSame([['image' => $sea, 'title' => 'Typed']], $draft->data['hero']);

        $form = (string) $this->browser->get('/admin/content/pages/' . $draft->id)->getBody();
        self::assertMatchesRegularExpression('~name="fields\[hero\]\[0\]\[title\]"[^>]* value="Typed"~', $form);
        self::assertStringContainsString('<span class="admin-slides-title" data-slides-title>Typed</span>', $form);
    }

    public function testARefusedSaveRendersThePostedRowsAgain(): void
    {
        $sea = $this->insertMedia('sea.jpg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/pages', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => '',
            'title' => 'About',
            'status' => 'draft',
            'fields' => ['hero' => [
                '3' => ['image' => (string) $sea, 'title' => 'Kept', 'url' => 'javascript:alert(1)'],
                '4' => ['image' => '', 'title' => ''],
            ]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $form = (string) $response->getBody();
        self::assertStringContainsString('Slide 1: enter an http(s) address', $form);
        self::assertMatchesRegularExpression('~name="fields\[hero\]\[0\]\[url\]"[^>]* value="javascript:alert\(1\)"~', $form, 'the posted value stays for correcting');
        self::assertMatchesRegularExpression('~<div class="admin-slides" id="field-hero"[^>]* aria-describedby="field-hero-error">~', $form);
    }

    public function testASlideWithAVideoRoundTripsThroughTheForm(): void
    {
        $sea = $this->insertMedia('sea.jpg');
        $waves = $this->insertMedia('waves <1>.mp4', 'video/mp4');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/pages', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => '',
            'title' => 'About',
            'status' => 'draft',
            'fields' => ['hero' => ['0' => ['image' => (string) $sea, 'video' => (string) $waves, 'title' => 'Waves', 'position' => '1']]],
        ]);

        self::assertSame(303, $response->getStatusCode());
        $page = $this->app->container()->service(EntryRepository::class)->findBySlug('pages', 'en', 'about') ?? self::fail('Not saved');
        self::assertSame([['image' => $sea, 'video' => $waves, 'title' => 'Waves']], $page->data['hero']);

        $form = (string) $this->browser->get('/admin/content/pages/' . $page->id)->getBody();
        self::assertMatchesRegularExpression('~name="fields\[hero\]\[0\]\[video\]"[^>]* value="' . $waves . '"~', $form, 'the no-JS video number');
        self::assertMatchesRegularExpression('~<li class="admin-slides-item" data-slides-item data-file="sea\.jpg" data-video-file="waves &lt;1&gt;\.mp4" data-video-url="/uploads/2026/09/[0-9a-f]{32}\.mp4">~', $form, 'the row carries the video\'s address for the dialog\'s player');
        self::assertStringContainsString('<span class="admin-slides-video" data-slides-video role="img" title="Video: waves &lt;1&gt;.mp4" aria-label="Video: waves &lt;1&gt;.mp4">', $form, 'the row marks a slide with a video');
        self::assertMatchesRegularExpression('~<li class="admin-slides-item admin-slides-blank"[^>]*>.*?<span class="admin-slides-video" data-slides-video role="img" title="" aria-label="" hidden>~s', $form, 'a slide without a video has the marker hidden');

        $after = substr($form, (int) strpos($form, '</form>', (int) strpos($form, 'data-entry-form')));
        self::assertMatchesRegularExpression('~<div class="admin-field" data-slides-media="video">\s*<label for="slides-hero-video">.*?Video</label>\s*<div class="admin-media-field" data-media-field data-multiple="0" data-kind="video">~s', $after, 'the dialog\'s optional video, picked from the videos');
        self::assertStringContainsString('Plays instead of the picture; the picture is the poster', $after);
        self::assertMatchesRegularExpression('~<div class="admin-field" data-slides-media="image">\s*<label for="slides-hero-image">~', $after);
    }

    public function testADocumentAsTheVideoIsRefusedAtTheSlidesTab(): void
    {
        $sea = $this->insertMedia('sea.jpg');
        $report = $this->insertMedia('report.pdf', 'application/pdf');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/pages', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => '',
            'title' => 'About',
            'status' => 'draft',
            'fields' => ['hero' => ['0' => ['image' => (string) $sea, 'video' => (string) $report]]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $form = (string) $response->getBody();
        self::assertMatchesRegularExpression('~id="tab-slides-hero" aria-controls="panel-slides-hero" aria-selected="true" tabindex="0" data-tab="slides-hero" data-errors="1"~', $form);
        self::assertStringContainsString('Slide 1: choose a video (MP4 or WebM).', $form);
        self::assertMatchesRegularExpression('~name="fields\[hero\]\[0\]\[video\]"[^>]* value="' . $report . '"~', $form, 'the posted number stays for correcting');
    }

    private function insertMedia(string $name, string $mime = 'image/jpeg'): int
    {
        $now = $this->clock->now();
        $image = str_starts_with($mime, 'image/');

        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . (['video/mp4' => '.mp4', 'application/pdf' => '.pdf'][$mime] ?? '.jpg'),
            'original_name' => $name,
            'mime' => $mime,
            'size' => 100,
            'width' => $image ? 64 : null,
            'height' => $image ? 48 : null,
            'alt' => [],
            'uploaded_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, string> tab name => its visible label
     */
    private static function tabs(string $form): array
    {
        preg_match_all('~<button type="button" class="admin-tab" role="tab" id="tab-([a-z0-9-]+)"[^>]*>\s*([^<]+?)\s*<~', $form, $matches);

        return array_combine($matches[1], $matches[2]);
    }
}
