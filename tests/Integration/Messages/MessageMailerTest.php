<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Messages;

use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\SiteTime;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormLabels;
use AtarCms\Messages\MailTransport;
use AtarCms\Messages\Message;
use AtarCms\Messages\MessageMailer;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\RecordingMailTransport;
use AtarCms\Tests\Support\TempDirectory;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Each form is mailed with its own subject, the theme's labels in the site's default language and its recipient.
 */
final class MessageMailerTest extends DatabaseTestCase
{
    use TempDirectory;

    private RecordingMailTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $this->transport = new RecordingMailTransport();
        $settings = new SettingsRepository($this->db);
        $settings->put(SiteSettings::EMAIL, '', 'office@example.com', new DateTimeImmutable());
        $settings->put(SiteSettings::SITE_NAME, 'en', 'Salon', new DateTimeImmutable());
        mkdir($this->tempDir() . '/lang');
        file_put_contents($this->tempDir() . '/lang/en.php', '<?php return ' . var_export(['forms' => [
            'booking' => [
                'title' => 'Book a visit',
                'subject' => 'Booking from {name}',
                'name' => 'Your name',
                'phone' => 'Your phone',
                'service' => 'Service',
                'service.coloring' => 'Hair coloring',
                'date' => 'Day',
                'consent' => 'I agree',
            ],
            'contact' => ['name' => 'Your name (theme)'],
        ]], true) . ';');
    }

    public function testABookingGoesToItsRecipientWithTheThemesLabels(): void
    {
        $this->mailer()->send($this->message('booking', name: 'Dana', email: null, phone: '+972 50 123 4567', fields: [
            'service' => 'coloring',
            'date' => '2026-10-20',
            'time' => '10:30',
            'consent' => '1',
            'removed' => 'old value',
        ], body: "Please call\nin the morning."));

        $mail = $this->transport->sent[0] ?? self::fail('mailed');
        self::assertSame('bookings@example.com', $mail->getTo()[0]->getAddress());
        self::assertSame([], $mail->getReplyTo(), 'no e-mail, no Reply-To');
        self::assertSame('Booking from Dana', $mail->getSubject());
        self::assertSame(
            "A visitor sent the form “Book a visit” on Salon.\n\n"
            . "Your name: Dana\nYour phone: +972 50 123 4567\nService: Hair coloring\nDay: Oct 20, 2026\ntime: 10:30\nI agree: Yes\nremoved: old value\n"
            . "Page: https://example.com/\nSent: Oct 15, 2026, 09:00\n\nPlease call\nin the morning.",
            $mail->getTextBody(),
        );
    }

    public function testWithoutASubjectInTheThemeTheCoreSubjectIsUsed(): void
    {
        $this->mailer()->send($this->message('callback', name: null, email: 'anna@example.com', phone: null));

        $mail = $this->transport->sent[0] ?? self::fail('mailed');
        self::assertSame('office@example.com', $mail->getTo()[0]->getAddress(), 'a form without a recipient: the site e-mail');
        self::assertSame('anna@example.com', $mail->getReplyTo()[0]->getAddress());
        self::assertSame('New message from anna@example.com on Salon', $mail->getSubject());
    }

    public function testTheContactFormKeepsTheCoreLabels(): void
    {
        $this->mailer()->send($this->message('contact', name: "Anna\r\nBcc: x@example.com", email: 'anna@example.com', phone: null, body: 'Hello there, friend.'));

        $mail = $this->transport->sent[0] ?? self::fail('mailed');
        self::assertSame('New message from Anna Bcc: x@example.com on Salon', $mail->getSubject(), 'one line');
        self::assertSame(
            "A visitor wrote to you through the contact form of Salon.\n\nName: Anna\r\nBcc: x@example.com\nEmail: anna@example.com\n"
            . "Page: https://example.com/\nSent: Oct 15, 2026, 09:00\n\nHello there, friend.\n\n-- \nReply to this email to answer Anna Bcc: x@example.com.",
            $mail->getTextBody(),
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function message(string $form, ?string $name, ?string $email, ?string $phone, array $fields = [], ?string $body = null): Message
    {
        return new Message(7, $form, 'en', $name, $email, $phone, $body, $fields, 'https://example.com/', new DateTimeImmutable('2026-10-15 09:00:00'), null, null);
    }

    private function mailer(): MessageMailer
    {
        $logger = new MemoryLogger();
        $locales = new Locales('en', ['en', 'ru']);
        $translator = new Translator($locales, [dirname(__DIR__, 3) . '/resources/lang', $this->tempDir() . '/lang'], $logger);
        $time = new SiteTime(new DateTimeZone('UTC'));
        $forms = FormDefinitions::fromConfig(new Config(['forms' => [
            'booking' => ['recipient' => 'bookings@example.com', 'fields' => [
                'name' => ['type' => 'text'],
                'phone' => ['type' => 'tel', 'required' => true],
                'service' => ['type' => 'select', 'options' => ['haircut', 'coloring']],
                'date' => ['type' => 'date'],
                'time' => ['type' => 'time'],
                'consent' => ['type' => 'checkbox'],
                'message' => ['type' => 'textarea'],
            ]],
            'callback' => ['fields' => ['email' => ['type' => 'email', 'required' => true]]],
        ]]));

        return new MessageMailer(
            MailTransport::using($this->transport, 'site@example.com'),
            new SiteSettings(new SettingsRepository($this->db), $translator),
            $locales,
            $translator,
            $time,
            $logger,
            $forms,
            new FormLabels($translator, $time),
        );
    }
}
