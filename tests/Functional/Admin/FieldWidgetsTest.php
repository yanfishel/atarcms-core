<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Admin\AdminView;
use AtarCms\Content\Field\Field;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Application;
use AtarCms\Tests\Fixtures\ColorType;
use AtarCms\Tests\Fixtures\NamelessType;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class FieldWidgetsTest extends TestCase
{
    private AdminView $view;

    protected function setUp(): void
    {
        putenv('APP_DEBUG=0');
        $this->view = Application::fromSite(__DIR__ . '/../../Fixtures/site')->container()->service(AdminView::class);
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
    }

    public function testScalarWidgetsEscapeValues(): void
    {
        self::assertStringContainsString(
            '<input id="field-name" name="fields[name]" value="&lt;b&gt;x" maxlength="255">',
            $this->render(Field::text('name'), '<b>x'),
        );
        self::assertStringContainsString(
            '<textarea id="field-summary" name="fields[summary]" rows="4">&lt;/textarea&gt;&lt;script&gt;</textarea>',
            $this->render(Field::textarea('summary'), '</textarea><script>'),
        );
        self::assertMatchesRegularExpression(
            '~<textarea id="field-body" name="fields\[body\]" rows="12" data-rich-text data-editor="\{&quot;profile&quot;:&quot;full&quot;,&quot;language&quot;:[^"]*\}">&lt;p&gt;Hi&lt;/p&gt;</textarea>~',
            $this->render(Field::richText('body'), '<p>Hi</p>'),
        );
        self::assertMatchesRegularExpression(
            '~<textarea id="field-summary" name="fields\[summary\]" rows="4" data-rich-text data-editor="\{&quot;profile&quot;:&quot;minimal&quot;,&quot;language&quot;:[^"]*\}">&lt;p&gt;Hi&lt;/p&gt;</textarea>~',
            $this->render(Field::richText('summary')->minimal(), '<p>Hi</p>'),
        );
        self::assertStringContainsString('type="number"', $this->render(Field::number('rating')->min(0)->max(10), 5));
        self::assertStringContainsString('min="0" max="10"', $this->render(Field::number('rating')->min(0)->max(10), 5));
        self::assertStringContainsString('value="2026-10-01"', $this->render(Field::date('day'), '2026-10-01'));
        self::assertStringContainsString('value="2026-10-01T18:30"', $this->render(Field::datetime('starts'), '2026-10-01 18:30:00'));
        self::assertStringContainsString('value="2026-10-01T18:30"', $this->render(Field::datetime('starts'), '2026-10-01T18:30'));
        self::assertStringContainsString('value="dQw4w9WgXcQ"', $this->render(Field::youtube('video'), 'dQw4w9WgXcQ'));
    }

    public function testChoiceWidgets(): void
    {
        $checked = $this->render(Field::boolean('featured')->label('Featured'), true);
        self::assertStringContainsString('<input type="checkbox" id="field-featured" name="fields[featured]" value="1" checked>', $checked);
        self::assertStringContainsString('Featured', $checked);
        self::assertStringNotContainsString(' checked', $this->render(Field::boolean('featured'), false));

        $select = $this->render(Field::select('kind', ['report' => 'Report', 'interview' => 'Interview']), 'interview');
        self::assertStringContainsString('<option value="interview" selected>Interview</option>', $select);
        self::assertStringContainsString('<option value="">—</option>', $select);

        $relation = $this->render(Field::relation('related', 'articles'), [3, '5'], [3 => 'Three <b>', 5 => 'Five', 7 => 'Seven']);
        self::assertStringContainsString('<select id="field-related" name="fields[related][]" multiple size="6">', $relation);
        self::assertStringContainsString('<option value="3" selected>Three &lt;b&gt;</option>', $relation);
        self::assertStringContainsString('<option value="5" selected>Five</option>', $relation);
        self::assertStringContainsString('<option value="7">Seven</option>', $relation);
    }

    public function testWrapperShowsLabelAndTranslatedError(): void
    {
        $html = $this->view->renderer()->render('fields/field', [
            'field' => Field::text('code')->label('Code <i>')->required(),
            'value' => '',
            'error' => 'validation.field.too_long',
            'errorParams' => ['max' => 3],
            'options' => [],
        ], $this->request());

        self::assertStringContainsString('<div class="admin-field admin-field-invalid">', $html);
        self::assertMatchesRegularExpression('~<label for="field-code"><svg class="admin-icon" aria-hidden="true"><use href="[^"]+#type"></use></svg>Code &lt;i&gt; \*</label>~', $html);
        self::assertStringContainsString('<span class="admin-field-error" id="field-code-error">Use at most 3 characters.</span>', $html);
        self::assertStringContainsString(' required', $html);
    }

    /**
     * With an error, every core widget marks its control (the first one where it has several) with aria-invalid and
     * aria-describedby naming the error line, which carries that id; without one, nothing is marked.
     */
    public function testEveryWidgetMarksItsControlWhenInvalid(): void
    {
        $controls = [
            'featured' => [Field::boolean('featured'), '<input type="checkbox" id="field-featured"'],
            'day' => [Field::date('day'), '<input type="date" id="field-day"'],
            'starts' => [Field::datetime('starts'), '<input type="datetime-local" id="field-starts"'],
            'other' => [Field::make('other', new class extends NamelessType {}), '<input id="field-other"'],
            'attachment' => [Field::file('attachment'), '<input class="admin-media-ids" id="field-attachment"'],
            'images' => [Field::gallery('images'), '<input class="admin-media-ids" id="field-images"'],
            'cover' => [Field::image('cover'), '<input class="admin-media-ids" id="field-cover"'],
            'rating' => [Field::number('rating'), '<input type="number" step="any" id="field-rating"'],
            'related' => [Field::relation('related', 'articles'), '<select id="field-related"'],
            'body' => [Field::richText('body'), '<textarea id="field-body"'],
            'kind' => [Field::select('kind', ['a' => 'A']), '<select id="field-kind"'],
            'tags' => [Field::tags('tags'), '<input id="field-tags"'],
            'name' => [Field::text('name'), '<input id="field-name"'],
            'summary' => [Field::textarea('summary'), '<textarea id="field-summary"'],
            'video' => [Field::youtube('video'), '<input type="text" inputmode="url" dir="ltr" id="field-video"'],
        ];
        foreach ($controls as $name => [$field, $control]) {
            $html = $this->view->renderer()->render('fields/field', [
                'field' => $field,
                'value' => null,
                'error' => 'validation.field.number',
                'errorParams' => [],
                'options' => [],
            ], $this->request());

            self::assertMatchesRegularExpression('~' . preg_quote($control, '~') . '[^>]* aria-invalid="true" aria-describedby="field-' . $name . '-error"[^>]*>~', $html, $name);
            // The tag box's query is the visible control under JavaScript and is marked too (Plan 35).
            self::assertSame($name === 'tags' ? 2 : 1, substr_count($html, 'aria-invalid'), $name . ': one control is marked');
            self::assertStringContainsString('<span class="admin-field-error" id="field-' . $name . '-error">Enter a number.</span>', $html, $name);
            self::assertStringNotContainsString('aria-invalid', $this->render($field, null), $name . ' without an error');
        }
    }

    public function testFloatsAreRenderedWithoutExponents(): void
    {
        self::assertStringContainsString('value="0.00005"', $this->render(Field::number('ratio'), 5.0E-5));
        self::assertStringContainsString('value="-1.5"', $this->render(Field::number('delta'), -1.5));
        self::assertStringContainsString('value="12"', $this->render(Field::number('count'), 12));
        self::assertStringContainsString('value="1.25"', $this->render(Field::number('raw'), '1.25'));
    }

    public function testRelationWidgetsKeepChosenIdsThatAreNotListed(): void
    {
        $html = $this->view->renderer()->render('fields/field', [
            'field' => Field::relation('related', 'articles'),
            'value' => [3, 9],
            'error' => null,
            'errorParams' => [],
            'options' => [3 => 'Three'],
            'kept' => [9, 11],
        ], $this->request());

        self::assertStringContainsString('<option value="3" selected>Three</option>', $html);
        self::assertStringContainsString('<input type="hidden" name="fields[related][]" value="9">', $html);
        self::assertStringNotContainsString('value="11"', $html);
    }

    public function testLabelsCarryTheIconOfTheFieldType(): void
    {
        self::assertStringContainsString('#align-left"></use></svg>body</label>', $this->render(Field::richText('body'), ''));
        self::assertStringContainsString('#calendar"></use></svg>starts</label>', $this->render(Field::datetime('starts'), ''));
        self::assertStringContainsString('#circle"></use></svg>tint</label>', $this->render(Field::make('tint', new ColorType()), ''), 'types of modules get the default icon');
        self::assertStringNotContainsString('<label for="field-featured">', $this->render(Field::boolean('featured'), false), 'a checkbox carries its own label');

        $sprite = (string) file_get_contents(__DIR__ . '/../../../resources/admin/assets/icons.svg');
        foreach ([...\AtarCms\Admin\Content\FieldIcons::ICONS, \AtarCms\Admin\Content\FieldIcons::DEFAULT] as $icon) {
            self::assertStringContainsString('<symbol id="' . $icon . '"', $sprite, $icon);
        }
    }

    public function testModulesProvideWidgetsForCustomTypes(): void
    {
        self::assertStringContainsString('class="fixture-color"', $this->render(Field::make('tint', new ColorType()), '#ff0000'));
        self::assertStringContainsString(
            '<input id="field-other" name="fields[other]" value="x">',
            $this->render(Field::make('other', new class extends NamelessType {}), 'x'),
        );
    }

    public function testARichTextFieldWithALimitTellsTheEditorForItsCounter(): void
    {
        self::assertMatchesRegularExpression('~<textarea id="field-teaser" name="fields\[teaser\]" rows="4" data-rich-text data-editor="\{[^"]*\}" data-max="200">~', $this->render(Field::richText('teaser')->minimal()->max(200), ''));
        self::assertMatchesRegularExpression('~<textarea id="field-body" name="fields\[body\]" rows="12" data-rich-text data-editor="\{[^"]*\}" data-max="5000">~', $this->render(Field::richText('body')->max(5000), ''), 'a full editor too');
        self::assertStringNotContainsString('data-max', $this->render(Field::richText('body'), ''));
    }

    public function testAYouTubeFieldShowsTheCardOfItsVideo(): void
    {
        $html = $this->render(Field::youtube('video'), 'dQw4w9WgXcQ');

        self::assertStringContainsString('<div class="admin-youtube" data-youtube data-youtube-input="field-video" data-youtube-embed="https://www.youtube-nocookie.com/embed/" data-youtube-thumb="https://i.ytimg.com/vi/{id}/hqdefault.jpg" data-youtube-watch="https://www.youtube.com/watch?v=" data-youtube-id="dQw4w9WgXcQ">', $html, 'shown from the server output; the addresses youtube.js builds come from the server');
        self::assertStringContainsString('<div class="admin-youtube-preview" data-youtube-preview><img class="admin-youtube-thumb" src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg" alt="" data-youtube-image>', $html, 'the cover only: the player loads on Play');
        self::assertMatchesRegularExpression('~<button type="button" class="admin-youtube-play" aria-label="Play video" title="Play video" data-youtube-play><svg class="admin-icon" aria-hidden="true"[^>]*><use href="[^"]+#play"></use></svg></button>~', $html);
        self::assertStringNotContainsString('admin-youtube-id', $html, 'the input shows the id already');
        self::assertMatchesRegularExpression('~<a class="admin-youtube-open" href="https://www\.youtube\.com/watch\?v=dQw4w9WgXcQ" target="_blank" rel="noopener noreferrer" data-youtube-open><svg class="admin-icon admin-icon-mirror" aria-hidden="true"[^>]*><use href="[^"]+#external-link"></use></svg>Open on YouTube</a>~', $html, 'works without JavaScript');
        self::assertMatchesRegularExpression('~<button type="button" class="admin-button admin-button-link admin-button-danger admin-button-compact admin-youtube-remove" data-youtube-remove><svg class="admin-icon" aria-hidden="true"[^>]*><use href="[^"]+#trash-2"></use></svg>Remove video</button>~', $html);
        self::assertStringContainsString('<template data-youtube-player><iframe class="admin-youtube-frame" title="Video preview" allow="accelerometer; autoplay; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin"></iframe></template>', $html, 'the player without its address; autoplay: Play starts it');
        self::assertMatchesRegularExpression('~<template data-youtube-invalid><div class="admin-toast admin-toast-error" role="alert" data-toast>.*Enter a YouTube link or video ID\.</p>.*</div></template>~s', $html, 'an unreadable value is an error toast with the server\'s message');
        self::assertStringNotContainsString(' src=', substr($html, (int) strpos($html, 'data-youtube-player')), 'no address in the player template');
        self::assertStringNotContainsString('Check video', $html);
        self::assertDoesNotMatchRegularExpression('~<script|<style|style=|hx-on~', $html);
    }

    public function testAYouTubeFieldWithoutAVideoHidesTheCard(): void
    {
        $html = $this->render(Field::youtube('video'), null);

        self::assertMatchesRegularExpression('~<div class="admin-youtube" data-youtube [^>]* hidden>~', $html);
        self::assertStringContainsString('<img class="admin-youtube-thumb" alt="" data-youtube-image>', $html, 'no cover to load');
        self::assertStringContainsString(' data-youtube-id="" hidden>', $html);
        self::assertStringContainsString('<a class="admin-youtube-open" href="" ', $html);
    }

    public function testARefusedOrPostedLinkShowsTheVideoItNames(): void
    {
        self::assertStringContainsString('src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"', $this->render(Field::youtube('video'), ' https://youtu.be/dQw4w9WgXcQ '), 'a link as posted');
        self::assertMatchesRegularExpression('~<div class="admin-youtube" data-youtube [^>]* hidden>~', $this->render(Field::youtube('video'), 'https://vimeo.com/123'), 'not a YouTube video');
    }

    /**
     * @param array<int|string, string> $options
     */
    private function render(Field $field, mixed $value, array $options = []): string
    {
        return $this->view->renderer()->render('fields/field', [
            'field' => $field,
            'value' => $value,
            'error' => null,
            'errorParams' => [],
            'options' => $options,
        ], $this->request());
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/admin')->withAttribute(RequestLocale::class, new RequestLocale('en', false));
    }
}
