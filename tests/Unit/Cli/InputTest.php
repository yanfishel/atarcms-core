<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Cli;

use AtarCms\Cli\CliException;
use AtarCms\Cli\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testParsesArgumentsAndOptions(): void
    {
        $input = Input::parse(['admin@example.com', '--role=admin', '--force', '--', '--literal']);

        self::assertSame('admin@example.com', $input->argument(0));
        self::assertSame('--literal', $input->argument(1));
        self::assertNull($input->argument(2));
        self::assertSame('admin', $input->option('role'));
        self::assertTrue($input->option('force'));
        self::assertNull($input->option('missing'));
    }

    public function testRequireArgument(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('Missing argument <email>');

        Input::parse([])->requireArgument(0, 'email');
    }
}
