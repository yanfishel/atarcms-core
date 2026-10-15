<?php

declare(strict_types=1);

namespace AtarCms\Messages;

/**
 * What became of a submitted contact form that was not refused: stored (and maybe e-mailed), or quietly discarded as
 * spam (the honeypot was filled or the form was sent too fast). The visitor sees the same "sent" notice either way.
 */
final readonly class SubmitResult
{
    private function __construct(public ?Message $message) {}

    public static function stored(Message $message): self
    {
        return new self($message);
    }

    public static function discarded(): self
    {
        return new self(null);
    }

    public function isDiscarded(): bool
    {
        return $this->message === null;
    }
}
