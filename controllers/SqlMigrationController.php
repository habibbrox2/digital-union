<?php
// controllers/SqlMigrationController.php

/**
 * SQL Migration Controller
 * Handles HTTP requests for database migrations
 * 
 * Author: Hr Habib
 * Updated: 2026
 */

require_once __DIR__ . '/../classes/SqlMigrationModel.php';

class SqlMigrationController
{
    private SqlMigrationModel $migrationModel;
    private SafeTwig $twig;
    private array $user;

    public function __construct(SqlMigrationModel $migrationModel, SafeTwig $twig, array $user = [])
    {
        $this->migrationModel = $migrationModel;
        $this->twig = $twig;
        $this->user = $user;
    }

    /**
     * Display migration dashboard
     */
    public function dashboard(): void
    {
        // Check superadmin permission
        if (!$this->isSuperAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access Denied: Superadmin only']);
            exit;
        }

        $backups = $this->migrationModel->getBackupList();
        $csrf_token = generateCsrfToken();
        
        // Get database statistics
        $dbStats = $this->getDatabaseStats();

        echo $this->twig->render('migrations/dashboard.twig', [
            'backups' => $backups,
            'csrf_token' => $csrf_token,
            'page_title' => 'Database Migrations',
            'page_icon' => 'database',
            'title' => 'Database Migrations',
            'header_title' => 'Database Migrations',
            'db_stats' => $dbStats
        ]);
    }

    /**
     * Handle SQL file upload and preview
     */
    public function preview(): void
    {
        if (!$this->isSuperAdmin()) {
            $this->sendJsonResponse(403, 'শুধুমাত্র সুপার অ্যাডমিন এই ফিচার ব্যবহার করতে পারবেন।');
        }

        if (!isset($_FILES['sql_file'])) {
            $this->sendJsonResponse(400, 'No file uploaded');
        }

        try {
            $file = $_FILES['sql_file'];

            // Validate upload
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Upload error code: {$file['error']}");
            }

            // Validate file extension
            $fileName = $file['name'];
            if (pathinfo($fileName, PATHINFO_EXTENSION) !== 'sql') {
                throw new Exception("Only .sql files are allowed");
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
                'safe_queries' => array_slice($categorized['safe'], 0, 5), // Show first 5
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

            $this->sendJsonResponse(200, 'File parsed successfully', $response);
        } catch (Exception $e) {
            $this->sendJsonResponse(400, $e->getMessage());
        }
    }

