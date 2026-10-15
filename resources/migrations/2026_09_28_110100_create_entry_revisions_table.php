<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute(
            'CREATE TABLE entry_revisions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entry_id INT UNSIGNED NOT NULL,
                data JSON NOT NULL,
                author_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                KEY entry_revisions_entry_index (entry_id, id),
                CONSTRAINT entry_revisions_entry_fk FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE CASCADE,
                CONSTRAINT entry_revisions_author_fk FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
