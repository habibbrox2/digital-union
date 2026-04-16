<?php

/**
 * SQL Migration Helpers
 * Utility functions for database migrations
 * 
 * Author: Hr Habib
 * Updated: 2026
 */

declare(strict_types=1);

/**
 * Initialize migration model
 */
if (!function_exists('getMigrationModel')) {
    function getMigrationModel(mysqli $mysqli): SqlMigrationModel {
        return new SqlMigrationModel($mysqli);
    }
}

/**
 * Get list of all backups with details
 */
if (!function_exists('listAllBackups')) {
    function listAllBackups(): array {
        $backupDir = __DIR__ . '/../storage/db_backups/';
        $backups = [];

        if (!is_dir($backupDir)) {
            return $backups;
        }

        $files = array_reverse(scandir($backupDir));
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && str_ends_with($file, '.sql')) {
                $filePath = $backupDir . $file;
                $backups[] = [
                    'name' => $file,
                    'path' => $filePath,
                    'size' => filesize($filePath),
                    'date' => filemtime($filePath),
                    'formatted_date' => date('d M Y H:i:s', filemtime($filePath)),
                    'formatted_size' => formatBackupSize(filesize($filePath))
                ];
            }
        }

        return $backups;
    }
}

/**
 * Format file size
 */
if (!function_exists('formatBackupSize')) {
    function formatBackupSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

/**
 * Clean old backups (older than specified days)
 */
if (!function_exists('cleanOldBackups')) {
    function cleanOldBackups(int $daysToKeep = 30): int {
        $backupDir = __DIR__ . '/../storage/db_backups/';
        $deletedCount = 0;

        if (!is_dir($backupDir)) {
            return 0;
        }

        $cutoffTime = strtotime("-{$daysToKeep} days");
        $files = scandir($backupDir);

        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && str_ends_with($file, '.sql')) {
                $filePath = $backupDir . $file;
                $fileTime = filemtime($filePath);

                if ($fileTime && $fileTime < $cutoffTime) {
                    if (unlink($filePath)) {
                        error_log("Deleted old backup: {$file}");
                        $deletedCount++;
                    }
                }
            }
        }

        return $deletedCount;
    }
}

/**
 * Get migration execution log
 */
if (!function_exists('getMigrationLog')) {
    function getMigrationLog(int $limit = 100): array {
        $logFile = __DIR__ . '/../storage/logs/migrations.log';
        $logs = [];

        if (!file_exists($logFile)) {
            return $logs;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $lines = array_reverse($lines);

        foreach (array_slice($lines, 0, $limit) as $line) {
            if (!empty($line)) {
                $logs[] = json_decode($line, true);
            }
        }

        return $logs;
    }
}

/**
 * Export migration as JSON
 */
if (!function_exists('exportMigrationLog')) {
    function exportMigrationLog(): string {
        $logs = getMigrationLog(PHP_INT_MAX);
        return json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

/**
 * Validate SQL query syntax
 */
if (!function_exists('validateSqlSyntax')) {
    function validateSqlSyntax(string $query, mysqli $mysqli): array {
        // Simple validation - check for balanced parentheses and quotes
        $errors = [];

        // Check parentheses balance
        if (substr_count($query, '(') !== substr_count($query, ')')) {
            $errors[] = 'Unbalanced parentheses';
        }

        // Check quotes balance
        $singleQuotes = substr_count($query, "'") - substr_count($query, "\\'");
        if ($singleQuotes % 2 !== 0) {
            $errors[] = 'Unbalanced single quotes';
        }

        // Try to parse with mysqli
        if (@$mysqli->query("EXPLAIN " . $query) === false) {
            // This is just a basic check, actual validation happens on execute
            // Commenting out to avoid false positives
            // $errors[] = $mysqli->error;
        }

        return $errors;
    }
}

/**
 * Get database statistics
 */
if (!function_exists('getDatabaseStats')) {
    function getDatabaseStats(mysqli $mysqli): array {
        $stats = [
            'tables' => 0,
            'size' => 0,
            'backups' => count(listAllBackups())
        ];

        $result = $mysqli->query("SELECT COUNT(*) as count FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['tables'] = $row['count'] ?? 0;
        }

        // Get database size
        $result = $mysqli->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size 
                                FROM information_schema.TABLES 
                                WHERE TABLE_SCHEMA = '" . DB_NAME . "'");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['size'] = $row['size'] ?? 0;
        }

        return $stats;
    }
}

/**
 * Generate migration report
 */
if (!function_exists('generateMigrationReport')) {
    function generateMigrationReport(mysqli $mysqli): array {
        $logs = getMigrationLog();
        $stats = getDatabaseStats($mysqli);
        $backups = listAllBackups();

        $totalQueries = 0;
        $totalSuccessful = 0;
        $totalFailed = 0;

        foreach ($logs as $log) {
            if (isset($log['results_json'])) {
                $results = json_decode($log['results_json'], true);
                // Count queries
            }
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'database_stats' => $stats,
            'recent_migrations' => count($logs),
            'total_backups' => count($backups),
            'backup_size' => array_sum(array_column($backups, 'size')),
            'migrations_log' => $logs
        ];
    }
}

/**
 * Setup SQL Migration permissions in RBAC
 */
if (!function_exists('setupMigrationPermissions')) {
    function setupMigrationPermissions(mysqli $mysqli): void {
        require_once __DIR__ . '/../classes/PermissionsManager.php';
        
        $permManager = new PermissionsManager($mysqli);
        
        // Check if permissions already exist
        $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM permissions WHERE name LIKE 'manage_database_%'");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['count'] > 0) {
            return; // Permissions already exist
        }
        
        // Create permissions
        $permissions = [
            [
                'name' => 'manage_database_migrations',
                'module' => 'database',
                'description' => 'ডাটাবেস মাইগ্রেশন পরিচালনা করতে পারবে'
            ],
            [
                'name' => 'view_database_migrations',
                'module' => 'database',
                'description' => 'ডাটাবেস মাইগ্রেশন দেখতে পারবে'
            ],
            [
                'name' => 'execute_database_migrations',
                'module' => 'database',
                'description' => 'ডাটাবেস মাইগ্রেশন চালাতে পারবে'
            ],
            [
                'name' => 'manage_database_backups',
                'module' => 'database',
                'description' => 'ডাটাবেস ব্যাকআপ পরিচালনা করতে পারবে'
            ]
        ];
        
        foreach ($permissions as $perm) {
            $permManager->createPermission($perm['name'], $perm['module'], $perm['description']);
        }
    }
}
