<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Content\Collections;
use AtarCms\Http\Security\PublicFormToken;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Messages\ContactForm;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormService;
use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Support\Regex;
use Psr\Http\Message\ResponseInterface;

/**
 * The skeleton's contacts page holds the contact form: a public form without the session, posted to the page's own
 * address.
 */
final class ContactFormTest extends SkeletonTestCase
{
    public function testThePageShowsTheFormWithoutACookie(): void
    {
        $response = $this->browser->get('/contacts');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'), 'a visitor of the contacts page gets no cookie');
        self::assertStringContainsString('<form class="form" method="post" action="/contacts#contact" novalidate>', $body);
        self::assertMatchesRegularExpression('~<input type="hidden" name="_form" value="\d+\.[A-Za-z0-9_-]{43}">~', $body);
        self::assertStringContainsString('<input type="text" id="contact-website" name="website" value="" tabindex="-1" autocomplete="off">', $body);
        self::assertStringNotContainsString('name="_csrf"', $body);
        self::assertStringContainsString('action="/he/contacts#contact"', (string) $this->browser->get('/he/contacts')->getBody());
    }

    public function testASentMessageIsStoredAndThePageSaysSo(): void
    {
        $response = $this->send('/ru/contacts', ['name' => 'Анна', 'email' => 'anna@example.com', 'message' => 'Здравствуйте! Хочу узнать больше.']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/ru/contacts?sent=1#contact', $response->getHeaderLine('Location'));
        self::assertSame([], $response->getHeader('Set-Cookie'));
        $messages = $this->app->container()->service(MessageRepository::class)->latest(10, 0);
        self::assertCount(1, $messages);
        self::assertSame(['ru', 'Анна', 'https://localhost/ru/contacts'], [$messages[0]->locale, $messages[0]->name, $messages[0]->pageUrl]);

        $page = (string) $this->browser->get('/ru/contacts?sent=1')->getBody();
        self::assertStringContainsString('<p class="notice" role="status">Спасибо! Сообщение отправлено.', $page);
        self::assertStringNotContainsString('<form class="form"', $page);
    }

    public function testARefusedMessageComesBackWithTheTypedTextAndItsErrors(): void
    {
        $response = $this->send('/he/contacts', ['name' => 'דנה <b>', 'email' => 'dana@', 'message' => 'שלום']);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('value="דנה &lt;b&gt;"', $body);
        self::assertStringContainsString('<div class="form-errors" role="alert">', $body);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="contact-email-error"', $body);
        self::assertMatchesRegularExpression('~<p class="field-error" id="contact-email-error" data-error-for="email">[^<]+</p>~', $body);
        self::assertStringContainsString('>שלום</textarea>', $body);
        self::assertSame(0, $this->app->container()->service(MessageRepository::class)->count());
    }

    /**
     * The refused form comes back with a token old enough to be sent again at once: a visitor who fixes a typo within
     * seconds is not taken for a bot (and silently dropped).
     */
    public function testAFixedFormCanBeSentAgainAtOnce(): void
    {
        $refused = (string) $this->send('/contacts', ['name' => 'Anna', 'email' => 'anna@', 'message' => 'Hello, I would like to know more.'])->getBody();
        $token = Regex::match('~name="_form" value="([^"]+)"~', $refused);

        $response = $this->send('/contacts', ['name' => 'Anna', 'email' => 'anna@example.com', 'message' => 'Hello, I would like to know more.'], $token[1]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(1, $this->app->container()->service(MessageRepository::class)->count(), 'stored, not dropped as too fast');
    }

    public function testAnOldFormKeepsTheTextAndAsksToSendAgain(): void
    {
        $old = $this->token('-2 days');
        $response = $this->send('/contacts', ['name' => 'Anna', 'email' => 'anna@example.com', 'message' => 'Hello, I would like to know more.'], $old);

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('The form was open for too long. Please send it again.', $body);
        self::assertStringContainsString('Hello, I would like to know more.</textarea>', $body);
    }

    public function testSpamLooksSentButIsNotKept(): void
    {
        $response = $this->send('/contacts', ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Buy cheap things now!', 'website' => 'https://spam.example']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(0, $this->app->container()->service(MessageRepository::class)->count());
    }

    public function testTooManyMessagesAreRefusedWith429(): void
    {
        for ($i = 0; $i < FormService::RATE_LIMIT; ++$i) {
            self::assertSame(303, $this->send('/contacts', ['name' => 'Anna', 'email' => 'anna@example.com', 'message' => 'Hello, message number ' . $i])->getStatusCode());
        }
        $response = $this->send('/contacts', ['name' => 'Anna', 'email' => 'anna@example.com', 'message' => 'Hello, one message too many']);

        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('You have sent several messages in a short time.', (string) $response->getBody());
    }

    public function testAPostFromAnotherSiteIsRefused(): void
    {
        $response = $this->browser->post('/contacts', ['name' => 'Anna', 'email' => 'anna@example.com', 'message' => 'Hello there, friend.', '_form' => $this->token('-1 minute')], ['Origin' => 'https://evil.example']);

        self::assertSame(419, $response->getStatusCode(), 'the CSRF refusal');
        self::assertSame(0, $this->app->container()->service(MessageRepository::class)->count());
    }

    public function testTheContactFormMustBeARoutedSingle(): void
    {
        $collections = $this->app->container()->service(Collections::class);

        self::assertNull(ContactForm::fromConfig(new Config(['content' => []]), $collections)->page);
        self::assertSame('contacts', ContactForm::fromConfig(new Config(['content' => ['contact_form' => 'contacts']]), $collections)->page?->name);
        foreach (['news', 'ghost', 'articles'] as $name) {
            try {
                ContactForm::fromConfig(new Config(['content' => ['contact_form' => $name]]), $collections);
                self::fail('Expected a ConfigException for ' . $name);
            } catch (ConfigException $e) {
                self::assertStringContainsString('content.contact_form must name a single() collection with a route', $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private function send(string $path, array $fields, ?string $token = null): ResponseInterface
    {
        return $this->browser->post($path, [...$fields, '_form' => $token ?? $this->token('-1 minute')], ['Origin' => 'https://localhost', 'Sec-Fetch-Site' => 'same-origin']);
    }

    private function token(string $age): string
    {
        $container = $this->app->container();

        return $container->service(PublicFormToken::class)->issue(FormDefinitions::CONTACT, $container->service(Clock::class)->now()->modify($age));
    }
}
