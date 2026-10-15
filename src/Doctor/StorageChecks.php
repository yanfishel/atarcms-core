<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * storage/ under the one-user rule: one user owns the site and runs PHP-FPM, composer and bin/atar. A file made by
 * root (sudo php bin/atar, docker compose exec without -u) is the classic cause of 500 errors: the site key or an image
 * cache the web server cannot read.
 */
final class StorageChecks implements Check
{
    /** The directories the site writes; missing ones are created on first use. */
    public const array DIRECTORIES = ['logs', 'cache', 'cache/media', 'uploads', 'tmp', 'avatars'];

    /** How many entries of storage/ the owner check looks at, at most. */
    private const int LIMIT = 5000;

    public function run(DoctorContext $context): array
    {
        return [$this->writable($context), $this->owner($context), $this->appKey($context)];
    }

    private function writable(DoctorContext $context): CheckResult
    {
        $storage = $context->paths->storage();
        if (!is_dir($storage)) {
            return CheckResult::fail('storage.writable', 'There is no storage/ directory', 'mkdir storage, owned by the site user');
        }
        $blocked = [];
        foreach (['', ...self::DIRECTORIES] as $directory) {
            $path = $directory === '' ? $storage : $storage . '/' . $directory;
            if (is_dir($path) && !$context->platform->isWritable($path)) {
                $blocked[] = $directory === '' ? 'storage' : 'storage/' . $directory;
            }
        }
        if ($blocked !== []) {
            return CheckResult::fail('storage.writable', 'Not writable for ' . $this->who($context) . ': ' . implode(', ', $blocked), $this->chown($context));
        }

        return CheckResult::ok('storage.writable', 'storage/ is writable');
    }

    private function owner(DoctorContext $context): CheckResult
    {
        $platform = $context->platform;
        $storage = $context->paths->storage();
        $uid = $platform->effectiveUid();
        $owner = $platform->fileOwner($storage);
        if ($uid === null || $owner === null) {
            return CheckResult::skip('storage.owner', 'skipped: file owners are not available on this system');
        }
        $ownerName = $this->name($context, $owner);
        $foreign = [];
        $count = 0;
        try {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($entries as $entry) {
                if (!$entry instanceof SplFileInfo || ++$count > self::LIMIT) {
                    break;
                }
                $entryOwner = $platform->fileOwner($entry->getPathname());
                if ($entryOwner !== null && $entryOwner !== $owner) {
                    $foreign[$entry->getPathname()] = $entryOwner;
                }
            }
        } catch (UnexpectedValueException) {
            return CheckResult::fail('storage.owner', 'storage/ holds a directory ' . $this->who($context) . ' cannot read', $this->chown($context));
        }

        if ($owner === 0) {
            return $uid === 0 || $platform->isWritable($storage)
                ? CheckResult::warn('storage.owner', 'storage/ belongs to root. Normal on Docker Desktop (Windows, macOS); on a Linux server it should belong to the site user', 'Linux: chown -R <site user>: storage, then run bin/atar as that user')
                : CheckResult::fail('storage.owner', 'storage/ belongs to root and ' . $this->who($context) . ' cannot write it', 'chown -R <site user>: storage');
        }
        if ($foreign !== []) {
            $owners = array_values(array_unique(array_map(fn(int $id): string => $this->name($context, $id), $foreign)));
            $shown = array_map(fn(string $path): string => $this->relative($context, $path), array_slice(array_keys($foreign), 0, 5));

            return CheckResult::fail(
                'storage.owner',
                sprintf('%d entries in storage/ belong to %s, not to %s (the owner of storage/): %s', count($foreign), implode(', ', $owners), $ownerName, implode(', ', $shown)),
                sprintf('chown -R %s: storage, and run bin/atar only as %s', $ownerName, $ownerName),
            );
        }
        if ($uid === 0) {
            return CheckResult::warn('storage.owner', 'You run bin/atar as root: files it writes into storage/ break the site', sprintf('sudo -u %s php bin/atar … (Docker: docker compose exec -u www-data php php bin/atar …)', $ownerName));
        }
        if ($uid !== $owner) {
            return CheckResult::fail('storage.owner', sprintf('You run bin/atar as %s but storage/ belongs to %s', $this->name($context, $uid), $ownerName), sprintf('sudo -u %s php bin/atar … (Docker: docker compose exec -u www-data php php bin/atar …)', $ownerName));
        }

        return CheckResult::ok('storage.owner', sprintf('storage/ and everything in it belong to %s, who runs bin/atar', $ownerName));
    }

    private function appKey(DoctorContext $context): CheckResult
    {
        $file = $context->paths->storage('app.key');
        if (!is_file($file)) {
            return CheckResult::ok('storage.app_key', 'storage/app.key is made on first use');
        }
        try {
            $key = @file_get_contents($file);
        } catch (Throwable) {
            $key = false;
        }
        if ($key === false) {
            return CheckResult::fail('storage.app_key', 'storage/app.key cannot be read by ' . $this->who($context), $this->chown($context));
        }
        if (preg_match('/^[0-9a-f]{64}$/D', trim($key)) !== 1) {
            return CheckResult::fail('storage.app_key', 'storage/app.key does not hold a key (64 hexadecimal characters)', 'delete storage/app.key: a new one is made (open contact forms must be sent again)');
        }
        $platform = $context->platform;
        $owner = $platform->fileOwner($context->paths->storage());
        $keyOwner = $platform->fileOwner($file);
        if ($owner !== null && $owner !== 0 && $keyOwner !== null && $keyOwner !== $owner) {
            return CheckResult::fail('storage.app_key', sprintf('storage/app.key belongs to %s, not to %s: the site cannot read it', $this->name($context, $keyOwner), $this->name($context, $owner)), $this->chown($context));
        }
        $mode = $platform->fileMode($file);
        if ($mode !== null && ($mode & 0o077) !== 0 && $mode !== 0o777) {
            return CheckResult::warn('storage.app_key', sprintf('storage/app.key is readable by other users (mode %o)', $mode), 'chmod 600 storage/app.key');
        }

        return CheckResult::ok('storage.app_key', 'storage/app.key is a valid key');
    }

    private function who(DoctorContext $context): string
    {
        $uid = $context->platform->effectiveUid();

        return $uid === null ? 'this user' : $this->name($context, $uid);
    }

    private function chown(DoctorContext $context): string
    {
        $owner = $context->platform->fileOwner($context->paths->storage());
        $name = $owner === null || $owner === 0 ? '<site user>' : $this->name($context, $owner);

        return sprintf('chown -R %s: storage (the user that runs PHP-FPM and bin/atar; Docker: docker compose exec php chown -R www-data: storage)', $name);
    }

    private function name(DoctorContext $context, int $uid): string
    {
        return $context->platform->userName($uid) ?? 'uid ' . $uid;
    }

    private function relative(DoctorContext $context, string $path): string
    {
        $storage = $context->paths->storage();

        return 'storage' . substr(str_replace('\\', '/', $path), strlen($storage));
    }
}
