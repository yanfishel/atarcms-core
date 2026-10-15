<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\SocialMeta;
use PHPUnit\Framework\TestCase;

final class SocialMetaTest extends TestCase
{
    public function testOpenGraphLocalesUseAnUpperCaseRegion(): void
    {
        self::assertSame('en', SocialMeta::ogLocale('en'));
        self::assertSame('ru', SocialMeta::ogLocale('ru'));
        self::assertSame('pt_BR', SocialMeta::ogLocale('pt-br'));
        self::assertSame('zh', SocialMeta::ogLocale('zh-hans'), 'a script subtag is not a region');
        self::assertSame('es', SocialMeta::ogLocale('es-419'), 'a numeric region has no Open Graph form');
    }
}
