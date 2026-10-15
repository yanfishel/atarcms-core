<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\After;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

trait TempDirectory
{
    private ?string $tempDirectory = null;

    protected function tempDir(): string
    {
        if ($this->tempDirectory === null) {
            $this->tempDirectory = sys_get_temp_dir() . '/atar-test-' . bin2hex(random_bytes(6));
            mkdir($this->tempDirectory, 0777, true);
        }

        return $this->tempDirectory;
    }

    protected function writeFile(string $relative, string $contents): string
    {
        $file = $this->tempDir() . '/' . $relative;
        $directory = dirname($file);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($file, $contents);

        return $file;
    }

    #[After]
    protected function removeTempDir(): void
    {
        if ($this->tempDirectory === null) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tempDirectory);
        $this->tempDirectory = null;
    }
}
