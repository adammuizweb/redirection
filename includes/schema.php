<?php
declare(strict_types=1);

function jyr_schema_is_ready(): bool
{
    return ($GLOBALS['jyr_schema_ready'] ?? false) === true;
}

function jyr_ensure_schema(PDO $pdo): bool
{
    if (jyr_schema_is_ready()) return true;

    try {
        $stored = function_exists('settings_get')
            ? (string)(settings_get($pdo, 'jyr_schema_version', '') ?? '')
            : '';

        $tablesReady = false;
        if ($stored === JYR_SCHEMA_VERSION) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)'
            );
            $stmt->execute([JYR_RULES_TABLE, JYR_LOGS_TABLE]);
            $tablesReady = (int)$stmt->fetchColumn() === 2;
            if ($tablesReady) {
                $columns = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND ((TABLE_NAME = ? AND COLUMN_NAME = ?) OR (TABLE_NAME = ? AND COLUMN_NAME = ?))'
                );
                $columns->execute([JYR_RULES_TABLE, 'source_hash', JYR_LOGS_TABLE, 'path_hash']);
                $tablesReady = (int)$columns->fetchColumn() === 2;
            }
        }

        if (!$tablesReady) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `' . JYR_RULES_TABLE . '` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `source_path` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                    `source_hash` BINARY(32) NOT NULL,
                    `target_url` VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `hit_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `last_hit_at` DATETIME DEFAULT NULL,
                    `created_by` INT UNSIGNED DEFAULT NULL,
                    `updated_by` INT UNSIGNED DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_jyr_redirects_source_hash` (`source_hash`),
                    KEY `idx_jyr_redirects_enabled_hash` (`enabled`, `source_hash`),
                    KEY `idx_jyr_redirects_updated` (`updated_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `' . JYR_LOGS_TABLE . '` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `path` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                    `path_hash` BINARY(32) NOT NULL,
                    `hit_count` BIGINT UNSIGNED NOT NULL DEFAULT 1,
                    `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_jyr_404_paths_hash` (`path_hash`),
                    KEY `idx_jyr_404_paths_last_seen` (`last_seen_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $columns = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND ((TABLE_NAME = ? AND COLUMN_NAME = ?) OR (TABLE_NAME = ? AND COLUMN_NAME = ?))'
            );
            $columns->execute([JYR_RULES_TABLE, 'source_hash', JYR_LOGS_TABLE, 'path_hash']);
            if ((int)$columns->fetchColumn() !== 2) throw new RuntimeException('Redirection schema verification failed.');
            if (function_exists('settings_set') && !settings_set($pdo, 'jyr_schema_version', JYR_SCHEMA_VERSION, 1)) {
                throw new RuntimeException('Unable to persist the Redirection schema version.');
            }
        }

        $GLOBALS['jyr_schema_ready'] = true;
        return true;
    } catch (Throwable $e) {
        $GLOBALS['jyr_schema_ready'] = false;
        error_log('[redirection] schema error: ' . $e->getMessage());
        return false;
    }
}

function jyr_drop_schema(PDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS `' . JYR_LOGS_TABLE . '`');
    $pdo->exec('DROP TABLE IF EXISTS `' . JYR_RULES_TABLE . '`');
    $pdo->prepare("DELETE FROM settings WHERE `key` = ?")->execute(['jyr_schema_version']);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)'
    );
    $stmt->execute([JYR_RULES_TABLE, JYR_LOGS_TABLE]);
    if ((int)$stmt->fetchColumn() !== 0) throw new RuntimeException('Redirection data removal could not be verified.');
    $GLOBALS['jyr_schema_ready'] = false;
}
