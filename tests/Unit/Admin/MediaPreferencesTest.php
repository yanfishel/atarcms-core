<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\Media\MediaPreferences;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class MediaPreferencesTest extends TestCase
{
    public function testTheSiteAddressDecidesTheSecureFlag(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'https://proxy.local/admin/media?view=list')->withQueryParams(['view' => 'list']);

        $plain = MediaPreferences::remember($request, $factory->createResponse(), MediaPreferences::LIBRARY_VIEW, 'http://localhost:8080');
        $secure = MediaPreferences::remember($request->withUri($factory->createUri('http://behind.proxy/admin/media')), $factory->createResponse(), MediaPreferences::LIBRARY_VIEW, 'https://example.com');

        self::assertSame('admin_media_view=list; Path=/admin; Max-Age=31536000; HttpOnly; SameSite=Lax', $plain->getHeaderLine('Set-Cookie'));
        self::assertSame('admin_media_view=list; Path=/admin; Max-Age=31536000; HttpOnly; SameSite=Lax; Secure', $secure->getHeaderLine('Set-Cookie'));
    }
}
