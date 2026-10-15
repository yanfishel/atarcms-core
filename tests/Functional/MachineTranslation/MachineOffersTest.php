<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\MachineTranslation;

use AtarCms\Content\EntryStatus;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * Plan 38: wherever a missing translation is offered, the copy is "Copy for translation" and a second, hidden submit
 * button of the same form ("Translate (machine translation)", name "machine") waits for translate.js, which shows it
 * only where the browser can translate the pair on the device. Without JavaScript only the copy shows.
 */
final class MachineOffersTest extends ContentFunctionalTestCase
{
    public function testTheEntryOfferToastHasTheCopyAndTheHiddenMachineButton(): void
    {
        $alone = $this->entry('articles', 'Alone', ['slug' => 'alone']);
        $this->actingAs($this->createUser('ann@example.com'));

        $list = (string) $this->browser->get('/admin/content/articles?locale=ru&translate=' . $alone->id)->getBody();

        self::assertMatchesRegularExpression(
            '~<form class="admin-toast-form" method="post" action="/admin/content/articles/' . $alone->id . '/translate"><input type="hidden" name="_csrf" value="[^"]+"><input type="hidden" name="locale" value="ru">'
            . '<button type="submit" class="admin-button admin-button-primary admin-button-compact" aria-label="Translate \(machine translation\)" title="Translate \(machine translation\)" name="machine" value="1" data-machine-offer data-machine-from="en" data-machine-to="ru" hidden><svg[^>]*><use href="[^"]+#languages"></use></svg>Translate</button>'
            . '<button type="submit" class="admin-button admin-button-primary admin-button-compact admin-toast-copy" aria-label="Copy for translation" title="Copy for translation"><svg[^>]*><use href="[^"]+#copy"></use></svg>Copy</button></form>~',
            $list,
        );
        self::assertStringContainsString('/translate.js?v=', $list, 'the list loads the script that shows the button');
    }

    public function testTheTermAndSectionOffersHaveTheMachineButton(): void
    {
        $code = $this->term('topics', 'Code');
        $news = $this->term('sections', 'News');
        $this->actingAs($this->createUser('ann@example.com'));

        foreach (['/admin/taxonomies/topics?locale=ru&translate=' . $code->id => $code->id, '/admin/sections?locale=ru&translate=' . $news->id => $news->id] as $url => $id) {
            $body = (string) $this->browser->get($url)->getBody();
            self::assertStringContainsString('aria-label="Copy for translation" title="Copy for translation">', $body, $url);
            self::assertStringContainsString('name="machine" value="1" data-machine-offer data-machine-from="en" data-machine-to="ru" hidden>', $body, $url);
            self::assertStringContainsString('/translate"', $body, $url);
            self::assertStringContainsString('/translate.js?v=', $body, $url);
        }
    }

    public function testWithoutTheRightToTranslateThereIsNoButtonAtAll(): void
    {
        $other = $this->createUser('other@example.com', 'author');
        $alone = $this->entry('articles', 'Alone', ['slug' => 'alone', 'author' => $other, 'status' => EntryStatus::Draft]);
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $list = (string) $this->browser->get('/admin/content/articles?locale=ru&translate=' . $alone->id)->getBody();

        self::assertStringNotContainsString('data-machine-offer', $list);
        self::assertStringNotContainsString('Copy for translation', $list);
    }

    public function testTheTranslationsLineHasAHiddenMachineSegment(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $code = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ann@example.com'));

        $entry = (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody();
        self::assertMatchesRegularExpression(
            '~<input type="hidden" name="locale" value="ru">\s*'
            . '<button type="submit" class="admin-translations-create" title="Русский: copy for translation"><span aria-hidden="true">RU</span><svg[^>]*><use href="[^"]+#plus"></use></svg><span class="admin-visually-hidden">Русский: copy for translation</span></button>\s*'
            . '<button type="submit" class="admin-translations-create" name="machine" value="1" title="Русский: machine translation" data-machine-offer data-machine-from="en" data-machine-to="ru" hidden><span aria-hidden="true">RU</span><svg[^>]*><use href="[^"]+#languages"></use></svg><span class="admin-visually-hidden">Русский: machine translation</span></button>\s*</form>~',
            $entry,
        );
        self::assertStringContainsString('/translate.js?v=', $entry);

        $term = (string) $this->browser->get('/admin/taxonomies/topics/' . $code->id)->getBody();
        self::assertStringContainsString('title="Русский: machine translation" data-machine-offer data-machine-from="en" data-machine-to="ru" hidden>', $term);
        self::assertStringContainsString('/translate.js?v=', $term);
    }
}
