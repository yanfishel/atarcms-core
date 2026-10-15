<?php

declare(strict_types=1);

namespace AtarCms\Cli;

use AtarCms\Kernel\Container;
use Throwable;

final class Console
{
    /**
     * @param list<class-string<Command>> $commands
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $commands,
    ) {}

    /**
     * Only the command that runs is constructed; listing and help read the #[AsCommand] attributes.
     *
     * @param list<string> $argv arguments without the script name
     */
    public function run(array $argv, Output $output): int
    {
        $name = $argv[0] ?? 'list';
        if (self::isHelpFlag($name)) {
            $name = 'help';
        }

        try {
            $commands = new CommandList($this->commands);
            if ($name === 'list') {
                $this->list($commands, $output);

                return 0;
            }
            if ($name === 'help') {
                $topic = $argv[1] ?? null;
                if ($topic === null) {
                    $this->list($commands, $output);

                    return 0;
                }

                return $this->help($commands, self::isHelpFlag($topic) ? 'help' : $topic, $output);
            }
            if (!$commands->has($name)) {
                return self::unknown($name, $output);
            }
            $arguments = array_slice($argv, 1);
            if (self::asksForHelp($arguments)) {
                return $this->help($commands, $name, $output);
            }

            return $this->container->service($commands->class($name))->run(Input::parse($arguments), $output);
        } catch (Throwable $e) {
            $output->error(sprintf('%s: %s', $e::class, $e->getMessage()));

            return 1;
        }
    }

    /**
     * Whether a command's arguments hold --help, --help=… or -h before the "--" that ends the options.
     *
     * @param list<string> $arguments the arguments after the command name
     */
    public static function asksForHelp(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($argument === '--') {
                return false;
            }
            if (self::isHelpFlag($argument)) {
                return true;
            }
        }

        return false;
    }

    private static function isHelpFlag(string $argument): bool
    {
        // Input::parse() reads --help=x as the option "help" too, so it must not run the command either.
        return $argument === '--help' || $argument === '-h' || str_starts_with($argument, '--help=');
    }

    private static function unknown(string $name, Output $output): int
    {
        $output->error(sprintf('Unknown command "%s". Run "list" to see available commands.', $name));

        return 1;
    }

    private function list(CommandList $commands, Output $output): void
    {
        $descriptions = CommandList::BUILT_IN;
        foreach ($commands->names() as $name) {
            $descriptions[$name] = $commands->description($name);
        }
        ksort($descriptions);
        $output->line('Available commands:');
        $width = max(array_map(strlen(...), array_keys($descriptions)));
        foreach ($descriptions as $name => $description) {
            $output->line(sprintf('  %s  %s', str_pad($name, $width), $description));
        }
    }

    private function help(CommandList $commands, string $name, Output $output): int
    {
        $description = CommandList::BUILT_IN[$name] ?? ($commands->has($name) ? $commands->description($name) : null);
        if ($description === null) {
            return self::unknown($name, $output);
        }
        [$text, $usage] = CommandList::splitUsage($name, $description);
        $output->line($text);
        $output->line('Usage: php bin/atar ' . $usage);

        return 0;
    }
}
