<?php

declare(strict_types=1);

namespace AtarCms\Cli;

use ReflectionClass;

/**
 * The console commands by name, read from their #[AsCommand] attributes without constructing them.
 */
final class CommandList
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9]*(?:[:-][a-z0-9]+)*$/';

    /** The commands Console itself answers, by name: their descriptions; no command class may take these names. */
    public const array BUILT_IN = [
        'help' => "Show one command's usage: help [<command>]",
        'list' => 'List the commands',
    ];

    /** @var array<string, array{class: class-string<Command>, description: string}> */
    private readonly array $commands;

    /**
     * @param list<string> $classes
     *
     * @throws CliException for a class that is not a command, has no attribute or an invalid name, and for duplicate names
     */
    public function __construct(array $classes)
    {
        $commands = [];
        foreach ($classes as $class) {
            if (!is_subclass_of($class, Command::class)) {
                throw new CliException(sprintf('%s does not implement %s', $class, Command::class));
            }
            $attributes = (new ReflectionClass($class))->getAttributes(AsCommand::class);
            if ($attributes === []) {
                throw new CliException(sprintf('%s has no #[AsCommand] attribute', $class));
            }
            $info = $attributes[0]->newInstance();
            if (preg_match(self::NAME_PATTERN, $info->name) !== 1) {
                throw new CliException(sprintf(
                    'Invalid command name "%s" in %s; use lowercase words separated by ":" or "-"',
                    $info->name,
                    $class,
                ));
            }
            if (isset(self::BUILT_IN[$info->name])) {
                throw new CliException(sprintf('The command name "%s" in %s is reserved for the built-in command', $info->name, $class));
            }
            if (isset($commands[$info->name])) {
                throw new CliException(sprintf(
                    'The command "%s" is defined by both %s and %s',
                    $info->name,
                    $commands[$info->name]['class'],
                    $class,
                ));
            }
            $commands[$info->name] = ['class' => $class, 'description' => $info->description];
        }
        ksort($commands);
        $this->commands = $commands;
    }

    /**
     * @return list<string> sorted
     */
    public function names(): array
    {
        return array_keys($this->commands);
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * @return class-string<Command>
     */
    public function class(string $name): string
    {
        return $this->commands[$name]['class'] ?? throw new CliException(sprintf('Unknown command "%s"', $name));
    }

    public function description(string $name): string
    {
        return $this->commands[$name]['description'] ?? throw new CliException(sprintf('Unknown command "%s"', $name));
    }

    /**
     * A description ends in its usage after ": <name>" ('Delete old files: media:cleanup [--dry-run]'); without that
     * tail the usage is the bare name.
     *
     * @return array{string, string} the text and the usage
     */
    public static function splitUsage(string $name, string $description): array
    {
        if (preg_match('/^(.*?): (' . preg_quote($name, '/') . '(?: .*)?)$/s', $description, $parts) === 1) {
            return [$parts[1], $parts[2]];
        }

        return [$description, $name];
    }
}
