<?php

declare(strict_types=1);

namespace AtarCms\Cli;

/**
 * Questions to the person at the terminal (bin/atar demo:seed's confirmation). A command run by a script, a cron job or
 * a pipe is not interactive: it must be told by an option instead (--force).
 */
final class Prompt
{
    /** @var resource */
    private $input;

    private readonly bool $interactive;

    /**
     * @param resource|null $input the answers (default: the standard input)
     * @param bool|null $interactive null: whether $input is a terminal
     */
    public function __construct(mixed $input = null, ?bool $interactive = null)
    {
        $stream = is_resource($input) ? $input : (defined('STDIN') ? STDIN : fopen('php://stdin', 'r'));
        if (!is_resource($stream)) {
            throw new \RuntimeException('The standard input cannot be read');
        }
        $this->input = $stream;
        $this->interactive = $interactive ?? stream_isatty($stream);
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    /**
     * Prints $question and returns the answer without its line break (null at the end of the input).
     */
    public function ask(string $question, Output $output): ?string
    {
        $output->write($question . ' ');
        $line = fgets($this->input);

        return $line === false ? null : rtrim($line, "\r\n");
    }
}
