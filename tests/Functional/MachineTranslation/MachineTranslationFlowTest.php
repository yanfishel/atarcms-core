<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\MachineTranslation;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Preset\PresetPages;
use AtarCms\Content\Term;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * Plan 38: the machine button posts the translate action with "machine"; the copy is made as for "Copy for
 * translation" and its form opens with ?machine={source}&start=1, where the hidden panel (partials/machine-translation)
 * names the languages, the inputs to translate and the toasts translate.js clones. Nothing is translated on the server
 * and the copy stays a draft.
 */
final class MachineTranslationFlowTest extends ContentFunctionalTestCase
{
    public function testTheMarkerOpensTheCopysFormWithTheSourceLanguage(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'fields' => ['summary' => 'A summary', 'body' => '<p>Hi</p>']]);
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->post('/admin/content/articles/' . $hello->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'machine' => '1']);

        self::assertSame(303, $response->getStatusCode());
        $copy = $this->copyOf($hello, 'ru');
        self::assertSame('/admin/content/articles/' . $copy->id . '?locale=ru&machine=en&start=1', $response->getHeaderLine('Location'));
        self::assertSame(EntryStatus::Draft, $copy->status, 'the copy is a draft, as without the marker');
        self::assertSame('Hello', $copy->title, 'nothing is translated on the server');

        $form = (string) $this->browser->get($response->getHeaderLine('Location'))->getBody();
        self::assertMatchesRegularExpression(
            '~<div class="admin-machine" data-machine data-machine-from="en" data-machine-to="ru" data-machine-start data-machine-slug data-machine-fields="\[&quot;title&quot;,&quot;meta_title&quot;,&quot;meta_description&quot;,&quot;fields\[summary\]&quot;,&quot;fields\[body\]&quot;\]" data-machine-slides="\[\]" hidden>~',
            $form,
        );
        self::assertStringContainsString('<p class="admin-machine-note">Machine translation from English: check the texts before saving or publishing.</p>', $form);
        self::assertMatchesRegularExpression('~<button type="button" class="admin-button admin-button-secondary admin-button-small" data-machine-run><svg[^>]*><use href="[^"]+#languages"></use></svg>Translate from English</button>~', $form);
        self::assertStringContainsString('<progress class="admin-machine-progress" max="1" value="0" aria-label="Translation progress" data-machine-progress hidden></progress>', $form);
        self::assertMatchesRegularExpression('~<template data-machine-done><div class="admin-toast admin-toast-success" data-toast>.*?Translated from English\. Check the texts before saving\.~s', $form);
        self::assertMatchesRegularExpression('~<template data-machine-failed><div class="admin-toast admin-toast-error" role="alert" data-toast>.*?Machine translation did not work\. The texts were not changed\.~s', $form);
        self::assertGreaterThan((int) strpos($form, '<form class="admin-entry-form"'), (int) strpos($form, 'data-machine '), 'the panel sits inside the entry form');
        self::assertLessThan((int) strpos($form, 'admin-form-layout'), (int) strpos($form, 'data-machine '), 'above its columns');
        self::assertGreaterThan(strpos($form, 'editor.js?v='), strpos($form, 'translate.js?v='), 'translate.js comes after the editor');
    }

    public function testWithoutTheMarkerTheCopyIsPlain(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->post('/admin/content/articles/' . $hello->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);

        $copy = $this->copyOf($hello, 'ru');
        self::assertSame('/admin/content/articles/' . $copy->id . '?locale=ru', $response->getHeaderLine('Location'));
        $form = (string) $this->browser->get($response->getHeaderLine('Location'))->getBody();
        self::assertStringContainsString('Copy for translation created.', $form);
        self::assertStringNotContainsString('data-machine ', $form);
    }

    public function testOnlyAnotherSiteLanguageOpensThePanel(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet', EntryStatus::Draft);
        $this->actingAs($this->createUser('ann@example.com'));

        foreach (['ru', 'de', '', 'x"y'] as $forged) {
            $form = (string) $this->browser->get('/admin/content/articles/' . $privet->id . '?machine=' . rawurlencode($forged))->getBody();
            self::assertStringNotContainsString('data-machine ', $form, $forged);
        }
        $form = (string) $this->browser->get('/admin/content/articles/' . $privet->id . '?machine=en')->getBody();
        self::assertStringContainsString('<div class="admin-machine" data-machine data-machine-from="en" data-machine-to="ru" data-machine-slug data-machine-fields=', $form, 'without start=1 it waits for the click');
    }

    public function testAPublishedCopyKeepsItsAddressAndSlidesAreNamed(): void
    {
        $home = $this->entry('pages', 'Home', ['slug' => 'home']);
        $dom = $this->translation($home, 'ru', 'Дом', 'dom');
        $this->actingAs($this->createUser('ann@example.com'));

        $form = (string) $this->browser->get('/admin/content/pages/' . $dom->id . '?machine=en')->getBody();

        self::assertStringContainsString('data-machine-to="ru" data-machine-fields="[&quot;title&quot;,&quot;meta_title&quot;,&quot;meta_description&quot;,&quot;fields[body]&quot;,&quot;fields[teaser]&quot;]" data-machine-slides="[&quot;hero&quot;]" hidden>', $form, 'a published entry keeps its address: no data-machine-slug');
    }

    public function testAPresetCopyKeepsItsTitleInItsLanguage(): void
    {
        $this->app->container()->service(PresetPages::class)->sync();
        $legal = $this->app->container()->service(EntryRepository::class)->presetPages('info', 'legal')['ru'] ?? self::fail('No preset');
        $this->actingAs($this->createUser('ann@example.com'));

        $form = (string) $this->browser->get('/admin/content/info/' . $legal->id . '?machine=en')->getBody();

        self::assertStringContainsString('data-machine-to="ru" data-machine-fields="[&quot;meta_title&quot;,&quot;meta_description&quot;,&quot;fields[body]&quot;]" data-machine-slides="[]" hidden>', $form, 'the title is the preset label in the language of the copy already; the address is fixed');
    }

    public function testATermAndASectionOpenTheirFormWithThePanel(): void
    {
        $code = $this->term('topics', 'Code');
        $news = $this->term('sections', 'News');
        $this->actingAs($this->createUser('ann@example.com'));

        // A term's copy has only its name: TermService::createTranslation() copies no search-engine texts.
        $fields = ['topics' => '[&quot;name&quot;]', 'sections' => '[&quot;name&quot;]'];
        foreach (['/admin/taxonomies/topics/' => [$code, 'topics'], '/admin/sections/' => [$news, 'sections']] as $base => [$term, $taxonomy]) {
            $response = $this->browser->post($base . $term->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'machine' => '1']);
            $copy = $this->termCopyOf($term, 'ru');
            self::assertSame($base . $copy->id . '?locale=ru&machine=en&start=1', $response->getHeaderLine('Location'), $taxonomy);

            $form = (string) $this->browser->get($response->getHeaderLine('Location'))->getBody();
            self::assertStringContainsString('<div class="admin-machine" data-machine data-machine-from="en" data-machine-to="ru" data-machine-start data-machine-fields="' . $fields[$taxonomy] . '" data-machine-slides="[]" hidden>', $form, $taxonomy . ': a term keeps its slug');
            self::assertStringContainsString('/translate.js?v=', $form, $taxonomy);
            self::assertStringContainsString('Copy for translation created.', $form, $taxonomy);
        }
    }

    public function testARefusedMarkerIsTheCopysRefusal(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->post('/admin/content/articles/' . $hello->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'machine' => '1']);

        self::assertSame('/admin/content/articles/' . $hello->id, $response->getHeaderLine('Location'));
        self::assertStringContainsString('A translation for this language already exists.', (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody());
    }

    private function copyOf(Entry $source, string $locale): Entry
    {
        foreach ($this->app->container()->service(EntryRepository::class)->translations($source->translationGroup) as $translation) {
            if ($translation->locale === $locale) {
                return $translation;
            }
        }

        return self::fail('No copy in ' . $locale);
    }

    private function termCopyOf(Term $source, string $locale): Term
    {
        foreach ($this->terms()->translations($source->translationGroup) as $translation) {
            if ($translation->locale === $locale) {
                return $translation;
            }
        }

        return self::fail('No copy in ' . $locale);
    }
}
