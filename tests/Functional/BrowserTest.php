<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

final class BrowserTest extends FunctionalTestCase
{
    public function testGetSendsQueryParamsToTheController(): void
    {
        $response = $this->browser->get('/echo-query?q=%D0%BF%D1%80%D0%B8+x');

        self::assertSame('при x', (string) $response->getBody());
    }
}
