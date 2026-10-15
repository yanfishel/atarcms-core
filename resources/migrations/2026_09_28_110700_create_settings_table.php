<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // A site-wide setting has the locale '' (a NULL could not take part in the primary key).
        $db->execute(
            'CREATE TABLE settings (
                name VARCHAR(64) NOT NULL,
                locale VARCHAR(12) NOT NULL DEFAULT \'\',
                value TEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (name, locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
