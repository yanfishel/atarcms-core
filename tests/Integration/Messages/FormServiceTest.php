<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Messages;

use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Security\PublicFormToken;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\AppKey;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\SiteTime;
use AtarCms\Messages\Form\FieldValidator;
use AtarCms\Messages\Form\FormDefinition;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormInput;
use AtarCms\Messages\Form\FormLabels;
use AtarCms\Messages\Form\FormService;
use AtarCms\Messages\MailTransport;
use AtarCms\Messages\MessageMailer;
use AtarCms\Messages\MessageRepository;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\FrozenClock;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\RecordingMailTransport;
use AtarCms\Tests\Support\TempDirectory;
use DateTimeZone;

final class FormServiceTest extends DatabaseTestCase
{
    use TempDirectory;

    private const array CONTACT = [
        'name' => 'Anna Petrova',
        'email' => 'anna@example.com',
        'phone' => '+7 900 123-45-67',
        'message' => "Hello!\nI would like to know more.",
    ];

    private const array BOOKING = [
        'name' => 'Dana',
        'phone' => '+972 50 123 4567',
        'service' => 'coloring',
        'date' => '2026-10-20',
        'time' => '10:30',
        'consent' => 'on',
    ];

    private FrozenClock $clock;

    private MessageRepository $messages;

    private PublicFormToken $tokens;

    private MemoryLogger $logger;

