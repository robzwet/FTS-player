-- ═══════════════════════════════════════════════
--  Video Queue — Database initialisation
--  Runs automatically on first MySQL/MariaDB start.
-- ═══════════════════════════════════════════════

USE videoqueue;

CREATE TABLE IF NOT EXISTS `queue` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `video_id`   VARCHAR(11)  NOT NULL,
    `title`      VARCHAR(200) NOT NULL DEFAULT '',
    `channel`    VARCHAR(100) NOT NULL DEFAULT '',
    `duration`   VARCHAR(20)  NOT NULL DEFAULT '',
    `added_by`   VARCHAR(64)  NOT NULL DEFAULT '',
    `added_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `position`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_video_id (`video_id`),
    INDEX idx_position (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
    `key`        VARCHAR(60)  NOT NULL PRIMARY KEY,
    `value`      TEXT         NOT NULL,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username`      VARCHAR(40)  NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('ticker', '');
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('projector_command', '');
