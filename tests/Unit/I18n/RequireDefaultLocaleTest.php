<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\Exception\NotFound;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\RequireDefaultLocale;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RequireDefaultLocaleTest extends TestCase
{
    public function testUnprefixedAndUnresolvedRequestsPass(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/admin');

        self::assertSame(204, $this->process($request)->getStatusCode());
        self::assertSame(204, $this->process($request->withAttribute(RequestLocale::class, new RequestLocale('en', false)))->getStatusCode());
    }

    public function testPrefixedRequestIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->process((new Psr17Factory())->createServerRequest('GET', '/admin')->withAttribute(RequestLocale::class, new RequestLocale('ru', true)));
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        return (new RequireDefaultLocale())->process(
            $request,
            new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(204)),
        );
    }
}
