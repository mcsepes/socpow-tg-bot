-- Создание таблицы пользователей
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL UNIQUE,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Создание таблицы рассылок
CREATE TABLE IF NOT EXISTS `broadcasts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id` BIGINT NOT NULL,
    `text` TEXT NULL,
    `status` ENUM('pending_text', 'sending', 'processing', 'completed') NOT NULL DEFAULT 'pending_text',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Создание таблицы попыток рассылки
CREATE TABLE IF NOT EXISTS `broadcast_attempts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `broadcast_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `last_error` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL,
    FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX (`broadcast_id`),
    INDEX (`user_id`),
    UNIQUE KEY `broadcast_user` (`broadcast_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
