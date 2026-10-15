<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * The public mirror (yanfishel/atarcms-core): the code is MIT-licensed, and the release export publishes the tag's
 * tree without the private working files. Topology: docs/releasing.md ("Repositories").
 */
final class PublicMirrorTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheCodeIsMitLicensed(): void
    {
        $licence = (string) file_get_contents(self::ROOT . '/LICENSE');

        self::assertStringContainsString('MIT License', $licence);
        self::assertStringContainsString('Copyright (c) 2026 Yan Fishel', $licence);
        self::assertStringContainsString('THE SOFTWARE IS PROVIDED "AS IS"', $licence);

        $core = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true);
        $skeleton = json_decode((string) file_get_contents(self::ROOT . '/skeleton/composer.json'), true);
        self::assertIsArray($core);
        self::assertIsArray($skeleton);
        self::assertSame('MIT', $core['license'] ?? null);
        self::assertSame('MIT', $skeleton['license'] ?? null);

        // The skeleton is exported to its own repository (yanfishel/atarcms), so it carries its own copy.
        self::assertSame($licence, (string) file_get_contents(self::ROOT . '/skeleton/LICENSE'));
    }

    public function testTheSecurityPolicyAndContributionGuideShip(): void
    {
        self::assertStringContainsString('security/advisories', (string) file_get_contents(self::ROOT . '/SECURITY.md'));
        self::assertStringContainsString('mirror', (string) file_get_contents(self::ROOT . '/CONTRIBUTING.md'));

        // The skeleton repository (yanfishel/atarcms) is the storefront: its own SECURITY.md points reports at the core's.
        self::assertStringContainsString('atarcms-core/security/advisories', (string) file_get_contents(self::ROOT . '/skeleton/SECURITY.md'));
    }

    public function testTheCoreExportLeavesOutThePrivateWorkingFiles(): void
    {
        self::assertStringContainsString('set -euo pipefail', self::exportScript());
        self::assertStringContainsString('exclude=(CLAUDE.md docs/superpowers .github .claude)', self::exportScript());
    }

    public function testEveryExportIgnoredEntryIsClassifiedForTheMirror(): void
    {
        // export-ignore trims the Composer dist; the mirror instead publishes everything except export-core.sh's
        // exclusions. So every export-ignored entry is either deliberately public on the mirror (the dev files
        // people need to run the checks) or in the script's exclude list — a new private working file goes there.
        $public = ['tests', 'skeleton', 'docs', 'docker', 'compose.yaml', '.editorconfig', '.gitattributes',
            '.gitignore', '.php-cs-fixer.dist.php', 'phpstan.neon.dist', 'phpunit.xml.dist', 'composer.lock'];

        self::assertSame(1, preg_match('/^exclude=\(([^)]*)\)$/m', self::exportScript(), $m));
        $excluded = explode(' ', $m[1] ?? '');
        self::assertContains('docs/superpowers', $excluded);

        $lines = file(self::ROOT . '/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('#^/(\S+) export-ignore$#', $line, $match) === 1) {
                self::assertTrue(
                    in_array($match[1], $public, true) || in_array($match[1], $excluded, true),
                    $match[1] . " is export-ignored: publish it on the mirror on purpose, or add it to export-core.sh's exclude list",
                );
            }
        }
    }

    public function testTheReleaseWorkflowExportsTheCoreAfterTheChecks(): void
    {
        if (!is_dir(self::ROOT . '/.github')) {
            self::markTestSkipped('The public mirror ships without .github (export-core.sh).');
        }

        $workflow = (string) file_get_contents(self::ROOT . '/.github/workflows/release.yml');

        self::assertStringContainsString('export-core:', $workflow);
        self::assertStringContainsString('secrets.CORE_REPO_DEPLOY_KEY', $workflow);
        self::assertStringContainsString('.github/scripts/export-core.sh', $workflow);
        self::assertStringNotContainsString('--force', $workflow);
    }

    /** The script's text, or a skip on the mirror itself, which ships without .github. */
    private static function exportScript(): string
    {
        $path = self::ROOT . '/.github/scripts/export-core.sh';
        if (!is_file($path)) {
            self::markTestSkipped('The public mirror ships without .github (export-core.sh).');
        }

        return (string) file_get_contents($path);
    }
}
