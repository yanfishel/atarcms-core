<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\EntryInput;
use AtarCms\Content\RichTextTheme;
use AtarCms\View\EditorSettings;

/**
 * EntryService keeps only the active theme's classes in rich text (RichTextTheme), on every save.
 */
final class RichTextClassesTest extends ContentTestCase
{
    public function testSavingDropsTheClassesTheThemeDoesNotDeclare(): void
    {
        $service = $this->service(richText: $this->richTextTheme(['lead' => ['label' => 'Lead', 'element' => 'p', 'classes' => ['lead']]]));

        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Classes', fields: ['body' => '<p class="lead MsoNormal">A</p>'
            . '<figure class="image pasted"><img src="/uploads/x.png" alt="x"></figure><div class="note"><p>B</p></div>']), null, null);

        self::assertSame('<p class="lead">A</p><figure class="image"><img src="/uploads/x.png" alt="x" /></figure><div><p>B</p></div>', $entry->data['body']);
    }

    public function testSavingKeepsEveryFeatureAndDropsFontsTheThemeDoesNotDeclare(): void
    {
        $service = $this->service(richText: new RichTextTheme(new EditorSettings(fonts: [['label' => 'Serif', 'family' => 'Georgia, serif']]), ''));
        $body = '<p class="text-align-center indent-1"><span class="text-big" style="color: #e64d4d; font-family: Georgia, serif">A</span>'
            . '<mark class="marker-yellow">B</mark><span lang="ar" dir="rtl">C</span><a href="https://example.com" target="_blank" rel="noopener noreferrer">D</a></p>'
            . '<ol type="a" reversed="reversed" start="3"><li>E</li></ol>'
            . '<ul class="todo-list"><li><label class="todo-list__label"><input type="checkbox" checked="checked" disabled="disabled" /><span class="todo-list__label__description">F</span></label></li></ul>'
            . '<pre><code class="language-php">G</code></pre>'
            . '<figure class="image image-style-align-left image_resized" style="width: 50%"><a href="/uploads/x.png"><img style="aspect-ratio: 4/3" src="/uploads/x.png" alt="x" width="400" height="300" /></a></figure>'
            . '<figure class="table table-style-align-left" style="width: 80%"><table class="ck-table-resized" style="border: 1px solid #000"><colgroup><col style="width: 50%" /><col style="width: 50%" /></colgroup>'
            . '<tbody><tr><td style="padding: 4px">H</td><td>I</td></tr></tbody></table><figcaption>J</figcaption></figure>';

        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Features', fields: ['body' => $body
            . '<p><span style="font-family: Courier, monospace">K</span></p>']), null, null);

        self::assertSame($body . '<p><span>K</span></p>', $entry->data['body']);
    }

    public function testAMinimalFieldIsStoredWithItsOwnProfile(): void
    {
        $entry = $this->service()->create($this->collections->get('pages'), 'en', new EntryInput('Teaser', fields: [
            'body' => '<h2 class="text-align-center">Body</h2>',
            'teaser' => '<h2>Big</h2><p class="text-align-center"><strong>Bold</strong> <span class="text-huge marker" style="color:#c0392b;font-family:Georgia">red</span></p><ul><li>item</li></ul>',
        ]), null, null);

        self::assertSame('<h2 class="text-align-center">Body</h2>', $entry->data['body']);
        self::assertSame('Big <p><strong>Bold</strong> <span class="text-huge" style="color: #c0392b">red</span></p> item', $entry->data['teaser']);
    }

    public function testRestoringARevisionDropsTheClassesOfAPreviousTheme(): void
    {
        $old = $this->service(richText: $this->richTextTheme(['boxed' => ['label' => 'Boxed', 'element' => 'p', 'classes' => ['boxed']]]));
        $entry = $old->create($this->collections->get('articles'), 'en', new EntryInput('Theme', fields: ['body' => '<p class="boxed">Old</p>']), null, null);
        $first = $this->revisions()->forEntry($entry->id)[0]->id;
        self::assertSame('<p class="boxed">Old</p>', $entry->data['body']);
        $entry = $old->update($entry, new EntryInput('Theme', slug: $entry->slug, fields: ['body' => '<p>New</p>']), null, null);

        $restored = $this->service()->restore($entry, $first, null, null);

        self::assertSame('<p>Old</p>', $restored->data['body']);
    }
}
