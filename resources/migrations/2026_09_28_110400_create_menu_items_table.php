<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute(
            'CREATE TABLE menu_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                location VARCHAR(32) NOT NULL,
                locale VARCHAR(12) NOT NULL,
                parent_id INT UNSIGNED NULL,
                position INT NOT NULL DEFAULT 0,
                label VARCHAR(200) NOT NULL DEFAULT \'\',
                target_type VARCHAR(8) NOT NULL,
                entry_id INT UNSIGNED NULL,
                term_id INT UNSIGNED NULL,
                url VARCHAR(2048) NULL,
                new_tab TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY menu_items_tree_index (location, locale, parent_id, position),
                CONSTRAINT menu_items_parent_fk FOREIGN KEY (parent_id) REFERENCES menu_items (id) ON DELETE CASCADE,
                CONSTRAINT menu_items_entry_fk FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE SET NULL,
                CONSTRAINT menu_items_term_fk FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
