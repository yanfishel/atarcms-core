<?php

declare(strict_types=1);

namespace AtarCms\Messages;

use DateTimeImmutable;

/**
 * A message a visitor sent through one of the site's forms ($form: "contact", "booking"…). The reserved fields have
 * their own properties (null when the form has no such field or it was left empty); the other fields are in $fields,
 * by name in the form's config order, empty ones left out. Every value is the visitor's text: escape it.
 */
final readonly class Message
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public int $id,
        public string $form,
        public string $locale,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $body,
        public array $fields,
        public string $pageUrl,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $readAt,
        public ?DateTimeImmutable $mailedAt,
    ) {}

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /**
     * Who sent it: the name, else the e-mail, else the phone ('' when none was given).
     */
    public function sender(): string
    {
        return $this->name ?? $this->email ?? $this->phone ?? '';
    }

    /**
     * The first line of the message, cut to $length characters with "…": the list's preview. Without a message the
     * field values joined with " · " (raw values: the admin shows them with their labels).
     */
    public function preview(int $length = 90): string
    {
        return self::cut($this->body ?? implode(' · ', $this->fields), $length);
    }

    /**
     * $text on one line, cut to $length characters with "…".
     */
    public static function cut(string $text, int $length = 90): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($line) > $length ? rtrim(mb_substr($line, 0, $length - 1)) . '…' : $line;
    }
}
