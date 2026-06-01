<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Install/upgrade: creates module tables. Idempotent — safe to re-run.
 *
 * @param \CI_DB_driver $db
 */
function postieri_api_install($db): void
{
    $prefix = db_prefix();

    // --- 1. API tokens ---
    if (!$db->table_exists($prefix . 'postieri_api_tokens')) {
        $db->query("CREATE TABLE `{$prefix}postieri_api_tokens` (
            `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `token_hash` VARCHAR(255) NOT NULL,
            `scopes` TEXT,
            `last_used_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `revoked_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_token_hash` (`token_hash`(64))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // --- 2. Webhook subscribers ---
    if (!$db->table_exists($prefix . 'postieri_api_webhooks')) {
        $db->query("CREATE TABLE `{$prefix}postieri_api_webhooks` (
            `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `url` VARCHAR(500) NOT NULL,
            `events` TEXT NOT NULL,
            `secret` VARCHAR(64) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT(11) UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            INDEX `idx_is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // --- 3. Webhook delivery log ---
    if (!$db->table_exists($prefix . 'postieri_api_webhook_deliveries')) {
        $db->query("CREATE TABLE `{$prefix}postieri_api_webhook_deliveries` (
            `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `webhook_id` INT(11) UNSIGNED NOT NULL,
            `event` VARCHAR(100) NOT NULL,
            `payload` LONGTEXT NOT NULL,
            `response_status` INT(11) NULL,
            `response_body` TEXT NULL,
            `attempt` INT(11) NOT NULL DEFAULT 1,
            `delivered_at` DATETIME NULL,
            `next_retry_at` DATETIME NULL,
            `failed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_webhook_id` (`webhook_id`),
            INDEX `idx_event` (`event`),
            INDEX `idx_next_retry_at` (`next_retry_at`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // --- 4. Rate limit log ---
    if (!$db->table_exists($prefix . 'postieri_api_rate_log')) {
        $db->query("CREATE TABLE `{$prefix}postieri_api_rate_log` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `token_id` INT(11) UNSIGNED NOT NULL,
            `endpoint` VARCHAR(255) NOT NULL,
            `method` VARCHAR(10) NOT NULL,
            `ip` VARCHAR(45) NOT NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_token_id_created` (`token_id`, `created_at`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }
}
