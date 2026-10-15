<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

/**
 * The doctor's findings in the order they were checked, as text lines or as JSON data.
 */
final readonly class DoctorReport
{
    /**
     * @param list<CheckResult> $results
     */
    public function __construct(public array $results) {}

    /** 0 without failures (and, when strict, without warnings), else 1. */
    public function exitCode(bool $strict): int
    {
        return $this->ok($strict) ? 0 : 1;
    }

    public function ok(bool $strict): bool
    {
        return $this->count(Status::Fail) === 0 && (!$strict || $this->count(Status::Warn) === 0);
    }

    public function count(Status $status): int
    {
        return count(array_filter($this->results, static fn(CheckResult $result): bool => $result->status === $status));
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $width = max([14, ...array_map(static fn(CheckResult $result): int => strlen($result->id), $this->results)]) + 1;
        $lines = [];
        foreach ($this->results as $result) {
            $lines[] = rtrim(sprintf('%s  %s %s', $result->status->label(), str_pad($result->id, $width), $result->message));
            if ($result->fix !== null && $result->fix !== '') {
                $lines[] = str_repeat(' ', 6 + $width + 1) . 'fix: ' . $result->fix;
            }
        }
        $lines[] = '';
        $lines[] = 'doctor: ' . $this->summary();

        return $lines;
    }

    /**
     * @return array{ok: bool, checks: list<array{id: string, status: string, message: string, fix: ?string}>}
     */
    public function toArray(bool $strict): array
    {
        return ['ok' => $this->ok($strict), 'checks' => array_map(static fn(CheckResult $result): array => $result->toArray(), $this->results)];
    }

    private function summary(): string
    {
        $parts = [];
        $failures = $this->count(Status::Fail);
        $parts[] = $failures === 0 ? 'no problems found' : sprintf('%d failure%s', $failures, $failures === 1 ? '' : 's');
        $warnings = $this->count(Status::Warn);
        if ($warnings > 0) {
            $parts[] = sprintf('%d warning%s', $warnings, $warnings === 1 ? '' : 's');
        }
        $skipped = $this->count(Status::Skip);
        if ($skipped > 0) {
            $parts[] = $skipped . ' skipped';
        }

        return implode(', ', $parts);
    }
}
