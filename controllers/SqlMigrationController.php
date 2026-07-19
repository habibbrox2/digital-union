<?php
/**
 * controllers/SqlMigrationController.php
 * 
 * Database migration routes - pure closures using MigrationService.
 * No class-based pattern, no standalone helper functions.
 * All migration logic is in modules/Services/MigrationService.php.
 */

global $router, $twig, $mysqli;

$migrationService = new MigrationService($mysqli);

// Helper: require superadmin via service
$requireSuperadmin = function() use ($mysqli) {
    return MigrationService::requireSuperadmin($mysqli);
};

// GET : Display migration dashboard
$router->get('/admin/database/migrations', function() use ($twig, $migrationService, $requireSuperadmin) {
    try {
        $user = $requireSuperadmin();
        $data = $migrationService->getDashboardData();
        $csrf_token = generateCsrfToken();
        
        echo $twig->render('migrations/dashboard.twig', [
            'backups' => $data['backups'],
            'csrf_token' => $csrf_token,
            'page_title' => 'Database Migrations',
            'page_icon' => 'database',
            'title' => 'Database Migrations',
            'header_title' => 'Database Migrations',
            'db_stats' => $data['db_stats']
        ]);
    } catch (\Throwable $e) {
        error_log('Migration Dashboard Error: ' . $e->getMessage());
        renderError(500, 'ডাটাবেস মাইগ্রেশন পৃষ্ঠা লোড করতে ব্যর্থ।');
    }
});

// POST : Preview SQL file
$router->post('/admin/database/migrations/preview', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $result = $migrationService->preview($_FILES['sql_file'] ?? null);
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'File parsed successfully', 'data' => $result]);
        exit;
    } catch (\Throwable $e) {
        error_log('Migration Preview Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => $e->getMessage() ?: 'প্রিভিউ প্রক্রিয়া ব্যর্থ হয়েছে।']);
        exit;
    }
});

// POST : Execute migration
$router->post('/admin/database/migrations/execute', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $result = $migrationService->execute();
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'Migration executed successfully', 'data' => $result]);
        exit;
    } catch (\Throwable $e) {
        error_log('Migration Execute Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => $e->getMessage() ?: 'মাইগ্রেশন সম্পাদন ব্যর্থ হয়েছে।']);
        exit;
    }
});

// POST : Restore from backup
$router->post('/admin/database/migrations/restore', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $backupName = $input['backup_name'] ?? $_POST['backup_name'] ?? '';
        $result = $migrationService->restore($backupName);
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'Backup restored successfully', 'data' => $result]);
        exit;
    } catch (\Throwable $e) {
        error_log('Migration Restore Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => $e->getMessage() ?: 'ব্যাকআপ পুনরুদ্ধার ব্যর্থ হয়েছে।']);
        exit;
    }
});

// POST : Get backup list
$router->post('/admin/database/migrations/backups', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $backups = $migrationService->getBackups();
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'Backups retrieved', 'data' => ['backups' => $backups]]);
        exit;
    } catch (\Throwable $e) {
        error_log('Get Backups Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => 'ব্যাকআপ তালিকা পেতে ব্যর্থ।']);
        exit;
    }
});

// POST : Export single table
$router->post('/admin/database/migrations/export-table', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $table = $input['table'] ?? null;

        if (empty($table)) {
            http_response_code(400);
            echo json_encode(['code' => 400, 'message' => 'টেবিলের নাম দিন।']);
            exit;
        }

        $result = $migrationService->exportTable($table);
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'টেবিল এক্সপোর্ট সফল!', 'data' => $result]);
        exit;
    } catch (\Throwable $e) {
        error_log('Export Table Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => $e->getMessage() ?: 'সিঙ্গেল টেবিল এক্সপোর্ট ব্যর্থ হয়েছে।']);
        exit;
    }
});

// POST : Export database
$router->post('/admin/database/migrations/export', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $tables = $input['tables'] ?? null;
        $includeData = filter_var($input['include_data'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $result = $migrationService->exportDb($tables, $includeData);
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'এক্সপোর্ট সফল!', 'data' => $result]);
        exit;
    } catch (\Throwable $e) {
        error_log('Export Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => $e->getMessage() ?: 'এক্সপোর্ট প্রক্রিয়া ব্যর্থ হয়েছে।']);
        exit;
    }
});

// POST : Download exported file
$router->post('/admin/database/migrations/download', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $fileName = $_SESSION['export_file'] ?? $_GET['file'] ?? '';
        $migrationService->download($fileName);
    } catch (\Throwable $e) {
        error_log('Download Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => 'ফাইল ডাউনলোড ব্যর্থ হয়েছে।']);
        exit;
    }
});

// POST : Get all tables for export selection
$router->post('/admin/database/migrations/tables', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $tables = $migrationService->getTables();
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'টেবিল তালিকা সফল', 'data' => ['tables' => $tables]]);
        exit;
    } catch (\Throwable $e) {
        error_log('Get Tables Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => 'টেবিল তালিকা পেতে ব্যর্থ।']);
        exit;
    }
});

// POST : Quick import SQL file
$router->post('/admin/database/migrations/quick-import', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $result = $migrationService->quickImport($_FILES['sql_file'] ?? null);
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'ইম্পোর্ট সম্পন্ন!', 'data' => $result]);
        exit;
    } catch (\Throwable $e) {
        error_log('Quick Import Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => $e->getMessage() ?: 'কুইক ইম্পোর্ট ব্যর্থ হয়েছে।']);
        exit;
    }
});

// POST : Get migration logs
$router->post('/admin/database/migrations/logs', function() use ($migrationService, $requireSuperadmin) {
    try {
        $requireSuperadmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $limit = min((int)($input['limit'] ?? 50), 200);
        $result = $migrationService->getLogs($limit);
        header('Content-Type: application/json');
        echo json_encode(['code' => 200, 'message' => 'Migration logs retrieved', 'data' => $result]);
        exit;
    } catch (\Throwable $e) {
        error_log('Get Logs Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => $e->getMessage() ?: 'মাইগ্রেশন লগ পেতে ব্যর্থ।']);
        exit;
    }
});
