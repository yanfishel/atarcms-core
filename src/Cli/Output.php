<?php

declare(strict_types=1);

namespace AtarCms\Cli;

final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct($stdout, $stderr)
    {
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public static function standard(): self
    {
        return new self(STDOUT, STDERR);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    /**
     * Prints $text without a line break (a question before its answer).
     */
    public function write(string $text): void
    {
        fwrite($this->stdout, $text);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $text . PHP_EOL);
    }
}
