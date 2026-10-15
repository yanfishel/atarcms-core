<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\Env;
use AtarCms\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    use TempDirectory;

    public function testParsesPairsCommentsAndQuotes(): void
    {
        $values = Env::parse(<<<'ENV'
            # comment
            APP_NAME=AtarCMS
            DB_PASSWORD="se#cret value"
            SINGLE='quoted'
            EMPTY=
            WITH_COMMENT=value # trailing comment
            SPACED = spaced
            ENV);

        self::assertSame([
            'APP_NAME' => 'AtarCMS',
            'DB_PASSWORD' => 'se#cret value',
            'SINGLE' => 'quoted',
            'EMPTY' => '',
            'WITH_COMMENT' => 'value',
            'SPACED' => 'spaced',
        ], $values);
    }

    public function testHandlesQuotedValueWithHashInsideAndTrailingComment(): void
    {
        $values = Env::parse(<<<'ENV'
            A="se#cret" # note
            B='x' #c
            ENV);

        self::assertSame(['A' => 'se#cret', 'B' => 'x'], $values);
    }

    public function testHandlesWindowsLineEndingsAndBom(): void
    {
        $values = Env::parse("\xEF\xBB\xBFFIRST=1\r\nSECOND=two\r\n");

        self::assertSame(['FIRST' => '1', 'SECOND' => 'two'], $values);
    }

    public function testRejectsMalformedLine(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid .env line 2');

        Env::parse("GOOD=1\nnot a pair\n");
    }

    public function testAMalformedLineIsReportedWithoutItsValue(): void
    {
        foreach (["export DB_PASSWORD=Leak-Me-1\n" => 'DB_PASSWORD', "db_password=Leak-Me-1\n" => 'db_password'] as $contents => $name) {
            try {
                Env::parse($contents);
                self::fail('no exception');
            } catch (ConfigException $e) {
                self::assertStringNotContainsString('Leak-Me-1', $e->getMessage());
                self::assertStringContainsString('line 1', $e->getMessage());
                self::assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    public function testTypedAccessors(): void
    {
        $env = Env::fromArray(['DEBUG' => 'true', 'OFF' => '0', 'PORT' => '3306', 'NAME' => 'x']);

        self::assertTrue($env->bool('DEBUG'));
        self::assertFalse($env->bool('OFF', true));
        self::assertTrue($env->bool('MISSING', true));
        self::assertSame(3306, $env->int('PORT', 1));
        self::assertSame(5, $env->int('MISSING', 5));
        self::assertSame('x', $env->required('NAME'));
        self::assertSame('fallback', $env->get('MISSING', 'fallback'));
        self::assertNull($env->get('MISSING'));
    }

    public function testInvalidBooleanThrows(): void
    {
        $this->expectException(ConfigException::class);

        Env::fromArray(['DEBUG' => 'maybe'])->bool('DEBUG');
    }

    public function testInvalidIntegerThrows(): void
    {
        $this->expectException(ConfigException::class);

        Env::fromArray(['PORT' => '33a'])->int('PORT', 1);
    }

    public function testRequiredMissingThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_KEY');

        Env::fromArray([])->required('APP_KEY');
    }

    public function testLoadReadsFileAndProcessEnvironmentWins(): void
    {
        $file = $this->writeFile('.env', "ATAR_TEST_FROM_FILE=file\nATAR_TEST_OVERRIDE=file\n");
        putenv('ATAR_TEST_OVERRIDE=process');

        try {
            $env = Env::load($file);
            self::assertSame('file', $env->get('ATAR_TEST_FROM_FILE'));
            self::assertSame('process', $env->get('ATAR_TEST_OVERRIDE'));
        } finally {
            putenv('ATAR_TEST_OVERRIDE');
        }
    }

    public function testLoadWithMissingFileHasNoFileValues(): void
    {
        self::assertNull(Env::load($this->tempDir() . '/missing.env')->get('ATAR_TEST_NOT_SET'));
    }
}
