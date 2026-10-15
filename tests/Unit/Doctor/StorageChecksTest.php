<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Doctor;

use AtarCms\Doctor\CheckResult;
use AtarCms\Doctor\DoctorContext;
use AtarCms\Doctor\DoctorOptions;
use AtarCms\Doctor\Status;
use AtarCms\Doctor\StorageChecks;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\FakePlatform;
use AtarCms\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

/**
 * One user owns the site and runs its PHP: everything under storage/ belongs to the owner of storage/, bin/atar runs
 * as that user, and storage/app.key is a private key of that user.
 */
final class StorageChecksTest extends TestCase
{
    use TempDirectory;

    private const string KEY = 'f00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeed';

    private FakePlatform $platform;

    private string $storage;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();
        $this->storage = $this->tempDir() . '/storage';
        foreach (['logs', 'cache/media', 'uploads/2026/10', 'tmp'] as $directory) {
            mkdir($this->storage . '/' . $directory, 0o777, true);
        }
        file_put_contents($this->storage . '/uploads/2026/10/a.jpg', 'x');
    }

    public function testAStorageOfOneUserPasses(): void
    {
        file_put_contents($this->storage . '/app.key', self::KEY);

        foreach ($this->results() as $result) {
            self::assertSame(Status::Ok, $result->status, $result->id . ': ' . $result->message);
        }
    }

    public function testAReadOnlyDirectoryFailsWithTheFix(): void
    {
        $this->platform->readOnly[] = $this->storage . '/uploads';

        $result = $this->one('storage.writable');

        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('storage/uploads', $result->message);
        self::assertStringContainsString('chown -R site: storage', (string) $result->fix);
    }

    public function testFilesOfAnotherUserFail(): void
    {
        $this->platform->owners[$this->storage . '/uploads/2026/10/a.jpg'] = 0;
        $this->platform->owners[$this->storage . '/cache/media'] = 0;

        $result = $this->one('storage.owner');

        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('2 entries', $result->message);
        self::assertStringContainsString('root', $result->message);
        self::assertStringContainsString('chown -R site: storage', (string) $result->fix);
    }

    public function testRunningAsRootWarnsAndRunningAsAnotherUserFails(): void
    {
        $this->platform->uid = 0;
        $result = $this->one('storage.owner');
        self::assertSame(Status::Warn, $result->status);
        self::assertStringContainsString('sudo -u site php bin/atar', (string) $result->fix);

        $this->platform->uid = 33;
        $result = $this->one('storage.owner');
        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('www-data', $result->message);
    }

    public function testARootOwnedButWritableStorageOnlyWarns(): void
    {
        // Docker Desktop's mounts of Windows and macOS folders: every file belongs to root and anyone may write.
        $this->platform->defaultOwner = 0;
        $this->platform->uid = 82;

        self::assertSame(Status::Warn, $this->one('storage.owner')->status);
    }

    public function testWithoutPosixTheOwnershipIsNotChecked(): void
    {
        $this->platform->uid = null;

        self::assertSame(Status::Skip, $this->one('storage.owner')->status);
    }

    public function testTheSiteKey(): void
    {
        self::assertStringContainsString('first use', $this->one('storage.app_key')->message);

        file_put_contents($this->storage . '/app.key', 'secret-but-wrong');
        $result = $this->one('storage.app_key');
        self::assertSame(Status::Fail, $result->status);
        self::assertStringNotContainsString('secret-but-wrong', $result->message . $result->fix);

        file_put_contents($this->storage . '/app.key', self::KEY);
        $this->platform->modes[$this->storage . '/app.key'] = 0o644;
        self::assertSame(Status::Warn, $this->one('storage.app_key')->status);

        $this->platform->modes[$this->storage . '/app.key'] = 0o600;
        $this->platform->owners[$this->storage . '/app.key'] = 0;
        self::assertSame(Status::Fail, $this->one('storage.app_key')->status);
    }

    /**
     * @return list<CheckResult>
     */
    private function results(): array
    {
        return (new StorageChecks())->run(new DoctorContext(new Paths($this->tempDir(), $this->storage), $this->platform, new DoctorOptions()));
    }

    private function one(string $id): CheckResult
    {
        $results = array_values(array_filter($this->results(), static fn(CheckResult $r): bool => $r->id === $id));
        self::assertCount(1, $results, $id);

        return $results[0];
    }
}
