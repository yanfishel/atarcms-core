<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

final class SkeletonMigrationsTest extends TestCase
{
    public function testSkeletonMigrationsSortAfterEveryCoreMigration(): void
    {
        $root = dirname(__DIR__, 3);
        $core = self::ids($root . '/resources/migrations');
        $skeleton = self::ids($root . '/skeleton/database/migrations');
        if ($core === []) {
            self::fail('the core has migrations');
        }
        self::assertDirectoryExists($root . '/skeleton/database/migrations', 'collections:sync writes there');

        $latestCore = max($core);
        foreach ($skeleton as $id) {
            self::assertGreaterThan(0, strcmp($id, $latestCore), sprintf('%s must sort after %s', $id, $latestCore));
        }
    }

    /**
     * @return list<string>
     */
    private static function ids(string $directory): array
    {
        return array_map(static fn(string $file): string => basename($file, '.php'), glob($directory . '/*.php') ?: []);
    }
}
