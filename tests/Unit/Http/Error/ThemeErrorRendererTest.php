<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Error;

use AtarCms\Http\Error\ThemeErrorRenderer;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\View\Renderer;
use AtarCms\View\ThemeResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class ThemeErrorRendererTest extends TestCase
{
    use TempDirectory;

    public function testUsesStatusSpecificTemplate(): void
    {
        $this->writeFile('theme/errors/404.php', 'custom <?= $this->e($status) ?> <?= $this->e($reason) ?>');

        self::assertSame('custom 404 Not Found', $this->render(404));
    }

    public function testFallsBackToGenericTemplate(): void
    {
        $this->writeFile('theme/errors/error.php', 'generic <?= $this->e($status) ?>');

        self::assertSame('generic 500', $this->render(500));
    }

    public function testFallsBackToPlainPageWithoutTemplates(): void
    {
        self::assertStringContainsString('<h1>403 Forbidden</h1>', $this->render(403));
    }

    public function testBrokenTemplateFallsBackToPlainPageAndLogs(): void
    {
        $this->writeFile('theme/errors/404.php', 'before<?php throw new RuntimeException("template broke"); ?>');
        $logger = new MemoryLogger();

        $html = $this->render(404, $logger);

        self::assertStringContainsString('<h1>404 Not Found</h1>', $html);
        self::assertStringNotContainsString('before', $html);
        self::assertSame('template broke', $logger->records[0]['context']['message']);
    }

    public function testPassesTheRequestToTemplates(): void
    {
        $this->writeFile('theme/errors/404.php', 'missing <?= $this->e($this->path()) ?>');
        $renderer = new Renderer(new ThemeResolver([$this->tempDir() . '/theme']));
        $renderer->requestHelper('path', static fn(ServerRequestInterface $request): string => $request->getUri()->getPath());

        $html = (new ThemeErrorRenderer($renderer, new MemoryLogger()))
            ->render(new RuntimeException('x'), 404, (new Psr17Factory())->createServerRequest('GET', '/gone'));

        self::assertSame('missing /gone', $html);
    }

    private function render(int $status, ?MemoryLogger $logger = null): string
    {
        $renderer = new Renderer(new ThemeResolver([$this->tempDir() . '/theme']));

        return (new ThemeErrorRenderer($renderer, $logger ?? new MemoryLogger()))->render(
            new RuntimeException('secret detail'),
            $status,
            (new Psr17Factory())->createServerRequest('GET', '/x'),
        );
    }
}
