<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Functional\FunctionalTestCase;
use AtarCms\Tests\Support\DemoSnapshot;
use AtarCms\Tests\Support\TempDirectory;

/**
 * Full-stack tests against the skeleton site (skeleton/: its config, SiteModule and default theme) with the test
 * database and a temporary storage directory. The process environment wins over skeleton/.env, so the database is the
 * test one and the site runs as production at https://localhost whatever the developer's .env says.
 */
abstract class SkeletonTestCase extends FunctionalTestCase
{
    use TempDirectory;

    protected const string SITE = __DIR__ . '/../../../skeleton';

    private const array ENV = [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://localhost',
        'APP_THEME' => 'default',
        'MAIL_DSN' => '',
        'MAIL_FROM' => '',
    ];

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV) as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    protected function application(): Application
    {
        foreach (self::ENV as $name => $value) {
            putenv($name . '=' . $value);
        }
        $storage = $this->tempDir() . '/storage';
        foreach (['cache', 'logs', 'tmp', 'uploads', 'avatars'] as $directory) {
            if (!is_dir($storage . '/' . $directory)) {
                mkdir($storage . '/' . $directory, 0777, true);
            }
        }

        return Application::boot(new Paths(static::SITE, $storage));
    }

    /**
     * The demo content (DemoSeeder::seed(null)), seeded once per test process and restored from then on (DemoSnapshot).
     */
    protected function seedDemo(): void
    {
        DemoSnapshot::seed($this->app, $this->db);
    }
}
