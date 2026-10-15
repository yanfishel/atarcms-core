<?php

declare(strict_types=1);

namespace AtarCms\Theme\Check;

/**
 * One result of theme:check: an error fails the check, a warning or a note does not. $file is relative to the site
 * root ("themes/x/partials/menu.php"), or a URL for the render smoke; $line is 1-based or null.
 */
final readonly class Finding
{
    public function __construct(
        public Severity $severity,
        public string $check,
        public ?string $file,
        public ?int $line,
        public string $message,
    ) {}

    /**
     * @return array{check: string, file: ?string, line: ?int, message: string}
     */
    public function toArray(): array
    {
        return ['check' => $this->check, 'file' => $this->file, 'line' => $this->line, 'message' => $this->message];
    }

    public function format(): string
    {
        $where = $this->file === null ? '' : ' ' . $this->file . ($this->line === null ? '' : ':' . $this->line);

        return sprintf('%s [%s]%s %s', strtoupper($this->severity->value), $this->check, $where, $this->message);
    }
}
