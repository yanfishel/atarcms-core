<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Skeleton;

use PHPUnit\Framework\TestCase;

/**
 * The skeleton's composer.json is what `composer create-project` gives a site: the core from its private GitHub
 * repository, this release line only, stable versions. The monorepo develops the skeleton against the working tree
 * through composer.dev.json (selected by COMPOSER in compose.override.yaml), which may differ only in where the core
 * comes from.
 */
final class SkeletonComposerTest extends TestCase
{
    private const string SKELETON = __DIR__ . '/../../../skeleton';

    public function testASiteGetsTheCoreFromItsRepositoryAtThisReleaseLine(): void
    {
        $composer = self::json('composer.json');

        self::assertSame('yanfishel/atarcms', $composer['name']);
        self::assertSame('project', $composer['type']);
        self::assertIsArray($composer['require']);
        self::assertIsString($composer['require']['yanfishel/atarcms-core']);
        self::assertMatchesRegularExpression('/^\^\d+\.\d+$/', $composer['require']['yanfishel/atarcms-core']);
        self::assertArrayNotHasKey('repositories', $composer, 'the shipped skeleton resolves the core from Packagist');
        self::assertSame('stable', $composer['minimum-stability']);
        self::assertArrayNotHasKey('prefer-stable', $composer);
        self::assertArrayNotHasKey('version', $composer);
        self::assertIsArray($composer['config']);
        self::assertIsArray($composer['config']['platform']);
        self::assertSame('8.4.1', $composer['config']['platform']['php']);
        self::assertIsArray($composer['scripts']);
        self::assertIsArray($composer['scripts']['post-create-project-cmd']);
        self::assertContainsOnlyString($composer['scripts']['post-create-project-cmd']);
        self::assertStringContainsString("copy('.env.example', '.env')", implode("\n", $composer['scripts']['post-create-project-cmd']));
    }

    public function testTheDevelopmentFileDiffersOnlyInWhereTheCoreComesFrom(): void
    {
        $shipped = self::json('composer.json');
        $dev = self::json('composer.dev.json');

        self::assertSame([['type' => 'path', 'url' => '..', 'options' => ['symlink' => true]]], $dev['repositories']);
        self::assertIsArray($dev['require']);
        self::assertSame('@dev', $dev['require']['yanfishel/atarcms-core']);
        self::assertSame('dev', $dev['minimum-stability']);
        self::assertTrue($dev['prefer-stable']);

        self::assertIsArray($shipped['require']);
        unset($shipped['repositories'], $shipped['require']['yanfishel/atarcms-core'], $shipped['minimum-stability'], $shipped['scripts']);
        unset($dev['repositories'], $dev['require']['yanfishel/atarcms-core'], $dev['minimum-stability'], $dev['prefer-stable']);
        self::assertSame($shipped, $dev);
    }

    public function testTheMonorepoOverrideSelectsTheDevelopmentFile(): void
    {
        $override = (string) file_get_contents(self::SKELETON . '/compose.override.yaml');

        self::assertStringContainsString('COMPOSER: composer.dev.json', $override);
        self::assertStringContainsString('..:/srv/atarcms', $override);
        self::assertStringNotContainsString('..', (string) file_get_contents(self::SKELETON . '/compose.yaml'), 'the shipped compose.yaml is standalone');
    }

    public function testASiteCommitsItsLockButNeverItsComposerCredentials(): void
    {
        $ignored = preg_split('/\R/', (string) file_get_contents(self::SKELETON . '/.gitignore')) ?: [];

        self::assertNotContains('/composer.lock', $ignored);
        self::assertContains('/auth.json', $ignored);
        self::assertContains('/composer.dev.lock', $ignored);
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $file): array
    {
        $data = json_decode((string) file_get_contents(self::SKELETON . '/' . $file), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
