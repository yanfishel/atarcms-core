<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;

/**
 * Boots the skeleton site (its config and modules) without a request and without touching the database, with a
 * temporary storage directory. The process environment wins over skeleton/.env, so the site runs as production at
 * https://localhost whatever the developer's .env says. Call restoreEnvironment() in tearDown().
 */
trait SkeletonSite
{
    use TempDirectory;

    private const string SKELETON = __DIR__ . '/../../skeleton';

    private const array SKELETON_ENV = [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://localhost',
        'APP_THEME' => 'default',
        'MAIL_DSN' => '',
        'MAIL_FROM' => '',
    ];

    /**
     * @param array<string, mixed> $overrides
     */
    private function bootSkeleton(array $overrides = []): Application
    {
        foreach (self::SKELETON_ENV as $name => $value) {
            putenv($name . '=' . $value);
        }

        return Application::boot(new Paths(self::SKELETON, $this->skeletonStorage()), $overrides);
    }

    private function skeletonStorage(): string
    {
        $storage = $this->tempDir() . '/storage';
        foreach (['cache', 'logs', 'tmp', 'uploads', 'avatars'] as $directory) {
            if (!is_dir($storage . '/' . $directory)) {
                mkdir($storage . '/' . $directory, 0777, true);
            }
        }

        return $storage;
    }

    private function restoreEnvironment(): void
    {
        foreach (array_keys(self::SKELETON_ENV) as $name) {
            putenv($name);
        }
    }
}
