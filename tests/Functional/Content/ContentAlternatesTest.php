<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;

final class ContentAlternatesTest extends ContentFunctionalTestCase
{
    public function testEntryPagesLinkOnlyToPublishedTranslations(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->entry('articles', 'Lonely', ['slug' => 'lonely']);
        $drafted = $this->entry('articles', 'Drafted', ['slug' => 'drafted']);
        $this->translation($drafted, 'ru', 'Черновик', 'chernovik', EntryStatus::Draft);

        $english = (string) $this->browser->get('/articles/hello')->getBody();
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://localhost/articles/hello">', $english);
        self::assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://localhost/ru/articles/privet">', $english);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://localhost/articles/hello">', $english);

        $russian = (string) $this->browser->get('/ru/articles/privet')->getBody();
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://localhost/articles/hello">', $russian);

        self::assertStringNotContainsString('hreflang', (string) $this->browser->get('/articles/lonely')->getBody());
        self::assertStringNotContainsString('hreflang', (string) $this->browser->get('/articles/drafted')->getBody());
    }

    public function testArchivesKeepRouteAlternatesAndSinglesLinkOnlyToTheirEntries(): void
    {
        self::assertStringContainsString(
            '<link rel="alternate" hreflang="ru" href="https://localhost/ru/articles">',
            (string) $this->browser->get('/articles')->getBody(),
        );
        self::assertStringNotContainsString('hreflang', (string) $this->browser->get('/contact')->getBody(), 'no entry: no languages to advertise');
        self::assertStringNotContainsString('hreflang', (string) $this->browser->get('/ru/contact')->getBody());

        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        self::assertStringNotContainsString('hreflang', (string) $this->browser->get('/contact')->getBody());

        $this->translation($contact, 'ru', 'Контакты', 'contact');
        $english = (string) $this->browser->get('/contact')->getBody();
        self::assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://localhost/ru/contact">', $english);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://localhost/contact">', $english);
    }
}
