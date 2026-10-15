<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Cli;

use AtarCms\Cli\CliException;
use AtarCms\Cli\CommandList;
use AtarCms\Tests\Fixtures\Cli\BadNameCommand;
use AtarCms\Tests\Fixtures\Cli\DuplicateGreetCommand;
use AtarCms\Tests\Fixtures\Cli\ExplodingCommand;
use AtarCms\Tests\Fixtures\Cli\FailingCommand;
use AtarCms\Tests\Fixtures\Cli\GreetCommand;
use AtarCms\Tests\Fixtures\Cli\ListNamedCommand;
use AtarCms\Tests\Fixtures\Cli\NotACommand;
use AtarCms\Tests\Fixtures\Cli\UnnamedCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandListTest extends TestCase
{
    public function testReadsNamesAndDescriptionsWithoutConstructing(): void
    {
        $list = new CommandList([GreetCommand::class, ExplodingCommand::class, FailingCommand::class]);

        self::assertSame(['explode', 'fail', 'greet'], $list->names());
        self::assertSame(ExplodingCommand::class, $list->class('explode'));
        self::assertSame('Say hello: greet [<name>] [--loud]', $list->description('greet'));
        self::assertTrue($list->has('fail'));
        self::assertFalse($list->has('unknown'));
    }

    public function testMissingAttribute(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage(UnnamedCommand::class . ' has no #[AsCommand] attribute');

        new CommandList([GreetCommand::class, UnnamedCommand::class]);
    }

    public function testNotACommand(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage(NotACommand::class . ' does not implement');

        new CommandList([NotACommand::class]);
    }

    public function testInvalidNameIsRejected(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('Invalid command name "Bad Name" in ' . BadNameCommand::class);

        new CommandList([BadNameCommand::class]);
    }

    #[DataProvider('names')]
    public function testNamePattern(string $name, bool $valid): void
    {
        self::assertSame($valid, preg_match(CommandList::NAME_PATTERN, $name) === 1);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'simple' => ['migrate', true];
        yield 'namespaced' => ['media:variants:clear', true];
        yield 'dashed' => ['user:login-link', true];
        yield 'digits' => ['cache2:build', true];
        yield 'upper case' => ['Migrate', false];
        yield 'space' => ['a b', false];
        yield 'leading colon' => [':x', false];
        yield 'trailing colon' => ['x:', false];
        yield 'double colon' => ['a::b', false];
        yield 'empty' => ['', false];
    }

    public function testDuplicateNameNamesBothClasses(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage(sprintf('The command "greet" is defined by both %s and %s', GreetCommand::class, DuplicateGreetCommand::class));

        new CommandList([GreetCommand::class, DuplicateGreetCommand::class]);
    }

    public function testBuiltInNamesAreReserved(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage(sprintf('The command name "list" in %s is reserved for the built-in command', ListNamedCommand::class));

        new CommandList([GreetCommand::class, ListNamedCommand::class]);
    }

    /**
     * @param array{string, string} $expected
     */
    #[DataProvider('descriptions')]
    public function testSplitsTheUsageOffTheDescription(string $name, string $description, array $expected): void
    {
        self::assertSame($expected, CommandList::splitUsage($name, $description));
    }

    /**
     * @return iterable<string, array{string, string, array{string, string}}>
     */
    public static function descriptions(): iterable
    {
        yield 'usage tail' => ['media:cleanup', 'Delete old files: media:cleanup [--dry-run]', ['Delete old files', 'media:cleanup [--dry-run]']];
        yield 'bare name tail' => ['greet', 'Say hello: greet', ['Say hello', 'greet']];
        yield 'no tail' => ['migrate', 'Apply pending migrations', ['Apply pending migrations', 'migrate']];
        yield 'colon inside the text' => [
            'share-images:rebuild',
            'Bring them up to date (after a name: change): share-images:rebuild [--collection=<name>]',
            ['Bring them up to date (after a name: change)', 'share-images:rebuild [--collection=<name>]'],
        ];
        yield 'two usages' => [
            'settings:import',
            'Write the settings: settings:import <file.json> [--dry-run] | settings:import --example',
            ['Write the settings', 'settings:import <file.json> [--dry-run] | settings:import --example'],
        ];
        yield 'longer name is not the name' => ['migrate', 'Lists: migrate:status', ['Lists: migrate:status', 'migrate']];
    }

    public function testUnknownName(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('Unknown command "nope"');

        (new CommandList([GreetCommand::class]))->class('nope');
    }
}
