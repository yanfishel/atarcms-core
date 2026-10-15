<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * What the core package ships: GitHub's dist archives (Composer's download with a token) honour export-ignore, so
 * every top-level entry of the repository is either shipped to sites or export-ignored. The release export of the
 * skeleton (.github/scripts/export-skeleton.sh) leaves out the monorepo's dev override files the same way.
 */
final class PackageExportTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** What an installed site needs from the core. */
    private const array SHIPPED = ['src', 'resources', 'composer.json', 'README.md', 'CHANGELOG.md', 'UPGRADE.md', 'LICENSE', 'SECURITY.md', 'CONTRIBUTING.md'];

    /** Never tracked (ignored or local tooling), so never in an archive. */
    private const array LOCAL = ['.git', 'vendor', '.phpunit.cache', '.phpstan.cache', '.php-cs-fixer.cache', '.idea', '.superpowers', '.agents', 'skills-lock.json'];

    public function testEveryTopLevelEntryIsShippedOrExportIgnored(): void
    {
        $ignored = self::exportIgnored(self::ROOT . '/.gitattributes');
        $entries = array_values(array_diff(scandir(self::ROOT) ?: [], ['.', '..'], self::LOCAL));

        foreach ($entries as $entry) {
            if (in_array($entry, self::SHIPPED, true)) {
                self::assertNotContains($entry, $ignored, $entry . ' is shipped to sites');
            } else {
                self::assertContains($entry, $ignored, 'export-ignore /' . $entry . ' in .gitattributes, or ship it on purpose');
            }
        }
    }

    public function testTheSkeletonExportLeavesOutTheMonorepoDevFiles(): void
    {
        $ignored = self::exportIgnored(self::ROOT . '/skeleton/.gitattributes');

        self::assertContains('compose.override.yaml', $ignored);
        self::assertContains('composer.dev.json', $ignored);
    }

    public function testTheReleaseWorkflowExportsTheSkeletonAfterTheChecks(): void
    {
        if (!is_dir(self::ROOT . '/.github')) {
            self::markTestSkipped('The public mirror ships without .github (export-core.sh).');
        }

        $workflow = (string) file_get_contents(self::ROOT . '/.github/workflows/release.yml');

        self::assertStringContainsString("tags: ['v*.*.*']", $workflow);
        self::assertStringContainsString('uses: ./.github/workflows/ci.yml', $workflow);
        self::assertStringContainsString('needs: checks', $workflow);
        self::assertStringContainsString('secrets.SITE_REPO_DEPLOY_KEY', $workflow);
        self::assertStringNotContainsString('--force', $workflow);
        self::assertStringContainsString('workflow_call:', (string) file_get_contents(self::ROOT . '/.github/workflows/ci.yml'));
        self::assertFileExists(self::ROOT . '/.github/scripts/export-skeleton.sh');
    }

    /**
     * @return list<string> the top-level names marked export-ignore
     */
    private static function exportIgnored(string $file): array
    {
        $names = [];
        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            if (preg_match('~^/?([^\s/*]+)/?\s+export-ignore\s*$~', trim($line), $match) === 1) {
                $names[] = $match[1];
            }
        }

        return $names;
    }
}
