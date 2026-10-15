<?php

declare(strict_types=1);

namespace AtarCms\Cli;

final class Input
{
    /**
     * @param list<string> $arguments
     * @param array<string, string|true> $options
     */
    private function __construct(
        private readonly array $arguments,
        private readonly array $options,
    ) {}

    /**
     * @param list<string> $argv arguments after the command name
     */
    public static function parse(array $argv): self
    {
        $arguments = [];
        $options = [];
        $optionsEnded = false;
        foreach ($argv as $token) {
            if (!$optionsEnded && $token === '--') {
                $optionsEnded = true;

                continue;
            }
            if (!$optionsEnded && str_starts_with($token, '--')) {
                $pair = explode('=', substr($token, 2), 2);
                $options[$pair[0]] = $pair[1] ?? true;

                continue;
            }
            $arguments[] = $token;
        }

        return new self($arguments, $options);
    }

    public function argument(int $index): ?string
    {
        return $this->arguments[$index] ?? null;
    }

    public function requireArgument(int $index, string $name): string
    {
        return $this->arguments[$index] ?? throw new CliException(sprintf('Missing argument <%s>', $name));
    }

    public function option(string $name): string|true|null
    {
        return $this->options[$name] ?? null;
    }
}
