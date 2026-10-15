<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Session;

use AtarCms\Http\Session\NativeSessionStorage;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Runs in a separate process: native sessions refuse to start once PHPUnit has printed output.
 */
final class NativeSessionStorageTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testRoundTripsAndDestroysThroughTheNativeSaveHandler(): void
    {
        $storage = new NativeSessionStorage(3600);
        $id = bin2hex(random_bytes(32));
        $payload = ['data' => ['user' => 7], 'created_at' => 1, 'last_seen' => 2];

        self::assertNull($storage->read($id));
        $storage->write($id, $payload);
        self::assertSame($payload, $storage->read($id));
        $storage->destroy($id);
        self::assertNull($storage->read($id));
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testReadingAnUnknownIdLeavesNoFileOnDisk(): void
    {
        $storage = new NativeSessionStorage(3600);
        $id = bin2hex(random_bytes(32));

        self::assertNull($storage->read($id));

        $path = (session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $id;
        self::assertFileDoesNotExist($path);
    }
}
