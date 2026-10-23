<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Database\Migrator;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\DemoSnapshot;

/**
 * The test support that seeds the demo content once per process: a restored snapshot is the seeded site, table by
 * table (AUTO_INCREMENT counters included) and file by file.
 */
final class DemoSnapshotTest extends SkeletonTestCase
{
    public function testARestoreRecreatesTheSeededSiteExactly(): void
    {
        $this->seedDemo();
        $state = DemoSnapshot::state($this->db);
        $storage = $this->app->container()->service(Paths::class)->storageRoot;
        $files = DemoSnapshot::hashes($storage);
        $snapshot = DemoSnapshot::take($this->db, $storage);

        $this->reset($storage);
        self::assertNotSame($state, DemoSnapshot::state($this->db));
        $snapshot->restore($this->db, $storage);

        self::assertSame($state, DemoSnapshot::state($this->db));
        self::assertSame($files, DemoSnapshot::hashes($storage));
        self::assertSame(200, $this->browser->get('/news')->getStatusCode());
    }

    public function testASecondSeedOfTheSameStateMakesTheSameSite(): void
    {
        $storage = $this->app->container()->service(Paths::class)->storageRoot;
        $before = DemoSnapshot::hashes($storage);
        $this->seedDemo();
        $state = DemoSnapshot::state($this->db);
        $files = DemoSnapshot::hashes($storage);
        self::assertGreaterThan(30, count($files) - count($before), 'the pictures, their variants and the share images');

        $this->reset($storage);
        $this->seedDemo();

        self::assertSame($state, DemoSnapshot::state($this->db));
        self::assertSame($files, DemoSnapshot::hashes($storage));
    }

    /**
     * Back to the state of a new test: freshly migrated tables and empty storage directories.
     */
    private function reset(string $storage): void
    {
        $this->dropAllTables();
        $this->app->container()->service(Migrator::class)->migrate();
        foreach (array_keys(DemoSnapshot::hashes($storage)) as $file) {
            unlink($storage . '/' . $file);
        }
    }
}
