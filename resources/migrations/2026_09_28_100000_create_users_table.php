<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute(
            "CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(254) NOT NULL,
                name VARCHAR(190) NOT NULL DEFAULT '',
                role VARCHAR(64) NOT NULL,
                google_sub VARCHAR(255) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                locale VARCHAR(12) NULL,
                content_locale VARCHAR(12) NULL,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                avatar_version VARCHAR(16) NULL,
                accent VARCHAR(16) NULL,
                UNIQUE KEY users_email_unique (email),
                UNIQUE KEY users_google_sub_unique (google_sub),
                CONSTRAINT users_status_check CHECK (status IN ('active', 'disabled'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
