<?php

declare(strict_types=1);

use AtarCms\Kernel\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = Application::fromSite(dirname(__DIR__));
} catch (Throwable $e) {
    error_log('AtarCMS boot failed: ' . $e);
    http_response_code(500);
    echo 'Server Error';

    return;
}

$app->run();
