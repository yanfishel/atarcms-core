<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute(
            'CREATE TABLE entries (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                collection VARCHAR(32) NOT NULL,
                locale VARCHAR(12) NOT NULL,
                translation_group CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                slug VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                title VARCHAR(255) NOT NULL,
                status VARCHAR(16) NOT NULL,
                published_at DATETIME NULL,
                parent_id INT UNSIGNED NULL,
                position INT NOT NULL DEFAULT 0,
                author_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(500) NULL,
                share_image VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                preset VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
                data JSON NOT NULL,
                UNIQUE KEY entries_collection_locale_slug_unique (collection, locale, slug),
                UNIQUE KEY entries_collection_locale_preset_unique (collection, locale, preset),
                UNIQUE KEY entries_group_locale_unique (translation_group, locale),
                KEY entries_listing_index (collection, locale, status, published_at),
                KEY entries_parent_index (parent_id, position),
                CONSTRAINT entries_parent_fk FOREIGN KEY (parent_id) REFERENCES entries (id) ON DELETE RESTRICT,
                CONSTRAINT entries_author_fk FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
