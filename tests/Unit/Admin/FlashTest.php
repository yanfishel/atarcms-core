<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\Flash;
use AtarCms\Admin\FlashMessage;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Session\Session;
use PHPUnit\Framework\TestCase;

final class FlashTest extends TestCase
{
    public function testMessagesArePulledOnceInOrder(): void
    {
        $session = new Session('id');
        Flash::success($session, 'admin.entries.flash.saved');
        Flash::error($session, 'validation.entry.has_children', ['count' => 2]);

        self::assertTrue($session->isDirty());
        self::assertEquals(
            [
                new FlashMessage('success', 'admin.entries.flash.saved', []),
                new FlashMessage('error', 'validation.entry.has_children', ['count' => 2]),
            ],
            Flash::pull($session),
        );
        self::assertSame([], Flash::pull($session));
        self::assertFalse($session->has(Flash::SESSION_KEY));
    }

    public function testRefusalFlashesTheFirstErrorWithItsParams(): void
    {
        $session = new Session('id');
        Flash::refusal($session, new ValidationFailed(
            ['media' => 'validation.media.in_use', 'other' => 'validation.entry.has_children'],
            ['media' => ['count' => 2]],
        ));
        Flash::refusal($session, new ValidationFailed([]));

        self::assertEquals([new FlashMessage('error', 'validation.media.in_use', ['count' => 2])], Flash::pull($session));
    }

    public function testMalformedStoredValuesAreIgnored(): void
    {
        $session = new Session('id', [Flash::SESSION_KEY => ['junk', ['type' => 'success'], ['type' => 'other', 'key' => 'x', 'params' => []]]]);

        self::assertSame([], Flash::pull($session));
    }
}
