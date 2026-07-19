<?php
/**
 * modules/Services/MigrationService.php
 * 
 * Service layer for database migration operations.
 * Handles SQL file import, export, backup, restore, and logging.
 * Database CRUD is delegated to SqlMigrationModel.
 */

require_once __DIR__ . '/../../models/SqlMigrationModel.php';
require_once __DIR__ . '/../../helpers/migration_helper.php';

class MigrationService
{
    private SqlMigrationModel $migrationModel;
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->migrationModel = new SqlMigrationModel($mysqli);
    }

    /**
     * Get dashboard data (backups + db stats)
     */
    public function getDashboardData(): array
    {
        $backups = $this->migrationModel->getBackupList();
        $dbStats = $this->getDatabaseStats();

        return [
            'backups' => $backups,
            'db_stats' => $dbStats
        ];
    }

    /**
     * Preview SQL file
     */
    public function preview(array $file): array
    {
        if (!isset($file)) {
            throw new \Exception('No file uploaded');
        }

        // Validate upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception("Upload error code: {$file['error']}");
        }

        // Validate file extension
        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'sql') {
            throw new \Exception("Only .sql files are allowed");
        }

        // Read and parse SQL file
        $queries = $this->migrationModel->readSqlFile($file['tmp_name']);
        $categorized = $this->migrationModel->categorizeQueries($queries);

        // Prepare response
        $response = [
            'success' => true,
            'file_name' => basename($file['name']),
            'total_queries' => count($queries),
            'safe_count' => count($categorized['safe']),
            'dangerous_count' => count($categorized['dangerous']),
            'safe_queries' => array_slice($categorized['safe'], 0, 5),
            'dangerous_queries' => $categorized['dangerous'],
            'file_size' => $this->migrationModel->formatFileSize($file['size'])
        ];

        // Store in session for execution
        $_SESSION['sql_migration'] = [
            'queries' => $queries,
            'safe' => $categorized['safe'],
            'dangerous' => $categorized['dangerous'],
            'file_name' => basename($file['name']),
            'timestamp' => time()
        ];

        return $response;
    }

    /**
     * Execute the migration
     */
    public function execute(): array
    {
        if (!isset($_SESSION['sql_migration'])) {
            throw new \Exception('No migration session found');
        }

        $migration = $_SESSION['sql_migration'];

        $results = [
            'safe_results' => [],
            'dangerous_results' => []
        ];

        // Execute safe queries
        if (!empty($migration['safe'])) {
            $results['safe_results'] = $this->migrationModel->executeSafeQueries($migration['safe']);
        }

        // Execute dangerous queries
        if (!empty($migration['dangerous'])) {
            $results['dangerous_results'] = $this->migrationModel->executeDangerousQueries($migration['dangerous']);
        }

        // Get execution log
        $log = $this->migrationModel->getExecutionLog();

        // Log migration to file
        $this->logMigration($migration['file_name'], $results, $log);

        // Clear session
        unset($_SESSION['sql_migration']);

        return [
            'success' => true,
            'results' => $results,
            'log' => $log,
            'message' => 'Migration completed successfully'
        ];
    }

    /**
     * Restore from backup
     */
    public function restore(string $backupName): array
    {
        if (empty($backupName)) {
            throw new \Exception('Backup name required');
        }

        // Security check
        if (strpos($backupName, '..') !== false || strpos($backupName, '/') !== false) {
            throw new \Exception('Invalid backup name');
        }

        $backupDir = __DIR__ . '/../../storage/db_backups/';
        $backupPath = $backupDir . basename($backupName);

        if (!file_exists($backupPath)) {
            throw new \Exception('Backup file not found');
        }

        // Read and execute backup
        $queries = $this->migrationModel->readSqlFile($backupPath);
        $results = $this->migrationModel->executeSafeQueries($queries);

        return [
            'backup_name' => $backupName,
            'results' => $results
        ];
    }

    /**
     * Get backup list
     */
    public function getBackups(): array
    {
        $backups = $this->migrationModel->getBackupList();

        return array_map(function($backup) {
            return [
                'name' => $backup['name'],
                'date' => $backup['formatted_date'],
                'size' => $this->migrationModel->formatFileSize($backup['size'])
            ];
        }, $backups);
    }

    /**
     * Export single table
     */
    public function exportTable(string $table): array
    {
        $exportFile = $this->migrationModel->backupTable($table);

        if ($exportFile === null || !file_exists($exportFile)) {
            throw new \Exception('সিঙ্গেল টেবিল এক্সপোর্ট ব্যর্থ — ফাইল তৈরি হয়নি');
        }

        $fileName = basename($exportFile);
        $fileSize = filesize($exportFile);

        $_SESSION['export_file'] = $fileName;

        return [
            'file_name' => $fileName,
            'file_size' => $this->migrationModel->formatFileSize($fileSize),
            'download_url' => '/admin/database/migrations/download'
        ];
    }

    /**
     * Export database
     */
    public function exportDb(?array $tables = null, bool $includeData = true): array
    {
        $exportFile = $this->migrationModel->exportDatabase($tables, $includeData);

        if ($exportFile === null || !file_exists($exportFile)) {
            throw new \Exception('Export failed — no file was created');
        }

        $fileName = basename($exportFile);
        $fileSize = filesize($exportFile);

        $_SESSION['export_file'] = $fileName;

        return [
            'file_name' => $fileName,
            'file_size' => $this->migrationModel->formatFileSize($fileSize),
            'download_url' => '/admin/database/migrations/download'
        ];
    }

    /**
     * Download the exported SQL file
     */
    public function download(string $fileName): void
    {
        if (empty($fileName)) {
            http_response_code(400);
            echo 'No file specified';
            exit;
        }

        // Security: prevent path traversal
        if (strpos($fileName, '..') !== false || strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false) {
            http_response_code(400);
            echo 'Invalid file name';
            exit;
        }

        $backupDir = __DIR__ . '/../../storage/db_backups/';
        $filePath = $backupDir . $fileName;

        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'File not found';
            exit;
        }

        // Send file for download
        header('Content-Description: File Transfer');
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($filePath);
        unset($_SESSION['export_file']);
        exit;
    }

    /**
     * Get all tables for export selection
     */
    public function getTables(): array
    {
        return $this->migrationModel->getAllTables();
    }

    /**
     * Quick import SQL file
     */
    public function quickImport(array $file): array
    {
        if (!isset($file)) {
            throw new \Exception('কোনো ফাইল আপলোড করা হয়নি।');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception("আপলোড ত্রুটি কোড: {$file['error']}");
        }

        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'sql') {
            throw new \Exception('শুধুমাত্র .sql ফাইল অনুমোদিত।');
        }

        // Execute the import
        $result = $this->migrationModel->quickImport($file['tmp_name']);

        return [
            'file_name' => basename($file['name']),
            'file_size' => $this->migrationModel->formatFileSize($file['size']),
            'result' => $result
        ];
    }

    /**
     * Get migration logs
     */
    public function getLogs(int $limit = 50): array
    {
        $limit = min($limit, 200);
        $logs = getMigrationLog($limit);

        // Enrich log entries for display
        $enriched = array_filter(array_map(function ($entry) {
            if (!is_array($entry)) return null;

            $results = [];
            if (!empty($entry['results_json'])) {
                $decoded = json_decode($entry['results_json'], true);
                if (is_array($decoded)) {
                    $results = $decoded;
                }
            }

            // Count queries by type and status
            $safeResults = $results['safe_results'] ?? [];
            $dangerousResults = $results['dangerous_results'] ?? [];
            $safeTotal = count($safeResults);
            $dangerousTotal = count($dangerousResults);
            $errorCount = 0;

            foreach (array_merge($safeResults, $dangerousResults) as $r) {
                if (isset($r['status']) && $r['status'] === 'error') {
                    $errorCount++;
                }
            }

            $safeSuccess = count(array_filter($safeResults, function ($r) {
                return ($r['status'] ?? '') === 'success';
            }));

            return [
                'filename' => $entry['filename'] ?? 'Unknown',
                'timestamp' => $entry['timestamp'] ?? '',
                'user_id' => $entry['user_id'] ?? null,
                'total_queries' => $safeTotal + $dangerousTotal,
                'safe_total' => $safeTotal,
                'safe_success' => $safeSuccess,
                'dangerous_total' => $dangerousTotal,
                'error_count' => $errorCount,
                'log' => $entry['log'] ?? '',
                'results_json' => $entry['results_json'] ?? ''
            ];
        }, $logs));

        // Compute summary stats
        $totalMigrations = count($enriched);
        $totalQueries = 0;
        $totalErrors = 0;
        foreach ($enriched as $e) {
            $totalQueries += $e['total_queries'];
            $totalErrors += $e['error_count'];
        }

        return [
            'logs' => array_values($enriched),
            'summary' => [
                'total_migrations' => $totalMigrations,
                'total_queries' => $totalQueries,
                'total_errors' => $totalErrors
            ]
        ];
    }

    /**
     * Send JSON response
     */
    public function sendJsonResponse(int $code, string $message, array $data = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }

    /**
     * Get database statistics
     */
    private function getDatabaseStats(): array
    {
        try {
            $stats = [
                'tables' => 0,
                'size' => 0,
                'backups' => 0,
                'last_backup' => 'Never'
            ];

            // Count tables
            $result = $this->mysqli->query(
                "SELECT COUNT(*) as count FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'"
            );
            if ($result) {
                $row = $result->fetch_assoc();
                $stats['tables'] = $row['count'] ?? 0;
            }

            // Get database size
            $result = $this->mysqli->query(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size 
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = '" . DB_NAME . "'"
            );
            if ($result) {
                $row = $result->fetch_assoc();
                $stats['size'] = ($row['size'] ?? 0) . ' MB';
            }

            // Get backup count
            $backups = $this->migrationModel->getBackupList();
            $stats['backups'] = count($backups);
            
            // Get last backup date
            if (!empty($backups)) {
                $stats['last_backup'] = $backups[0]['formatted_date'] ?? 'Never';
            }

            return $stats;
        } catch (\Exception $e) {
            error_log("Database stats error: " . $e->getMessage());
            return [
                'tables' => 0,
                'size' => 'N/A',
                'backups' => 0,
                'last_backup' => 'N/A'
            ];
        }
    }

    /**
     * Log migration to file
     */
    private function logMigration(string $fileName, array $results, array $log): void
    {
        $logEntry = [
            'filename' => $fileName,
            'timestamp' => date('Y-m-d H:i:s'),
            'results_json' => json_encode($results),
            'log' => implode("\n", $log)
        ];

        // Log to file
        $logFile = __DIR__ . '/../../storage/logs/migrations.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents(
            $logFile,
            json_encode($logEntry) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Require superadmin access
     */
    public static function requireSuperadmin(mysqli $mysqli): array
    {
        $auth = new AuthManager($mysqli);
        $auth->requireLogin();
        
        $user = $auth->getUserData(false);
        
        // Check if user is superadmin (role_id 0 or 1)
        if (!isset($user['role_id']) || $user['role_id'] > 1) {
            http_response_code(403);
            
            $isAjax = (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            );
            
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'শুধুমাত্র সুপার অ্যাডমিন এই ফিচার ব্যবহার করতে পারবেন।'
                ]);
            } else {
                renderError(403, 'শুধুমাত্র সুপার অ্যাডমিন এই ফিচার ব্যবহার করতে পারবেন।');
            }
            exit;
        }
        
        return $user;
    }
}
