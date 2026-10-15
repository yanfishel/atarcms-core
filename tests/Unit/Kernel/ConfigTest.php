<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\Env;
use AtarCms\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    use TempDirectory;

    public function testLoadsArrayAndClosureFilesKeyedByFileName(): void
    {
        $this->writeFile('config/app.php', <<<'PHP'
            <?php
            return ['name' => 'Site', 'nested' => ['depth' => 2]];
            PHP);
        $this->writeFile('config/database.php', <<<'PHP'
            <?php
            return static fn (AtarCms\Kernel\Env $env): array => ['host' => $env->get('DB_HOST')];
            PHP);

        $config = Config::fromDirectory($this->tempDir() . '/config', Env::fromArray(['DB_HOST' => 'db']));

        self::assertSame('Site', $config->get('app.name'));
        self::assertSame(2, $config->get('app.nested.depth'));
        self::assertSame('db', $config->get('database.host'));
        self::assertNull($config->get('app.missing'));
        self::assertSame('d', $config->get('missing.key', 'd'));
        self::assertSame(['app', 'database'], array_keys($config->all()));
    }

    public function testFileMustReturnArray(): void
    {
        $this->writeFile('config/bad.php', "<?php\nreturn 'nope';\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('bad.php');

        Config::fromDirectory($this->tempDir() . '/config', Env::fromArray([]));
    }

    public function testHasDistinguishesNullFromMissing(): void
    {
        $config = new Config(['app' => ['value' => null]]);

        self::assertTrue($config->has('app.value'));
        self::assertFalse($config->has('app.other'));
        self::assertFalse($config->has('app.value.deeper'));
    }

    public function testTypedGetters(): void
    {
        $config = new Config(['app' => ['name' => 'x', 'port' => 8080, 'debug' => true, 'modules' => ['A']]]);

        self::assertSame('x', $config->string('app.name'));
        self::assertSame('fallback', $config->string('app.missing', 'fallback'));
        self::assertSame(8080, $config->int('app.port'));
        self::assertTrue($config->bool('app.debug'));
        self::assertFalse($config->bool('app.missing', false));
        self::assertSame(['A'], $config->array('app.modules'));
        self::assertSame([], $config->array('app.missing'));
    }

    public function testWithReplacesDottedKeysInACopy(): void
    {
        $config = new Config(['app' => ['theme' => 'default', 'name' => 'x']]);

        $other = $config->with(['app.theme' => 'other', 'content.revisions' => 5]);

        self::assertSame('other', $other->string('app.theme'));
        self::assertSame('x', $other->string('app.name'));
        self::assertSame(5, $other->int('content.revisions'));
        self::assertSame('default', $config->string('app.theme'), 'the original is unchanged');
    }

    public function testTypedGetterRejectsWrongType(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config "app.port" must be string, got int');

        (new Config(['app' => ['port' => 8080]]))->string('app.port');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function environments(): iterable
    {
        yield 'missing' => [[], 'production'];
        yield 'empty' => [['env' => ''], 'production'];
        yield 'blank' => [['env' => '   '], 'production'];
        yield 'upper case' => [['env' => 'Production'], 'production'];
        yield 'padded upper case' => [['env' => "\tPRODUCTION\n"], 'production'];
        yield 'local' => [['env' => 'local'], 'local'];
        yield 'padded mixed case' => [['env' => ' Testing '], 'testing'];
    }

    /**
     * @param array<string, mixed> $app
     */
    #[DataProvider('environments')]
    public function testEnvironmentIsNormalized(array $app, string $expected): void
    {
        self::assertSame($expected, (new Config(['app' => $app]))->environment());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function siteUrls(): iterable
    {
        yield 'https' => ['https://example.com', 'https://example.com'];
        yield 'trailing slash' => ['https://example.com/', 'https://example.com'];
        yield 'port' => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'upper-case scheme' => ['HTTPS://Example.com/', 'HTTPS://Example.com'];
    }

    #[DataProvider('siteUrls')]
    public function testAppUrlIsTheSiteRootWithoutTrailingSlash(string $url, string $expected): void
    {
        self::assertSame($expected, (new Config(['app' => ['url' => $url]]))->appUrl());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSiteUrls(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'relative' => ['/site'];
        yield 'protocol-relative' => ['//example.com'];
        yield 'other scheme' => ['ftp://example.com'];
        yield 'no host' => ['https://'];
        yield 'path' => ['https://example.com/blog'];
        yield 'query' => ['https://example.com/?a=1'];
        yield 'empty query' => ['https://example.com?'];
        yield 'fragment' => ['https://example.com#top'];
        yield 'userinfo' => ['https://user:secret@example.com'];
        yield 'whitespace' => [' https://example.com'];
        yield 'not a string' => [8080];
    }

    #[DataProvider('invalidSiteUrls')]
    public function testAppUrlMustBeAnAbsoluteHttpUrlOfTheSiteRoot(mixed $url): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('app.url');

        (new Config(['app' => $url === null ? [] : ['url' => $url]]))->appUrl();
    }

    public function testAppUrlErrorNeverEchoesUserinfo(): void
    {
        try {
            (new Config(['app' => ['url' => 'https://user:hunter2@example.com']]))->appUrl();
            self::fail('Expected a ConfigException.');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringNotContainsString('user', $e->getMessage());
            self::assertStringContainsString('https://example.com', $e->getMessage());
        }
    }

    public function testTypedGetterWithoutDefaultRequiresValue(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing config "app.theme"');

        (new Config([]))->string('app.theme');
    }
}
