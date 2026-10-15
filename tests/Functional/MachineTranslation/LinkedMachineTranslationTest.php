<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\MachineTranslation;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Tests\Functional\LinkedLanguage\LinkedLanguageTestCase;

/**
 * Plan 38 in the linked mode: the offer toasts carry the machine button as in the separate mode, and the top bar's
 * switch, which creates a single's missing translation by itself (Plan 37), opens its form with the panel waiting for
 * a click (no start=1: nobody chose machine translation yet).
 */
final class LinkedMachineTranslationTest extends LinkedLanguageTestCase
{
    public function testTheSwitchOffersTheMachineTranslationOnTheSinglesNewForm(): void
    {
        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '123']]);
        $this->actingAs($this->createUser('ann@example.com'));

        $target = $this->switchTo('ru', '/admin/content/contact/' . $contact->id);

        $russian = array_values(array_filter($this->app->container()->service(EntryRepository::class)->translations($contact->translationGroup), static fn(Entry $e): bool => $e->locale === 'ru'))[0];
        self::assertSame('/admin/content/contact/' . $russian->id . '?locale=ru&machine=en', $target);
        $form = (string) $this->browser->get($target)->getBody();
        self::assertStringContainsString('<div class="admin-machine" data-machine data-machine-from="en" data-machine-to="ru" data-machine-fields=', $form, 'a single keeps its address and does not start by itself');
        self::assertStringContainsString('Перевести с языка «English»', $form, 'the linked interface speaks the copy\'s language');
    }

    public function testTheListOfferHasTheMachineButton(): void
    {
        $alone = $this->entry('articles', 'Alone', ['slug' => 'alone']);
        $this->actingAs($this->createUser('ann@example.com'));

        $list = (string) $this->browser->get('/admin/content/articles?locale=ru&translate=' . $alone->id)->getBody();

        self::assertStringContainsString('aria-label="Скопировать для перевода" title="Скопировать для перевода"><svg', $list);
        self::assertStringContainsString('Скопировать</button>', $list);
        self::assertStringContainsString('aria-label="Перевести (машинный перевод)" title="Перевести (машинный перевод)" name="machine" value="1" data-machine-offer data-machine-from="en" data-machine-to="ru" hidden>', $list);
        self::assertStringContainsString('Перевести</button>', $list);
    }
}
