<?php

declare(strict_types=1);

use AtarCms\Kernel\AppKey;
use AtarCms\Tests\Support\WorkerDatabase;

require __DIR__ . '/../vendor/autoload.php';

// ParaTest numbers its worker processes in TEST_TOKEN: each one gets its own database.
$worker = getenv('TEST_TOKEN');
if (is_string($worker) && ctype_digit($worker)) {
    WorkerDatabase::use((int) $worker);

    // The fixture site's storage is shared by the workers: its key is made once, before any test, so that no two
    // first uses each write one and a test signs with the key another worker replaces.
    $storage = __DIR__ . '/Fixtures/site/storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0o777, true);
    }
    $lock = fopen($storage . '/app.key.lock', 'c');
    if ($lock !== false && flock($lock, LOCK_EX)) {
        new AppKey($storage . '/app.key')->value();
        flock($lock, LOCK_UN);
    }
}
