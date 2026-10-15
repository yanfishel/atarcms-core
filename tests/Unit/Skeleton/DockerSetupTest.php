<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Skeleton;

use PHPUnit\Framework\TestCase;

/**
 * The skeleton's Docker setups: development (compose.yaml) and a server (compose.production.yaml, selected with
 * COMPOSE_FILE in .env). One user runs the site's PHP: the image moves www-data to the host's uid and gid.
 */
final class DockerSetupTest extends TestCase
{
    private const string SKELETON = __DIR__ . '/../../../skeleton';

    public function testTheImageRunsPhpAsTheHostUser(): void
    {
        $dockerfile = (string) file_get_contents(self::SKELETON . '/docker/php/Dockerfile');

        self::assertStringContainsString('ARG HOST_UID=82', $dockerfile);
        self::assertStringContainsString('ARG HOST_GID=82', $dockerfile);
        self::assertStringContainsString('usermod', $dockerfile);
        self::assertStringContainsString('COMPOSER_HOME=/tmp/composer', $dockerfile);

        foreach (['compose.yaml', 'compose.production.yaml'] as $file) {
            $compose = (string) file_get_contents(self::SKELETON . '/' . $file);
            self::assertStringContainsString('HOST_UID: ${HOST_UID:-82}', $compose, $file);
            self::assertStringContainsString('HOST_GID: ${HOST_GID:-82}', $compose, $file);
        }
        $env = (string) file_get_contents(self::SKELETON . '/.env.example');
        self::assertStringContainsString("HOST_UID=\n", $env);
        self::assertStringContainsString("HOST_GID=\n", $env);
    }

    public function testProductionRunsCaddyWithoutMailpitAndRestartsEverything(): void
    {
        $compose = (string) file_get_contents(self::SKELETON . '/compose.production.yaml');

        self::assertStringNotContainsString('mailpit', $compose);
        self::assertStringContainsString('ATAR_PRODUCTION: "1"', $compose);
        self::assertStringContainsString('image: caddy:2', $compose);
        self::assertStringContainsString('./deploy/caddy/Caddyfile:/etc/caddy/Caddyfile:ro', $compose);
        self::assertStringContainsString('SITE_ADDRESS: ${APP_URL}', $compose);
        self::assertSame(3, substr_count($compose, 'restart: unless-stopped'));
        self::assertSame(3, substr_count($compose, 'healthcheck:'));
        self::assertStringContainsString('MARIADB_RANDOM_ROOT_PASSWORD: "1"', $compose);
        self::assertStringNotContainsString('3306:', $compose, 'the database is not published');
    }
}
