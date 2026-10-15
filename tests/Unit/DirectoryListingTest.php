<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit, PHPStan and PHP-CS-Fixer find files through PHP's directory iterators. On some shared folders (Docker
 * Desktop on Windows) FilesystemIterator returns only part of a directory once its listing grows past a size while
 * readdir() still sees every entry: whole test directories then vanish from the suite without a word. This test
 * makes that loud: every directory of the sources and the tests must list the same entries both ways.
 */
final class DirectoryListingTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    public function testTheIteratorsSeeEveryFileOfTheSourcesAndTheTests(): void
    {
        $short = [];
        foreach (['src', 'tests/Unit', 'tests/Integration', 'tests/Functional', 'tests/Support'] as $top) {
            foreach (self::directories(self::ROOT . '/' . $top) as $directory) {
                $listed = count(array_diff(scandir($directory) ?: [], ['.', '..']));
                $iterated = iterator_count(new FilesystemIterator($directory));
                if ($iterated !== $listed) {
                    $short[] = sprintf('%s: %d of %d', substr($directory, strlen(self::ROOT) + 1), $iterated, $listed);
                }
            }
        }

        self::assertSame([], $short, 'The directory iterator misses files here, so tests or checks would silently skip them. Run the checks where the repository is a native file system (e.g. inside WSL), or split the directory.');
    }

    /**
     * Every directory under $root, found with scandir() (which sees every entry).
     *
     * @return list<string>
     */
    private static function directories(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $found = [$root];
        foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
            if (is_dir($root . '/' . $entry)) {
                $found = [...$found, ...self::directories($root . '/' . $entry)];
            }
        }

        return $found;
    }
}
