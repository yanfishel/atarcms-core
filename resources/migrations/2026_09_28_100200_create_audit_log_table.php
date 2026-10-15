<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute(
            'CREATE TABLE audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                action VARCHAR(64) NOT NULL,
                subject_type VARCHAR(64) NULL,
                subject_id VARCHAR(64) NULL,
                meta JSON NOT NULL,
                ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL,
                KEY audit_log_created_at_index (created_at),
                KEY audit_log_user_id_index (user_id),
                CONSTRAINT audit_log_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
