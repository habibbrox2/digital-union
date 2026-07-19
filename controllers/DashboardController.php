<?php
/**
 * controllers/DashboardController.php
 * 
 * Dashboard routes - uses DashboardService for all business logic.
 * Controllers only define routes with permission checks and template rendering.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);

// ================================================================
// GET : Dashboard page
// ================================================================
$router->get('/dashboard', function () use ($twig, $authService) {
    $authService->ensureCan('manage_dashboard', 'dashboard');

    echo $twig->render('dashboard.twig', [
        'title'        => 'ড্যাশবোর্ড',
        'header_title' => 'স্বাগতম আপনার ড্যাশবোর্ডে',
    ]);
});

// ================================================================
// GET : Certificate data (chart)
// ================================================================
$router->get('/chart-data', function () use ($mysqli, $authService) {
    $authService->ensureCan('manage_dashboard', 'dashboard');

    $service = new DashboardService($mysqli);
    $data = $service->getCertificateData();

    header('Content-Type: application/json');
    echo json_encode($data);
});

// ================================================================
// GET : Monthly certificate data (chart)
// ================================================================
$router->get('/chart-data-monthly', function () use ($mysqli, $authService) {
    $authService->ensureCan('manage_dashboard', 'dashboard');

    $service = new DashboardService($mysqli);
    $data = $service->getMonthlyCertificateData();

    header('Content-Type: application/json');
    echo json_encode($data);
});

// ================================================================
// GET : Monthly status data (chart)
// ================================================================
$router->get('/chart-data-monthly-status', function () use ($mysqli, $authService) {
    $authService->ensureCan('manage_dashboard', 'dashboard');

    $service = new DashboardService($mysqli);
    $data = $service->getMonthlyStatusData();

    header('Content-Type: application/json');
    echo json_encode($data);
});

// ================================================================
// GET : Certificate search
// ================================================================
$router->get('/certificate-search', function () use ($mysqli, $authService) {
    $authService->ensureCan('manage_dashboard', 'dashboard');

    $query = $_GET['q'] ?? '';

    $service = new DashboardService($mysqli);
    $result = $service->certificateSearch($query);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
});

// ================================================================
// GET : Error statistics
// ================================================================
$router->get('/admin/error-stats', function () use ($mysqli, $authService) {
    $authService->ensureCan('manage_dashboard', 'dashboard');

    $service = new DashboardService($mysqli);
    $stats = $service->getErrorStats();

    header('Content-Type: application/json');
    echo json_encode($stats);
});

// ================================================================
// GET : Error logs viewer
// ================================================================
$router->get('/admin/logs', function () use ($twig, $mysqli, $authService) {
    $authService->ensureCan('manage_dashboard', 'dashboard');

    $service = new DashboardService($mysqli);

    // Clear log
    if (isset($_GET['action']) && $_GET['action'] === 'clear') {
        $service->clearErrorLogs();
        header('Location: /admin/logs?cleared=1');
        exit;
    }

    // Download log
    if (isset($_GET['action']) && $_GET['action'] === 'download') {
        $logFile = $service->getErrorLogFile();
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="error.log"');
        readfile($logFile);
        exit;
    }

    // AJAX Live refresh
    if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
        $data = $service->getErrorLogs();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo $twig->render('errors/error_logs.twig', [
        'title'        => 'ত্রুটির লগ',
        'header_title' => '🧠 Error Log Viewer',
        'cleared'      => isset($_GET['cleared']),
    ]);
});