    private FormDefinitions $forms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $this->clock = new FrozenClock('2026-10-04 12:00:00');
        $this->messages = new MessageRepository($this->db);
        $this->tokens = new PublicFormToken(new AppKey($this->tempDir() . '/app.key'));
        $this->logger = new MemoryLogger();
        $this->forms = FormDefinitions::fromConfig(new Config(['forms' => ['booking' => ['fields' => [
            'name' => ['type' => 'text'],
            'phone' => ['type' => 'tel', 'required' => true],
            'email' => ['type' => 'email'],
            'service' => ['type' => 'select', 'required' => true, 'options' => ['haircut', 'coloring']],
            'date' => ['type' => 'date', 'required' => true, 'min' => 'today', 'max' => '+60 days'],
            'time' => ['type' => 'time', 'min' => '09:00', 'max' => '18:00'],
            'consent' => ['type' => 'checkbox', 'required' => true],
            'message' => ['type' => 'textarea'],
        ]]]]));
    }

    public function testAValidMessageIsStoredWithoutTheSendersAddress(): void
    {
        $result = $this->service()->submit($this->contact(), $this->input('contact', self::CONTACT), 'ru', 'https://example.com/ru/contacts', '203.0.113.7');

        $message = $result->message ?? self::fail('A stored message expected');
        self::assertFalse($result->isDiscarded());
        self::assertSame(['contact', 'ru', 'Anna Petrova', 'anna@example.com', '+7 900 123-45-67', "Hello!\nI would like to know more.", []], [$message->form, $message->locale, $message->name, $message->email, $message->phone, $message->body, $message->fields]);
        self::assertSame('https://example.com/ru/contacts', $message->pageUrl);
        self::assertNull($message->readAt);
        self::assertNull($message->mailedAt, 'mail is off');
        $hash = Cast::string($this->db->scalar('SELECT ip_hash FROM messages'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertStringNotContainsString('203.0.113.7', (string) json_encode($this->db->select('SELECT * FROM messages')));
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM audit_log')), 'a visitor is no audited actor');
    }

    public function testABookingStoresItsReservedFieldsInColumnsAndTheRestInOrder(): void
    {
        $message = $this->service()->submit(
            $this->booking(),
            $this->input('booking', ['ghost' => 'boo', 'message' => '', ...self::BOOKING, 'website' => '']),
            'he',
            'https://example.com/he/',
            null,
        )->message ?? self::fail('stored');

        self::assertSame(['booking', 'Dana', null, '+972 50 123 4567', null], [$message->form, $message->name, $message->email, $message->phone, $message->body]);
        self::assertSame(['service' => 'coloring', 'date' => '2026-10-20', 'time' => '10:30', 'consent' => '1'], $message->fields, 'config order, empty and unknown fields left out');
        self::assertStringNotContainsString('ghost', Cast::string($this->db->scalar('SELECT fields FROM messages')));
    }

    public function testABookingIsValidatedByItsFields(): void
    {
        $this->assertRefused(
            ['phone' => 'validation.field.required', 'service' => 'validation.form.option', 'date' => 'validation.form.date', 'consent' => 'validation.form.checkbox'],
            $this->booking(),
            $this->input('booking', [...self::BOOKING, 'phone' => '', 'service' => 'massage', 'date' => '2026-02-30', 'consent' => '']),
        );
        $this->assertRefused(['service' => 'validation.field.required'], $this->booking(), $this->input('booking', [...self::BOOKING, 'service' => ['coloring']]));
        self::assertSame(0, $this->messages->count());
    }

    public function testSpamIsDiscardedAsIfItWasSent(): void
    {
        $service = $this->service();

        self::assertTrue($service->submit($this->contact(), $this->input('contact', [...self::CONTACT, 'website' => 'https://spam.example']), 'en', 'https://example.com/contacts', null)->isDiscarded());
        self::assertTrue($service->submit($this->contact(), $this->input('contact', self::CONTACT, $this->tokens->issue('contact', $this->clock->now()->modify('-1 second'))), 'en', 'https://example.com/contacts', null)->isDiscarded());
        self::assertTrue($service->submit($this->booking(), $this->input('booking', [...self::BOOKING, 'website' => 'x']), 'en', 'https://example.com/', null)->isDiscarded());
        self::assertSame(0, $this->messages->count());
    }

    public function testAnOldOrForeignFormIsSentAgain(): void
    {
        $this->assertRefused(['form' => 'validation.message.expired'], $this->contact(), $this->input('contact', self::CONTACT, $this->tokens->issue('contact', $this->clock->now()->modify('-2 days'))));
        $this->assertRefused(['form' => 'validation.message.expired', 'email' => 'validation.email.invalid'], $this->contact(), $this->input('contact', [...self::CONTACT, 'email' => 'x'], 'forged'));
        $this->assertRefused(
            ['form' => 'validation.message.expired'],
            $this->booking(),
            $this->input('booking', self::BOOKING, $this->tokens->issue('contact', $this->clock->now()->modify('-1 minute'))),
        );
    }

    public function testEachSenderSendsAFewMessagesPerTenMinutesAndTwentyADayOverAllForms(): void
    {
        $service = $this->service();
        for ($i = 0; $i < FormService::RATE_LIMIT; ++$i) {
            $service->submit($this->booking(), $this->input('booking', self::BOOKING), 'en', 'https://example.com/', '203.0.113.7');
        }
        $this->assertRefused(['form' => 'validation.message.too_many'], $this->contact(), $this->input('contact', self::CONTACT), '203.0.113.7');
        $service->submit($this->contact(), $this->input('contact', self::CONTACT), 'en', 'https://example.com/contacts', '198.51.100.1');

        $sent = FormService::RATE_LIMIT;
        while ($sent < FormService::DAILY_LIMIT) {
            $this->clock->advance('+11 minutes');
            $service->submit($this->contact(), $this->input('contact', self::CONTACT), 'en', 'https://example.com/contacts', '203.0.113.7');
            ++$sent;
        }
        $this->clock->advance('+11 minutes');
        $this->assertRefused(['form' => 'validation.message.too_many'], $this->contact(), $this->input('contact', self::CONTACT), '203.0.113.7');
        $this->clock->advance('+1 day');
        $service->submit($this->contact(), $this->input('contact', self::CONTACT), 'en', 'https://example.com/contacts', '203.0.113.7');
        self::assertSame(FormService::DAILY_LIMIT + 2, $this->messages->count());
    }

    public function testAMessageIsMailedToTheSiteAddressFromTheSite(): void
    {
        $settings = new SettingsRepository($this->db);
        $settings->put(SiteSettings::EMAIL, '', 'office@example.com', $this->clock->now());
        $settings->put(SiteSettings::SITE_NAME, 'en', 'Fishing club', $this->clock->now());
        $transport = new RecordingMailTransport();

        $message = $this->service($transport)->submit(
            $this->contact(),
            $this->input('contact', [...self::CONTACT, 'name' => "Anna\r\nBcc: victim@example.com " . str_repeat('x', 60)]),
            'ru',
            'https://example.com/ru/contacts',
            null,
        )->message ?? self::fail('stored');

        self::assertSame('2026-10-04 12:00:00', $message->mailedAt?->format('Y-m-d H:i:s'));
        self::assertCount(1, $transport->sent);
        $mail = $transport->sent[0];
        self::assertSame('site@example.com', $mail->getFrom()[0]->getAddress());
        self::assertSame('Fishing club', $mail->getFrom()[0]->getName());
        self::assertSame('office@example.com', $mail->getTo()[0]->getAddress());
        self::assertSame('anna@example.com', $mail->getReplyTo()[0]->getAddress());
        $subject = (string) $mail->getSubject();
        self::assertStringStartsWith('New message from Anna Bcc: victim@example.com xxx', $subject);
        self::assertStringNotContainsString("\n", $subject);
        self::assertStringEndsWith(' on Fishing club', $subject);
        self::assertSame([], $mail->getBcc());
        $text = (string) $mail->getTextBody();
        self::assertStringContainsString("Email: anna@example.com\nPhone: +7 900 123-45-67\nPage: https://example.com/ru/contacts\n", $text);
        self::assertStringContainsString("\n\nHello!\nI would like to know more.\n", $text);
    }

    public function testWithoutAMailServerNothingIsLost(): void
    {
        $settings = new SettingsRepository($this->db);
        $settings->put(SiteSettings::EMAIL, '', 'office@example.com', $this->clock->now());

        $message = $this->service(new RecordingMailTransport(fail: true))->submit($this->contact(), $this->input('contact', self::CONTACT), 'en', 'https://example.com/contacts', null)->message;
        self::assertNotNull($message);
        self::assertNull($message->mailedAt);
        self::assertStringContainsString('could not be e-mailed: Connection could not be established', implode("\n", array_column($this->logger->records, 'message')));

        $settings->put(SiteSettings::EMAIL, '', null, $this->clock->now());
        $recording = new RecordingMailTransport();
        $this->service($recording)->submit($this->contact(), $this->input('contact', self::CONTACT), 'en', 'https://example.com/contacts', null);
        self::assertSame([], $recording->sent);
        self::assertSame(2, $this->messages->count());
    }

    /**
     * @param array<string, string> $errors
     */
    private function assertRefused(array $errors, FormDefinition $form, FormInput $input, ?string $ip = null): void
    {
        try {
            $this->service()->submit($form, $input, 'en', 'https://example.com/contacts', $ip);
            self::fail('A refusal expected');
        } catch (ValidationFailed $failed) {
            self::assertEqualsCanonicalizing($errors, $failed->errors);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function input(string $form, array $body, mixed $token = null): FormInput
    {
        $definition = $this->forms->find($form) ?? self::fail('No form ' . $form);

        return FormInput::fromBody($definition, [...$body, PublicFormToken::FIELD => $token ?? $this->tokens->issue($form, $this->clock->now()->modify('-1 minute'))]);
    }

    private function contact(): FormDefinition
    {
        return $this->forms->find('contact') ?? self::fail('contact expected');
    }

    private function booking(): FormDefinition
    {
        return $this->forms->find('booking') ?? self::fail('booking expected');
    }

    private function service(?RecordingMailTransport $transport = null): FormService
    {
        $time = new SiteTime(new DateTimeZone('UTC'));

        return new FormService(
            $this->messages,
            $this->tokens,
            new FieldValidator($time, $this->clock),
            new AppKey($this->tempDir() . '/app.key'),
            $this->clock,
            $this->logger,
            $this->mailer($transport),
        );
    }

    private function mailer(?RecordingMailTransport $transport): MessageMailer
    {
        $locales = new Locales('en', ['en', 'ru']);
        $translator = new Translator($locales, [dirname(__DIR__, 3) . '/resources/lang'], $this->logger);
        $mail = $transport === null
            ? MailTransport::fromConfig(new Config(['app' => ['url' => 'https://example.com']]))
            : MailTransport::using($transport, 'site@example.com');
        $settings = new SiteSettings(new SettingsRepository($this->db), $translator);

        $time = new SiteTime(new DateTimeZone('UTC'));

        return new MessageMailer($mail, $settings, $locales, $translator, $time, $this->logger, $this->forms, new FormLabels($translator, $time));
    }
}
