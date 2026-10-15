<?php

declare(strict_types=1);

namespace AtarCms\Theme\Check;

/**
 * What theme:check found: the findings in the order of the checks and the pages the render smoke requested.
 */
final class Report
{
    /** @var list<Finding> */
    private array $findings = [];

    /** @var list<array{url: string, status: int}> */
    private array $pages = [];

    public function __construct(public readonly string $theme) {}

    public function error(string $check, ?string $file, ?int $line, string $message): void
    {
        $this->findings[] = new Finding(Severity::Error, $check, $file, $line, $message);
    }

    public function warning(string $check, ?string $file, ?int $line, string $message): void
    {
        $this->findings[] = new Finding(Severity::Warning, $check, $file, $line, $message);
    }

    public function info(string $check, ?string $file, string $message): void
    {
        $this->findings[] = new Finding(Severity::Info, $check, $file, null, $message);
    }

    public function page(string $url, int $status): void
    {
        $this->pages[] = ['url' => $url, 'status' => $status];
    }

    /**
     * @return list<Finding>
     */
    public function findings(?Severity $severity = null): array
    {
        return $severity === null
            ? $this->findings
            : array_values(array_filter($this->findings, static fn(Finding $f): bool => $f->severity === $severity));
    }

    /**
     * @return list<array{url: string, status: int}>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    public function ok(): bool
    {
        return $this->findings(Severity::Error) === [];
    }

    /**
     * @return array{theme: string, ok: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, info: list<array<string, mixed>>, pages: list<array{url: string, status: int}>}
     */
    public function toArray(): array
    {
        $list = fn(Severity $severity): array => array_map(static fn(Finding $f): array => $f->toArray(), $this->findings($severity));

        return [
            'theme' => $this->theme,
            'ok' => $this->ok(),
            'errors' => $list(Severity::Error),
            'warnings' => $list(Severity::Warning),
            'info' => $list(Severity::Info),
            'pages' => $this->pages,
        ];
    }
}
