<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

/**
 * One line of the doctor's report: what was checked (a stable id such as "php.memory"), the outcome, a message in
 * plain words and, for a warning or a failure, what to do about it. Messages and fixes never hold secrets.
 */
final readonly class CheckResult
{
    private function __construct(
        public string $id,
        public Status $status,
        public string $message,
        public ?string $fix = null,
    ) {}

    public static function ok(string $id, string $message): self
    {
        return new self($id, Status::Ok, $message);
    }

    public static function warn(string $id, string $message, ?string $fix = null): self
    {
        return new self($id, Status::Warn, $message, $fix);
    }

    public static function fail(string $id, string $message, ?string $fix = null): self
    {
        return new self($id, Status::Fail, $message, $fix);
    }

    public static function skip(string $id, string $message): self
    {
        return new self($id, Status::Skip, $message);
    }

    /**
     * @return array{id: string, status: string, message: string, fix: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'status' => $this->status->value, 'message' => $this->message, 'fix' => $this->fix];
    }
}
