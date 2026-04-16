<?php

/**
 * SQL Migration Configuration
 * Author: Hr Habib
 * Updated: 2026
 */

declare(strict_types=1);

// ==================== MIGRATION SETTINGS ====================

// Directory where backups will be stored
define('MIGRATION_BACKUP_DIR', __DIR__ . '/../storage/db_backups/');

// Directory where migration logs will be stored
define('MIGRATION_LOG_DIR', __DIR__ . '/../storage/logs/');

// Log filename
define('MIGRATION_LOG_FILE', MIGRATION_LOG_DIR . 'migrations.log');

// Auto-backup directory permissions
define('MIGRATION_BACKUP_DIR_PERMISSIONS', 0755);

// Auto-delete backups older than (days)
define('MIGRATION_BACKUP_RETENTION_DAYS', 30);

// Maximum file upload size (bytes) - 50MB
define('MIGRATION_MAX_FILE_SIZE', 50 * 1024 * 1024);

// Enable backup compression (requires gzip)
define('MIGRATION_COMPRESS_BACKUPS', false);

// ==================== FEATURE FLAGS ====================

// Enable automatic old backup cleanup (runs on dashboard load)
define('MIGRATION_AUTO_CLEANUP', true);

// Enable migration logging
define('MIGRATION_ENABLE_LOGGING', true);

// Enable detailed execution logs
define('MIGRATION_DETAILED_LOGS', true);

// ==================== SECURITY SETTINGS ====================

// Allowed file extensions
define('MIGRATION_ALLOWED_EXTENSIONS', ['sql']);

// Pattern for dangerous queries (add more patterns as needed)
define('MIGRATION_DANGEROUS_PATTERNS', [
    '/\bDROP\s+TABLE\b/i',
    '/\bTRUNCATE\s+TABLE\b/i',
    '/\bDELETE\s+FROM\b/i',
    '/\bDROP\s+DATABASE\b/i',
    '/\bALTER\s+TABLE\s+\w+\s+DROP\b/i',
]);

// ==================== ROLE-BASED ACCESS ====================

// Roles allowed to execute migrations
define('MIGRATION_ALLOWED_ROLES', ['admin', 'super_admin']);

// ==================== DATABASE SETTINGS ====================

// Timeout for long-running queries (seconds)
define('MIGRATION_QUERY_TIMEOUT', 300);

// Batch size for executing multiple queries
define('MIGRATION_BATCH_SIZE', 50);

// ==================== NOTIFICATION SETTINGS ====================

// Send email on successful migration
define('MIGRATION_EMAIL_ON_SUCCESS', false);

// Send email on failed migration
define('MIGRATION_EMAIL_ON_FAILURE', true);

// Admin email for migration notifications
define('MIGRATION_ADMIN_EMAIL', 'admin@example.com');

// ==================== UI SETTINGS ====================

// Number of recent backups to show on dashboard
define('MIGRATION_RECENT_BACKUPS_COUNT', 5);

// Number of lines to show in preview
define('MIGRATION_QUERY_PREVIEW_LENGTH', 100);

// Theme color for safe queries
define('MIGRATION_SAFE_COLOR', '#28a745'); // Green

// Theme color for dangerous queries
define('MIGRATION_DANGEROUS_COLOR', '#ffc107'); // Yellow

// ==================== HELPER FUNCTIONS ====================

if (!function_exists('getMigrationConfig')) {
    /**
     * Get migration configuration
     */
    function getMigrationConfig(): array {
        return [
            'backup_dir' => MIGRATION_BACKUP_DIR,
            'log_dir' => MIGRATION_LOG_DIR,
            'max_file_size' => MIGRATION_MAX_FILE_SIZE,
            'retention_days' => MIGRATION_BACKUP_RETENTION_DAYS,
            'allowed_roles' => MIGRATION_ALLOWED_ROLES,
            'enable_logging' => MIGRATION_ENABLE_LOGGING,
            'auto_cleanup' => MIGRATION_AUTO_CLEANUP,
            'compress_backups' => MIGRATION_COMPRESS_BACKUPS,
        ];
    }
}

if (!function_exists('validateMigrationConfig')) {
    /**
     * Validate migration configuration
     */
    function validateMigrationConfig(): array {
        $errors = [];

        // Check backup directory
        if (!is_dir(MIGRATION_BACKUP_DIR)) {
            $errors[] = "Backup directory does not exist: " . MIGRATION_BACKUP_DIR;
        } elseif (!is_writable(MIGRATION_BACKUP_DIR)) {
            $errors[] = "Backup directory is not writable: " . MIGRATION_BACKUP_DIR;
        }

        // Check log directory
        if (!is_dir(MIGRATION_LOG_DIR)) {
            $errors[] = "Log directory does not exist: " . MIGRATION_LOG_DIR;
        } elseif (!is_writable(MIGRATION_LOG_DIR)) {
            $errors[] = "Log directory is not writable: " . MIGRATION_LOG_DIR;
        }

        // Check database connection
        global $mysqli;
        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            $errors[] = "Database connection not available";
        } elseif ($mysqli->connect_error) {
            $errors[] = "Database connection error: " . $mysqli->connect_error;
        }

        return $errors;
    }
}

if (!function_exists('getMigrationConfigStatus')) {
    /**
     * Get configuration status for dashboard
     */
    function getMigrationConfigStatus(): array {
        $status = [
            'valid' => true,
            'errors' => [],
            'warnings' => []
        ];

        $errors = validateMigrationConfig();
        if (!empty($errors)) {
            $status['valid'] = false;
            $status['errors'] = $errors;
        }

        // Check for warnings
        if (MIGRATION_BACKUP_RETENTION_DAYS < 7) {
            $status['warnings'][] = "Backup retention is less than 7 days";
        }

        if (MIGRATION_MAX_FILE_SIZE < 1024 * 1024) {
            $status['warnings'][] = "Maximum file size is less than 1MB";
        }

        if (!MIGRATION_ENABLE_LOGGING) {
            $status['warnings'][] = "Migration logging is disabled";
        }

        return $status;
    }
}