    /**
     * Execute the migration
     */
    public function execute(): void
    {
        if (!$this->isSuperAdmin()) {
            $this->sendJsonResponse(403, 'শুধুমাত্র সুপার অ্যাডমিন এই ফিচার ব্যবহার করতে পারবেন।');
        }

        if (!isset($_SESSION['sql_migration'])) {
            $this->sendJsonResponse(400, 'No migration session found');
        }

        try {
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

            // Log migration to database
            $this->logMigration($migration['file_name'], $results, $log);

            // Clear session
            unset($_SESSION['sql_migration']);

            $response = [
                'success' => true,
                'results' => $results,
                'log' => $log,
                'message' => 'Migration completed successfully'
            ];

            $this->sendJsonResponse(200, 'Migration executed successfully', $response);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, 'Execution error: ' . $e->getMessage());
        }
    }

    /**
     * Restore from backup
     */
    public function restore(): void
    {
        if (!$this->isSuperAdmin()) {
            $this->sendJsonResponse(403, 'শুধুমাত্র সুপার অ্যাডমিন এই ফিচার ব্যবহার করতে পারবেন।');
        }

        $backupName = $_POST['backup_name'] ?? '';
        if (empty($backupName)) {
            $this->sendJsonResponse(400, 'Backup name required');
        }

        try {
            // Security check
            if (strpos($backupName, '..') !== false || strpos($backupName, '/') !== false) {
                throw new Exception('Invalid backup name');
            }

            $backupDir = __DIR__ . '/../storage/db_backups/';
            $backupPath = $backupDir . basename($backupName);

            if (!file_exists($backupPath)) {
                throw new Exception('Backup file not found');
            }

            // Read and execute backup
            $queries = $this->migrationModel->readSqlFile($backupPath);
            $results = $this->migrationModel->executeSafeQueries($queries);

            $this->sendJsonResponse(200, 'Backup restored successfully', [
                'backup_name' => $backupName,
                'results' => $results
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(400, $e->getMessage());
        }
    }

    /**
     * Get backup list
     */
    public function getBackups(): void
    {
        if (!$this->isSuperAdmin()) {
            $this->sendJsonResponse(403, 'শুধুমাত্র সুপার অ্যাডমিন এই ফিচার ব্যবহার করতে পারবেন।');
        }

        $backups = $this->migrationModel->getBackupList();

        $formatted = array_map(function($backup) {
            return [
                'name' => $backup['name'],
                'date' => $backup['formatted_date'],
                'size' => $this->migrationModel->formatFileSize($backup['size'])
            ];
        }, $backups);

        $this->sendJsonResponse(200, 'Backups retrieved', ['backups' => $formatted]);
    }

    /**
     * Log migration to database
     */
    private function logMigration(string $fileName, array $results, array $log): void
    {
        // You can save this to a migration_logs table if needed
        $logEntry = [
            'filename' => $fileName,
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $this->user['id'] ?? null,
            'results_json' => json_encode($results),
            'log' => implode("\n", $log)
        ];

        // Log to file
        $logFile = __DIR__ . '/../storage/logs/migrations.log';
        file_put_contents(
            $logFile,
            json_encode($logEntry) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Check if user is superadmin
     */
    private function isSuperAdmin(): bool
    {
        // Superadmin only: role_id must be 0 or 1
        return isset($this->user['role_id']) && $this->user['role_id'] <= 1;
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
            $result = $this->migrationModel->getMysqli()->query(
                "SELECT COUNT(*) as count FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'"
            );
            if ($result) {
                $row = $result->fetch_assoc();
                $stats['tables'] = $row['count'] ?? 0;
            }

            // Get database size
            $result = $this->migrationModel->getMysqli()->query(
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
        } catch (Exception $e) {
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
     * Send JSON response
     */
    private function sendJsonResponse(int $code, string $message, array $data = []): void
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
}

/*
|--------------------------------------------------------------------------
| DATABASE MIGRATIONS ROUTES
|--------------------------------------------------------------------------
*/

// Helper function to check if user is superadmin
if (!function_exists('requireSuperadmin')) {
    function requireSuperadmin(mysqli $mysqli): array {
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

// GET : Display migration dashboard
if (isset($router)) {
    $router->get('/admin/database/migrations', function () {
        global $mysqli, $twig;
        
        try {
            $user = requireSuperadmin($mysqli);

            $migrationModel = new SqlMigrationModel($mysqli);
            $controller = new SqlMigrationController($migrationModel, $twig, $user);
            $controller->dashboard();
        } catch (Throwable $e) {
            error_log('Migration Dashboard Error: ' . $e->getMessage());
            renderError(500, 'ডাটাবেস মাইগ্রেশন পৃষ্ঠা লোড করতে ব্যর্থ।');
        }
    });

    // POST : Preview SQL file
    $router->post('/admin/database/migrations/preview', function () {
        global $mysqli, $twig;
        
        try {
            $user = requireSuperadmin($mysqli);

            $migrationModel = new SqlMigrationModel($mysqli);
            $controller = new SqlMigrationController($migrationModel, $twig, $user);
            $controller->preview();
        } catch (Throwable $e) {
            error_log('Migration Preview Error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['code' => 500, 'message' => 'প্রিভিউ প্রক্রিয়া ব্যর্থ হয়েছে।']);
        }
    });

    // POST : Execute migration
    $router->post('/admin/database/migrations/execute', function () {
        global $mysqli, $twig;
        
        try {
            $user = requireSuperadmin($mysqli);

            $migrationModel = new SqlMigrationModel($mysqli);
            $controller = new SqlMigrationController($migrationModel, $twig, $user);
            $controller->execute();
        } catch (Throwable $e) {
            error_log('Migration Execute Error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['code' => 500, 'message' => 'মাইগ্রেশন সম্পাদন ব্যর্থ হয়েছে।']);
        }
    });

    // POST : Restore from backup
    $router->post('/admin/database/migrations/restore', function () {
        global $mysqli, $twig;
        
        try {
            $user = requireSuperadmin($mysqli);

            $migrationModel = new SqlMigrationModel($mysqli);
            $controller = new SqlMigrationController($migrationModel, $twig, $user);
            $controller->restore();
        } catch (Throwable $e) {
            error_log('Migration Restore Error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['code' => 500, 'message' => 'ব্যাকআপ পুনরুদ্ধার ব্যর্থ হয়েছে।']);
        }
    });

    // POST : Get backup list
    $router->post('/admin/database/migrations/backups', function () {
        global $mysqli, $twig;
        
        try {
            $user = requireSuperadmin($mysqli);

            $migrationModel = new SqlMigrationModel($mysqli);
            $controller = new SqlMigrationController($migrationModel, $twig, $user);
            $controller->getBackups();
        } catch (Throwable $e) {
            error_log('Get Backups Error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['code' => 500, 'message' => 'ব্যাকআপ তালিকা পেতে ব্যর্থ।']);
        }
    });
}
