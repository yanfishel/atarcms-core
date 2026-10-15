<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

/**
 * Media folders, media files, the files each entry references (fields and rich-text links), and the SEO image's foreign key.
 * Both references use ON DELETE RESTRICT: the database refuses to delete a file that an entry uses.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        // Folders exist only here: a file's disk path never changes when it moves. parent_key makes the root's
        // children (parent_id NULL) unique by name too; the unicode_ci collation compares names ignoring case and
        // accents. ON DELETE RESTRICT keeps a folder with subfolders or files.
        $db->execute(
            'CREATE TABLE media_folders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                parent_id INT UNSIGNED NULL,
                parent_key INT UNSIGNED AS (COALESCE(parent_id, 0)) STORED,
                name VARCHAR(100) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY media_folders_name_unique (parent_key, name),
                KEY media_folders_parent_index (parent_id),
                CONSTRAINT media_folders_parent_fk FOREIGN KEY (parent_id) REFERENCES media_folders (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->execute(
            'CREATE TABLE media (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                path VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                size INT UNSIGNED NOT NULL,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                alt JSON NOT NULL,
                uploaded_by INT UNSIGNED NULL,
                folder_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY media_path_unique (path),
                KEY media_folder_index (folder_id),
                CONSTRAINT media_folder_fk FOREIGN KEY (folder_id) REFERENCES media_folders (id) ON DELETE RESTRICT,
                CONSTRAINT media_uploaded_by_fk FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->execute(
            'CREATE TABLE entry_media (
                entry_id INT UNSIGNED NOT NULL,
                field VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                media_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (entry_id, field, media_id),
                KEY entry_media_media_index (media_id),
                CONSTRAINT entry_media_entry_fk FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE CASCADE,
                CONSTRAINT entry_media_media_fk FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
