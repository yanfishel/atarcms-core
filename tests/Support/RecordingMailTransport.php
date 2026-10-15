<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that keeps what it is given (or fails like a mail server that is down).
 */
final class RecordingMailTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function __construct(private readonly bool $fail = false) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if ($this->fail) {
            throw new TransportException('Connection could not be established with host "smtp.example.com:587"');
        }
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}
