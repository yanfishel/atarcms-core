<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute(
            'CREATE TABLE login_links (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY login_links_token_hash_unique (token_hash),
                KEY login_links_expires_at_index (expires_at),
                CONSTRAINT login_links_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
