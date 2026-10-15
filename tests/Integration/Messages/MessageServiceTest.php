<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Messages;

use AtarCms\Auth\AuditLog;
use AtarCms\Cli\Command\MailTestCommand;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\SiteTime;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormLabels;
use AtarCms\Messages\MailTransport;
use AtarCms\Messages\MessageMailer;
use AtarCms\Messages\MessageRepository;
use AtarCms\Messages\MessageService;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\FrozenClock;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\RecordingMailTransport;
use DateTimeZone;

final class MessageServiceTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private MessageRepository $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $this->clock = new FrozenClock('2026-10-04 12:00:00');
        $this->messages = new MessageRepository($this->db);
    }

    public function testTheAdminMarksAndDeletesMessages(): void
    {
        $service = new MessageService($this->messages, new AuditLog($this->db, $this->clock), $this->clock);
        $id = $this->messages->insert([
            'form' => 'booking',
            'locale' => 'en',
            'phone' => '+972 50 123 4567',
            'fields' => ['service' => 'haircut'],
            'page_url' => 'https://example.com/',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => $this->clock->now(),
        ]);
        $message = $this->messages->find($id) ?? self::fail('stored');

        $service->markRead($message);
        self::assertSame('2026-10-04 12:00:00', $this->messages->find($message->id)?->readAt?->format('Y-m-d H:i:s'));
        self::assertSame(0, $this->messages->unreadCount());
        $service->markUnread($message);
        self::assertSame(1, $this->messages->unreadCount());

        $service->delete($message, null, '203.0.113.7');
        self::assertNull($this->messages->find($message->id));
        $row = $this->db->selectOne('SELECT action, subject_type, subject_id, meta FROM audit_log');
        self::assertSame(['message.deleted', 'message', (string) $message->id], [$row['action'] ?? null, $row['subject_type'] ?? null, $row['subject_id'] ?? null]);
        self::assertSame(['form' => 'booking', 'name' => '+972 50 123 4567', 'email' => null], json_decode(Cast::string($row['meta'] ?? ''), true), 'named by its sender');
    }

    public function testMailTestReportsWhatTheServerSaid(): void
    {
        $recording = new RecordingMailTransport();
        $run = function (MailTestCommand $command, string $email): array {
            $stdout = fopen('php://memory', 'w+') ?: self::fail('No stream');
            $stderr = fopen('php://memory', 'w+') ?: self::fail('No stream');
            $code = $command->run(Input::parse([$email]), new Output($stdout, $stderr));
            rewind($stdout);
            rewind($stderr);

            return [$code, (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr)];
        };

        self::assertSame([0, "Sent a test mail to me@example.com.\n"], $run(new MailTestCommand($this->mailer($recording)), 'me@example.com'));
        self::assertSame('Test mail from AtarCMS', $recording->sent[0]->getSubject());
        [$code, $output] = $run(new MailTestCommand($this->mailer(new RecordingMailTransport(fail: true))), 'me@example.com');
        self::assertSame(1, $code);
        self::assertStringContainsString('The mail was not sent: Connection could not be established', $output);
        [$code, $output] = $run(new MailTestCommand($this->mailer(null)), 'me@example.com');
        self::assertSame(1, $code);
        self::assertStringContainsString('Mail is not set up: set MAIL_DSN', $output);
    }

    private function mailer(?RecordingMailTransport $transport): MessageMailer
    {
        $logger = new MemoryLogger();
        $locales = new Locales('en', ['en', 'ru']);
        $translator = new Translator($locales, [dirname(__DIR__, 3) . '/resources/lang'], $logger);
        $mail = $transport === null
            ? MailTransport::fromConfig(new Config(['app' => ['url' => 'https://example.com']]))
            : MailTransport::using($transport, 'site@example.com');
        $settings = new SiteSettings(new SettingsRepository($this->db), $translator);

        $time = new SiteTime(new DateTimeZone('UTC'));

        return new MessageMailer($mail, $settings, $locales, $translator, $time, $logger, FormDefinitions::fromConfig(new Config()), new FormLabels($translator, $time));
    }
}
