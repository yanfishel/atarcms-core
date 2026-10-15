<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Cli;

use AtarCms\Cli\Console;
use AtarCms\Cli\Output;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Fixtures\Cli\DuplicateGreetCommand;
use AtarCms\Tests\Fixtures\Cli\ExplodingCommand;
use AtarCms\Tests\Fixtures\Cli\FailingCommand;
use AtarCms\Tests\Fixtures\Cli\GreetCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsoleTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private Console $console;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $this->stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $this->console = new Console(new Container(), [GreetCommand::class, FailingCommand::class, ExplodingCommand::class]);
    }

    public function testRunsCommandWithInput(): void
    {
        self::assertSame(0, $this->invokeCommand(['greet', 'Анна', '--loud']));
        self::assertSame("Hello Анна!\n", $this->read($this->stdout));
    }

    public function testListsCommandsByDefaultWithoutConstructingThem(): void
    {
        self::assertSame(0, $this->invokeCommand([]));
        $listing = $this->read($this->stdout);
        self::assertStringContainsString('fail', $listing);
        self::assertStringContainsString('greet', $listing);
        self::assertStringContainsString('Say hello', $listing);
        self::assertStringContainsString('Must never be constructed unless it runs', $listing);
        self::assertSame('', $this->read($this->stderr));
    }

    public function testRunsOnlyTheRequestedCommand(): void
    {
        self::assertSame(0, $this->invokeCommand(['greet']));
        self::assertSame("Hello world\n", $this->read($this->stdout));
    }

    public function testListShowsTheBuiltInCommands(): void
    {
        self::assertSame(0, $this->invokeCommand(['list']));
        $listing = $this->read($this->stdout);
        self::assertMatchesRegularExpression('/^  help\s+Show one command\'s usage: help \[<command>\]$/m', $listing);
        self::assertMatchesRegularExpression('/^  list\s+List the commands$/m', $listing);
        self::assertLessThan(strpos($listing, '  list '), strpos($listing, '  help '), 'sorted by name');
        self::assertLessThan(strpos($listing, '  list '), strpos($listing, '  greet '), 'sorted by name');
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('listings')]
    public function testHelpWithoutACommandIsTheListing(array $argv): void
    {
        $listing = $this->capture(['list']);

        self::assertSame(0, $this->invokeCommand($argv));
        self::assertSame($listing, $this->read($this->stdout));
        self::assertSame('', $this->read($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function listings(): iterable
    {
        yield 'help' => [['help']];
        yield '--help' => [['--help']];
        yield '-h' => [['-h']];
    }

    public function testHelpForACommandPrintsItsDescriptionAndUsage(): void
    {
        self::assertSame(0, $this->invokeCommand(['help', 'greet']));
        self::assertSame("Say hello\nUsage: php bin/atar greet [<name>] [--loud]\n", $this->read($this->stdout));
        self::assertSame('', $this->read($this->stderr));
    }

    public function testHelpNeverConstructsTheCommand(): void
    {
        self::assertSame(0, $this->invokeCommand(['help', 'explode']));
        self::assertSame("Must never be constructed unless it runs\nUsage: php bin/atar explode\n", $this->read($this->stdout));
    }

    public function testHelpForTheBuiltInCommands(): void
    {
        self::assertSame(0, $this->invokeCommand(['help', 'help']));
        self::assertSame(0, $this->invokeCommand(['help', 'list']));
        self::assertSame(
            "Show one command's usage\nUsage: php bin/atar help [<command>]\nList the commands\nUsage: php bin/atar list\n",
            $this->read($this->stdout),
        );
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('helpAboutHelp')]
    public function testAHelpFlagAfterHelpIsHelpAboutHelp(array $argv): void
    {
        self::assertSame(0, $this->invokeCommand($argv));
        self::assertSame("Show one command's usage\nUsage: php bin/atar help [<command>]\n", $this->read($this->stdout));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function helpAboutHelp(): iterable
    {
        yield '--help' => [['help', '--help']];
        yield '-h' => [['help', '-h']];
    }

    public function testHelpForAnUnknownCommandFails(): void
    {
        self::assertSame(1, $this->invokeCommand(['help', 'unknown']));
        self::assertSame('', $this->read($this->stdout));
        self::assertSame("Unknown command \"unknown\". Run \"list\" to see available commands.\n", $this->read($this->stderr));
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('helpFlags')]
    public function testAHelpFlagShowsTheHelpInsteadOfRunningTheCommand(array $argv): void
    {
        self::assertSame(0, $this->invokeCommand($argv));
        self::assertSame("Must never be constructed unless it runs\nUsage: php bin/atar explode\n", $this->read($this->stdout));
        self::assertSame('', $this->read($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function helpFlags(): iterable
    {
        yield '--help' => [['explode', '--help']];
        yield '-h' => [['explode', '-h']];
        yield 'after other arguments' => [['explode', 'a', '--x=1', '--help']];
        yield '--help with a value' => [['explode', '--help=x']];
    }

    public function testAHelpFlagAfterTheOptionsEndIsAnArgument(): void
    {
        self::assertSame(0, $this->invokeCommand(['greet', '--', '-h']));
        self::assertSame("Hello -h\n", $this->read($this->stdout));
    }

    public function testUnknownCommandFails(): void
    {
        self::assertSame(1, $this->invokeCommand(['unknown']));
        self::assertStringContainsString('Unknown command "unknown"', $this->read($this->stderr));
    }

    public function testCommandExceptionsBecomeExitCodeOne(): void
    {
        self::assertSame(1, $this->invokeCommand(['fail']));
        self::assertStringContainsString('nope', $this->read($this->stderr));
    }

    public function testDuplicateNamesFailEveryInvocation(): void
    {
        $console = new Console(new Container(), [GreetCommand::class, DuplicateGreetCommand::class]);

        foreach ([[], ['greet']] as $argv) {
            self::assertSame(1, $console->run($argv, new Output($this->stdout, $this->stderr)));
        }
        $errors = $this->read($this->stderr);
        self::assertStringContainsString(GreetCommand::class, $errors);
        self::assertStringContainsString(DuplicateGreetCommand::class, $errors);
    }

    /**
     * @param list<string> $argv
     */
    private function invokeCommand(array $argv): int
    {
        return $this->console->run($argv, new Output($this->stdout, $this->stderr));
    }

    /**
     * @param list<string> $argv
     *
     * @return string the standard output of a successful run on streams of its own
     */
    private function capture(array $argv): string
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        self::assertSame(0, $this->console->run($argv, new Output($stdout, $stderr)));

        return $this->read($stdout);
    }

    /**
     * @param resource $stream
     */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
