<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Error;

use AtarCms\Http\Error\ErrorRenderer;
use AtarCms\Http\Error\PathPrefixErrorRenderer;
use AtarCms\Http\RequestPath;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

final class NamedErrorRendererFixture implements ErrorRenderer
{
    public function __construct(private readonly string $name) {}

    public function render(Throwable $error, int $status, ServerRequestInterface $request): string
    {
        return $this->name;
    }
}

final class PathPrefixErrorRendererTest extends TestCase
{
    public function testChoosesRendererByCanonicalPath(): void
    {
        $renderer = new PathPrefixErrorRenderer('/admin', new NamedErrorRendererFixture('admin'), new NamedErrorRendererFixture('theme'));
        $factory = new Psr17Factory();
        $render = static fn(string $path): string => $renderer->render(
            new RuntimeException('x'),
            404,
            $factory->createServerRequest('GET', $path)->withAttribute(RequestPath::class, RequestPath::fromRaw($path)),
        );

        self::assertSame('admin', $render('/admin'));
        self::assertSame('admin', $render('/admin/users/9'));
        self::assertSame('admin', $render('/%61dmin/x'));
        self::assertSame('theme', $render('/administrator'));
        self::assertSame('theme', $render('/news/x'));
    }

    public function testLocalizedPathUnderTheAdminSegmentIsThemedNotAdmin(): void
    {
        $renderer = new PathPrefixErrorRenderer('/admin', new NamedErrorRendererFixture('admin'), new NamedErrorRendererFixture('theme'));
        $factory = new Psr17Factory();
        $path = RequestPath::fromRaw('/ru/admin')->withoutPrefix('/ru');
        $request = $factory->createServerRequest('GET', '/ru/admin')->withAttribute(RequestPath::class, $path);

        self::assertSame('theme', $renderer->render(new RuntimeException('x'), 404, $request));
    }
}
