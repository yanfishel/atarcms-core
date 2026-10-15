<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // Messages of the site's forms (form: "contact", or a key of config/forms.php). The reserved fields have their
        // own columns (null when the form has none or it was left empty); fields holds the other fields as a JSON
        // object in the form's order. ip_hash is an HMAC of the sender's address with the site key (AppKey): it lets
        // the rate limit, shared by all forms, count a sender's messages without storing the address.
        $db->execute(
            'CREATE TABLE messages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                form VARCHAR(40) NOT NULL,
                locale VARCHAR(12) NOT NULL,
                name VARCHAR(100) NULL,
                email VARCHAR(200) NULL,
                phone VARCHAR(50) NULL,
                body TEXT NULL,
                fields JSON NULL,
                page_url VARCHAR(500) NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                read_at DATETIME NULL,
                mailed_at DATETIME NULL,
                KEY messages_created_at_index (created_at),
                KEY messages_form_index (form, created_at),
                KEY messages_ip_hash_index (ip_hash, created_at),
                KEY messages_read_at_index (read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
