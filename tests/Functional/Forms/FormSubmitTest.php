<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Forms;

use AtarCms\Messages\Form\FormService;
use AtarCms\Messages\MessageRepository;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * POST /forms/{key}: JSON for the dialog (Accept: application/json), the form page again or a redirect otherwise.
 */
final class FormSubmitTest extends FormsTestCase
{
    public function testASentFormIsStoredAndRedirectsToTheThanks(): void
    {
        $response = $this->send('/forms/booking', [...$this->booking(), 'ghost' => 'boo', 'message' => '  ']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/forms/booking?sent=1', $response->getHeaderLine('Location'));
        self::assertSame([], $response->getHeader('Set-Cookie'));
        $message = $this->app->container()->service(MessageRepository::class)->latest(1, 0)[0] ?? self::fail('stored');
        self::assertSame(['booking', 'Dana', '+972 50 123 4567', null], [$message->form, $message->name, $message->phone, $message->body]);
        self::assertSame(['service' => 'coloring', 'date' => $this->day('+1 day'), 'time' => '10:30', 'consent' => '1'], $message->fields);
        self::assertSame('https://localhost/forms/booking', $message->pageUrl, 'without a Referer: the form page');
    }

    public function testARefusedFormComesBackWithTheTypedTextAndItsErrors(): void
    {
        $response = $this->send('/forms/booking', [...$this->booking(), 'name' => 'Dana <b>', 'phone' => '', 'date' => $this->day('-1 day')]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('value="Dana &lt;b&gt;"', $body);
        self::assertStringContainsString('<p class="error" id="booking-phone-error">This field is required.</p>', $body);
        self::assertMatchesRegularExpression('~<p class="error" id="booking-date-error">Choose a date no earlier than [^<]+\.</p>~', $body);
        self::assertSame(0, $this->stored());
    }

    public function testTooManyFormsAreRefusedWith429(): void
    {
        for ($i = 0; $i < FormService::RATE_LIMIT; ++$i) {
            self::assertSame(303, $this->send('/forms/booking', $this->booking())->getStatusCode());
        }

        $response = $this->send('/forms/booking', $this->booking());
        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('You have sent several messages in a short time.', (string) $response->getBody());

        $json = $this->send('/forms/booking', $this->booking(), json: true);
        self::assertSame(429, $json->getStatusCode());
        self::assertSame(['ok' => false, 'errors' => [], 'form' => 'You have sent several messages in a short time. Please try again later.'], self::refusal($json));
    }

    public function testTooManyFormsAnswerTheDialogWithJson429(): void
    {
        for ($i = 0; $i < FormService::RATE_LIMIT; ++$i) {
            self::assertSame(200, $this->send('/forms/booking', $this->booking(), json: true)->getStatusCode());
        }

        $response = $this->send('/forms/booking', $this->booking(), json: true);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['ok' => false, 'errors' => [], 'form' => 'You have sent several messages in a short time. Please try again later.'], self::refusal($response));
        self::assertSame(FormService::RATE_LIMIT, $this->stored());
    }

    public function testTheDialogGetsJson(): void
    {
        $response = $this->send('/forms/booking', $this->booking(), json: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['ok' => true, 'message' => 'Thank you! We will call you back.'], self::json($response));
        self::assertSame(1, $this->stored());
    }

    public function testRefusalsAreTranslatedForTheDialogWithoutEchoingTheInput(): void
    {
        $response = $this->send('/forms/booking', [...$this->booking(), 'phone' => 'call <me>', 'consent' => ''], json: true);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([
            'ok' => false,
            'errors' => [
                'phone' => 'Enter a phone number with digits, spaces, “+”, “-” and brackets, or leave it empty.',
                'consent' => 'Tick this box to go on.',
            ],
            'form' => null,
        ], self::refusal($response));
        self::assertStringNotContainsString('call', (string) $response->getBody());
    }

    public function testUnderALanguagePrefixTheTextsAreInThatLanguage(): void
    {
        $refused = self::json($this->send('/ru/forms/booking', [...$this->booking(), 'phone' => ''], json: true));
        self::assertSame(['phone' => 'Заполните это поле.'], $refused['errors'] ?? null);

        $sent = $this->send('/ru/forms/booking', $this->booking(), json: true);
        self::assertSame(['ok' => true, 'message' => 'Спасибо! Мы вам перезвоним.'], self::json($sent));
        self::assertSame('ru', ($this->app->container()->service(MessageRepository::class)->latest(1, 0)[0] ?? self::fail('stored'))->locale);
        self::assertSame('/ru/forms/booking?sent=1', $this->send('/ru/forms/booking', $this->booking())->getHeaderLine('Location'));
    }

    public function testSpamLooksSentButIsNotKept(): void
    {
        $trap = $this->send('/forms/booking', [...$this->booking(), 'website' => 'https://spam.example'], json: true);
        self::assertSame(['ok' => true, 'message' => 'Thank you! We will call you back.'], self::json($trap));

        $fast = $this->send('/forms/booking', [...$this->booking(), '_form' => $this->token('booking', '-1 second')], json: true);
        self::assertSame(200, $fast->getStatusCode());
        self::assertTrue(self::json($fast)['ok'] ?? null);
        self::assertSame(0, $this->stored());
    }

    public function testAnExpiredOrForeignFormIsSentAgain(): void
    {
        $expired = $this->send('/forms/booking', [...$this->booking(), '_form' => $this->token('booking', '-2 days')], json: true);
        self::assertSame(422, $expired->getStatusCode());
        self::assertSame(['ok' => false, 'errors' => [], 'form' => 'The form was open for too long. Please send it again.'], self::refusal($expired));
        $fresh = self::json($expired)['token'] ?? null;
        self::assertIsString($fresh);
        self::assertSame(200, $this->send('/forms/booking', [...$this->booking(), '_form' => $fresh], json: true)->getStatusCode(), 'the refusal\'s fresh token may be sent at once');
        self::assertSame(1, $this->stored());
        $this->app->container()->service(MessageRepository::class)->deleteAll();

        $foreign = $this->send('/forms/booking', [...$this->booking(), '_form' => $this->token('contact')], json: true);
        self::assertSame(422, $foreign->getStatusCode(), 'a contact form token is no booking token');
        self::assertSame('The form was open for too long. Please send it again.', self::json($foreign)['form'] ?? null);

        $page = $this->send('/forms/booking', [...$this->booking(), '_form' => $this->token('booking', '-2 days')]);
        self::assertSame(422, $page->getStatusCode());
        self::assertStringContainsString('<p class="form-error" role="alert">The form was open for too long. Please send it again.</p>', (string) $page->getBody());
        self::assertSame(0, $this->stored());
    }

    public function testHostileShapesAreFieldErrorsNeverFailures(): void
    {
        $response = $this->send('/forms/booking', [
            ...$this->booking(),
            'service' => ['coloring'],
            'size' => 'xxl',
            'date' => '2026-02-30',
            'time' => '25:00',
            'name' => str_repeat('a', 5000),
            'email' => ['a' => 'b'],
            'extra' => ['nested' => ['deep' => 'x']],
        ], json: true);

        self::assertSame(422, $response->getStatusCode());
        $errors = self::json($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertSame(['name', 'service', 'size', 'date', 'time'], array_keys($errors));
        self::assertSame(0, $this->stored());
    }

    public function testThePageUrlIsTheSameOriginReferer(): void
    {
        $this->send('/forms/booking', $this->booking(), json: true, headers: ['Referer' => 'https://localhost/about?x=1']);
        $this->send('/forms/booking', $this->booking(), json: true, headers: ['Referer' => 'https://evil.example/about']);

        $repository = $this->app->container()->service(MessageRepository::class);
        self::assertSame(['https://localhost/forms/booking', 'https://localhost/about?x=1'], array_map(static fn($message): string => $message->pageUrl, $repository->latest(2, 0)));
        $repository->deleteAll();

        // Userinfo and a look-alike host are other origins.
        $this->send('/forms/booking', $this->booking(), json: true, headers: ['Referer' => 'https://evil.example@localhost/x']);
        $this->send('/forms/booking', $this->booking(), json: true, headers: ['Referer' => 'https://localhost.evil.example/x']);
        self::assertSame(['https://localhost/forms/booking', 'https://localhost/forms/booking'], array_map(static fn($message): string => $message->pageUrl, $repository->latest(2, 0)));
    }

    public function testOnlyAFormBodyIsAccepted(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://localhost/forms/booking', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Origin', 'https://localhost')
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream((string) json_encode($this->booking())));

        self::assertSame(415, $this->app->handle($request)->getStatusCode());
        self::assertSame(0, $this->stored());
    }

    /**
     * A JSON refusal without its fresh token, which is checked here.
     *
     * @return array<mixed>
     */
    private static function refusal(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = self::json($response);
        self::assertMatchesRegularExpression('/^\d+\.[A-Za-z0-9_-]{43}$/', is_string($data['token'] ?? null) ? $data['token'] : '');
        unset($data['token']);

        return $data;
    }

    public function testAPostFromAnotherSiteIsRefused(): void
    {
        $response = $this->browser->post('/forms/booking', [...$this->booking(), '_form' => $this->token()], ['Origin' => 'https://evil.example', 'Accept' => 'application/json']);

        self::assertSame(419, $response->getStatusCode());
        self::assertSame(0, $this->stored());
    }
}
