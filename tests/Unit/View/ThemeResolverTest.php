<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use AtarCms\Tests\Support\TempDirectory;
use AtarCms\View\TemplateNotFound;
use AtarCms\View\ThemeResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThemeResolverTest extends TestCase
{
    use TempDirectory;

    public function testFirstDirectoryWins(): void
    {
        $site = $this->writeFile('site/show.php', 'site');
        $this->writeFile('core/show.php', 'core');
        $coreOnly = $this->writeFile('core/errors/404.php', 'core 404');
        $resolver = new ThemeResolver([$this->tempDir() . '/site', $this->tempDir() . '/core']);

        self::assertSame($site, $resolver->find('show'));
        self::assertSame($coreOnly, $resolver->find('errors/404'));
        self::assertNull($resolver->find('missing'));
    }

    public function testResolveTriesCandidatesInOrder(): void
    {
        $generic = $this->writeFile('theme/show.php', 'generic');
        $resolver = new ThemeResolver([$this->tempDir() . '/theme']);

        self::assertSame($generic, $resolver->resolve('collections/news/show', 'show'));
    }

    public function testResolveThrowsWhenNothingExists(): void
    {
        $resolver = new ThemeResolver([$this->tempDir()]);

        $this->expectException(TemplateNotFound::class);
        $this->expectExceptionMessage('collections/news/show, show');

        $resolver->resolve('collections/news/show', 'show');
    }

    #[DataProvider('invalidNameProvider')]
    public function testRejectsTraversalAndInvalidNames(string $name): void
    {
        $resolver = new ThemeResolver([$this->tempDir()]);

        $this->expectException(InvalidArgumentException::class);

        $resolver->find($name);
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            ['../secret'],
            ['a/../b'],
            ['Show'],
            ['show.php'],
            [''],
            ['/abs'],
        ];
    }
}
