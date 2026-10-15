<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;

/**
 * The "Atar" theme's chat button (the contact page's switch, off by default): a round button in the bottom end corner, above
 * the accessibility toolbar's button when that sits there (the cookie banner holds the start corner), that opens the filled messengers; with one messenger it is a link to it. Plain
 * links (no third-party script, no cookie), and a <details> panel that works without JavaScript.
 */
final class ChatButtonTest extends SkeletonTestCase
{
    public function testThereIsNoButtonUntilItIsSwitchedOnWithAMessenger(): void
    {
        self::assertStringNotContainsString('class="chat', (string) $this->browser->get('/')->getBody(), 'off by default');

        $this->contacts(new SettingsInput(chatButton: true));
        self::assertStringNotContainsString('class="chat', (string) $this->browser->get('/')->getBody(), 'no messenger, no button');

        $this->contacts(new SettingsInput(whatsapp: '+972 50 555 0100'));
        self::assertStringNotContainsString('class="chat', (string) $this->browser->get('/')->getBody(), 'switched off');
    }

    public function testSeveralMessengersOpenInAPanelOnEveryPage(): void
    {
        $this->contacts(new SettingsInput(whatsapp: '+972 50 555 0100', viber: '+972 50 555 0101', telegram: 'atar_demo', chatButton: true));

        foreach (['/', '/news', '/search?q=x', '/ru'] as $path) {
            self::assertStringContainsString('<details class="chat" data-chat-raised>', (string) $this->browser->get($path)->getBody(), $path . ': above the toolbar in its default bottom-end corner');
        }
        $page = (string) $this->browser->get('/')->getBody();
        self::assertMatchesRegularExpression('~<summary class="chat-trigger" aria-label="Write to us" title="Write to us"><svg class="icon"[^>]*><use href="[^"]+#message-circle"></use></svg></summary>~', $page);
        self::assertMatchesRegularExpression('~<a href="https://wa\.me/972505550100" rel="noopener"><svg class="icon icon-brand"[^>]*><use href="[^"]+#whatsapp"></use></svg>WhatsApp</a>~', $page);
        self::assertStringContainsString('<a href="viber://chat?number=%2B972505550101" rel="noopener">', $page);
        self::assertStringContainsString('<a href="https://t.me/atar_demo" rel="noopener">', $page);
        self::assertStringNotContainsString('chat.js', $page, 'the theme\'s own script closes it, no widget script');
        self::assertDoesNotMatchRegularExpression('~<details class="chat"[^>]*\sopen~', $page, 'closed at first');
        self::assertStringContainsString("querySelectorAll('details.dropdown, details.chat')", (string) file_get_contents(self::SITE . '/themes/default/assets/js/theme.js'), 'Escape and a click elsewhere close it, like the header dropdowns');

        $russian = (string) $this->browser->get('/ru')->getBody();
        self::assertStringContainsString('aria-label="Напишите нам"', $russian, 'in the page\'s language');
    }

    public function testOneMessengerIsADirectLink(): void
    {
        $this->contacts(new SettingsInput(telegram: 'atar_demo', chatButton: true));

        self::assertMatchesRegularExpression('~<div class="chat" data-chat-raised><a class="chat-trigger" href="https://t\.me/atar_demo" rel="noopener" aria-label="Write to us on Telegram" title="Write to us on Telegram"><svg class="icon icon-brand"[^>]*><use href="[^"]+#telegram"></use></svg></a></div>~', (string) $this->browser->get('/')->getBody());
    }

    public function testTheButtonKeepsAwayFromTheToolbar(): void
    {
        $service = $this->app->container()->service(SettingsService::class);

        $service->save('en', new SettingsInput(telegram: 'atar_demo', chatButton: true, accessibilityToolbarPosition: 'bottom-start'), null, null);
        self::assertStringContainsString('<div class="chat"><a', (string) $this->browser->get('/')->getBody(), 'the toolbar in the start corner: the end corner is free');
        $service->save('en', new SettingsInput(telegram: 'atar_demo', chatButton: true, accessibilityToolbar: false), null, null);
        self::assertStringContainsString('<div class="chat"><a', (string) $this->browser->get('/')->getBody(), 'no toolbar: the corner itself');
    }

    private function contacts(SettingsInput $input): void
    {
        $this->app->container()->service(SettingsService::class)->saveContacts('en', $input, null, null);
    }
}
