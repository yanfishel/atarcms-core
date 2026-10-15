<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Messages;

use AtarCms\Messages\Message;
use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\Cast;
use DateTimeImmutable;

final class MessageRepositoryTest extends DatabaseTestCase
{
    private MessageRepository $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $this->messages = new MessageRepository($this->db);
    }

    public function testABookingKeepsItsFieldsInOrder(): void
    {
        $id = $this->insert('booking', [
            'name' => null,
            'email' => null,
            'phone' => '+972 50 123 4567',
            'body' => null,
            'fields' => ['service' => 'haircut', 'date' => '2026-10-20', 'time' => '10:30', 'note' => 'Шалом, שלום'],
        ]);

        $message = $this->messages->find($id) ?? self::fail('stored');
        self::assertSame('booking', $message->form);
        self::assertSame(['service' => 'haircut', 'date' => '2026-10-20', 'time' => '10:30', 'note' => 'Шалом, שלום'], $message->fields);
        self::assertSame([null, null, '+972 50 123 4567', null], [$message->name, $message->email, $message->phone, $message->body]);
        self::assertStringContainsString('Шалом', Cast::string($this->db->scalar('SELECT fields FROM messages')), 'stored unescaped');
    }

    public function testAContactMessageHasNoFields(): void
    {
        $message = $this->messages->find($this->insert('contact')) ?? self::fail('stored');

        self::assertSame('contact', $message->form);
        self::assertSame([], $message->fields);
        self::assertNull($this->db->scalar('SELECT fields FROM messages'));
    }

    public function testMessagesAreListedAndCountedPerForm(): void
    {
        $this->insert('contact');
        $booking = $this->insert('booking', ['fields' => ['service' => 'haircut']]);
        $this->insert('booking', ['read_at' => new DateTimeImmutable('2026-10-15 10:00:00')]);
        $this->insert('callback');

        self::assertSame(4, $this->messages->count());
        self::assertSame(2, $this->messages->count('booking'));
        self::assertSame(0, $this->messages->count('ghost'));
        self::assertCount(4, $this->messages->latest(10, 0));
        $bookings = $this->messages->latest(10, 0, 'booking');
        self::assertSame(['booking', 'booking'], array_map(static fn(Message $message): string => $message->form, $bookings));
        self::assertContains($booking, array_map(static fn(Message $message): int => $message->id, $bookings));
        self::assertSame(3, $this->messages->unreadCount());
        self::assertSame(['booking' => 1, 'callback' => 1, 'contact' => 1], $this->messages->unreadCountsByForm());
    }

    public function testTheSenderAndThePreviewFallBack(): void
    {
        $message = self::message(...);

        self::assertSame('Anna', $message('Anna', 'anna@example.com', '+1 555 0100', null)->sender());
        self::assertSame('anna@example.com', $message(null, 'anna@example.com', '+1 555 0100', null)->sender());
        self::assertSame('+1 555 0100', $message(null, null, '+1 555 0100', null)->sender());
        self::assertSame('', $message(null, null, null, null)->sender());

        self::assertSame('Hello there', $message('Anna', null, null, "Hello\n  there", ['service' => 'haircut'])->preview());
        self::assertSame('haircut · 2026-10-20 · 10:30', $message('Anna', null, null, null, ['service' => 'haircut', 'date' => '2026-10-20', 'time' => '10:30'])->preview());
        self::assertSame('', $message('Anna', null, null, null)->preview());
    }

    /**
     * @param array<string, string> $fields
     */
    private static function message(?string $name, ?string $email, ?string $phone, ?string $body, array $fields = []): Message
    {
        return new Message(1, 'booking', 'en', $name, $email, $phone, $body, $fields, 'https://example.com/', new DateTimeImmutable(), null, null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $form, array $row = []): int
    {
        return $this->messages->insert([
            'form' => $form,
            'locale' => 'en',
            'name' => 'Anna',
            'email' => 'anna@example.com',
            'body' => 'Hello there, friend.',
            'page_url' => 'https://example.com/',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => new DateTimeImmutable('2026-10-15 09:00:00'),
            ...$row,
        ]);
    }
}
