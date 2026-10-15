<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

/**
 * The skeleton site boots from its own config against the test database.
 */
final class SkeletonBootTest extends SkeletonTestCase
{
    public function testTheSkeletonAnswersItsHomePage(): void
    {
        $response = $this->browser->get('/');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }
}
