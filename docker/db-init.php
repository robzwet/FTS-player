<?php
// ═══════════════════════════════════════════════
//  Video Queue — CLI database initialiser
//  Runs at container startup via entrypoint.sh
//  Creates tables if they don't exist yet.
//  Safe to run multiple times.
// ═══════════════════════════════════════════════
 
require_once '/var/www/html/config.php';
 
try {
    // Connect without selecting a database first so we can create it
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
 
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
 
    echo "[db-init] Connected to MySQL, using database `" . DB_NAME . "`\n";
 
    // Create tables
    $tables = [
 
        "CREATE TABLE IF NOT EXISTS `queue` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `video_id`   VARCHAR(11)  NOT NULL,
            `title`      VARCHAR(200) NOT NULL DEFAULT '',
            `channel`    VARCHAR(100) NOT NULL DEFAULT '',
            `duration`   VARCHAR(20)  NOT NULL DEFAULT '',
            `added_by`   VARCHAR(64)  NOT NULL DEFAULT '',
            `session_token` VARCHAR(64) NOT NULL DEFAULT '',
            `added_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `position`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            INDEX idx_video_id (`video_id`),
            INDEX idx_position (`position`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 
        "CREATE TABLE IF NOT EXISTS `history` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `video_id`   VARCHAR(11)  NOT NULL,
            `title`      VARCHAR(200) NOT NULL DEFAULT '',
            `channel`    VARCHAR(100) NOT NULL DEFAULT '',
            `duration`   VARCHAR(20)  NOT NULL DEFAULT '',
            `added_by`   VARCHAR(64)  NOT NULL DEFAULT '',
            `added_at`   DATETIME     NOT NULL,
            `played_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_video_id (`video_id`),
            INDEX idx_played_at (`played_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 
        "CREATE TABLE IF NOT EXISTS `settings` (
            `key`        VARCHAR(60)  NOT NULL PRIMARY KEY,
            `value`      TEXT         NOT NULL,
            `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 
        "CREATE TABLE IF NOT EXISTS `admins` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `username`      VARCHAR(40)  NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
 
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    echo "[db-init] Tables ready\n";
 
    // Seed default settings
    $pdo->exec("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('ticker', '')");
    $pdo->exec("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('projector_command', '')");
    echo "[db-init] Settings seeded\n";
 
    // Add session_token column if it doesn't exist yet (migration for existing installs)
    $cols = $pdo->query("SHOW COLUMNS FROM `queue` LIKE 'session_token'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `queue` ADD COLUMN `session_token` VARCHAR(64) NOT NULL DEFAULT '' AFTER `added_by`");
        echo "[db-init] Added session_token column to queue table\n";
    }
 
    // Fix added_by column width if it was created as VARCHAR(45)
    foreach (['queue', 'history'] as $table) {
        $col = $pdo->query("
            SELECT CHARACTER_MAXIMUM_LENGTH
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '" . DB_NAME . "'
            AND TABLE_NAME = '{$table}'
            AND COLUMN_NAME = 'added_by'
        ")->fetchColumn();
 
        if ($col && (int)$col < 64) {
            $pdo->exec("ALTER TABLE `{$table}` MODIFY `added_by` VARCHAR(64) NOT NULL DEFAULT ''");
            echo "[db-init] Fixed `{$table}`.added_by column width (was {$col}, now 64)\n";
        }
    }
 
    echo "[db-init] Done.\n";
 
} catch (PDOException $e) {
    echo "[db-init] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
 