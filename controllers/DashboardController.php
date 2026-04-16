<?php
// controllers/DashboardController.php

require_once __DIR__ . '/../classes/UnionModel.php';

/**
 * Show Dashboard Page
 */
function showDashboard() {
    global $twig, $mysqli;

    $auth = new AuthManager($mysqli);
    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_dashboard', 'dashboard');

    echo $twig->render('dashboard.twig', [
        'title'        => 'ড্যাশবোর্ড',
        'header_title' => 'স্বাগতম আপনার ড্যাশবোর্ডে',
    ]);
}



/**
 * Send JSON Response
 */
function sendJson($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Monthly total applications
 */
function getMonthlyCertificateData() {
    global $mysqli;
    $auth = new AuthManager($mysqli);
    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_dashboard', 'dashboard');

    $params = [];
    $types  = '';
    $unionModel = new UnionModel($mysqli);
    $where  = $unionModel->getUnionCondition($params, $types, 'a', true);

    $query = "
        SELECT DATE_FORMAT(a.apply_date, '%Y-%m') AS month, COUNT(a.id) AS total
        FROM applications a
        $where
        GROUP BY month
        ORDER BY month
    ";

    $stmt = $mysqli->prepare($query);
    if ($stmt === false) die("Query prepare failed.");

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $labels = [];
    $data   = [];
    while ($row = $result->fetch_assoc()) {
        $labels[] = $row['month'];
        $data[]   = (int)$row['total'];
    }
    $stmt->close();

    sendJson([
        'labels'   => $labels,
        'datasets' => [[
            'label'           => 'মোট আবেদন',
            'data'            => $data,
            'borderColor'     => '#007bff',
            'backgroundColor' => '#007bff',
            'fill'            => false
        ]]
    ]);
}

/**
 * Monthly applications by status
 */
function getMonthlyStatusData() {
    global $mysqli;
    $auth = new AuthManager($mysqli);
    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_dashboard', 'dashboard');

    $params = [];
    $types  = '';
    $unionModel = new UnionModel($mysqli);
    $where  = $unionModel->getUnionCondition($params, $types, 'a', true);

    $query = "
        SELECT DATE_FORMAT(a.apply_date, '%Y-%m') AS month, LOWER(a.status) AS status, COUNT(a.id) AS total
        FROM applications a
        $where
        GROUP BY month, status
        ORDER BY month
    ";

    $stmt = $mysqli->prepare($query);
    if ($stmt === false) die("Query prepare failed.");

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rawData = [];
    $labels  = [];
    while ($row = $result->fetch_assoc()) {
        $rawData[$row['status']][$row['month']] = (int)$row['total'];
        if (!in_array($row['month'], $labels)) {
            $labels[] = $row['month'];
        }
    }
    $stmt->close();

    $statusTypes = ['pending', 'approved', 'rejected', 'on_hold'];
    $colors      = ['#ffc107', '#28a745', '#dc3545', '#6c757d'];
    $datasets    = [];

    foreach ($statusTypes as $index => $status) {
        $data = [];
        foreach ($labels as $month) {
            $data[] = $rawData[$status][$month] ?? 0;
        }
        $datasets[] = [
            'label'           => ucfirst(str_replace('_', ' ', $status)),
            'data'            => $data,
            'borderColor'     => $colors[$index],
            'backgroundColor' => $colors[$index],
            'fill'            => false
        ];
    }

    sendJson([
        'labels'   => $labels,
        'datasets' => $datasets
    ]);
}

/**
 * Total certificate count
 */
function getTotalCertificatesCount() {
    global $mysqli;
    $result = $mysqli->query("SELECT COUNT(*) AS total_certificates FROM term_translations WHERE is_certificate_type = 1");
    $row = $result->fetch_assoc();
    return (int)$row['total_certificates'];
}

/**
 * Applications by certificate type
 */
function getCertificateData() {
    global $mysqli;
    $auth = new AuthManager($mysqli);
    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_dashboard', 'dashboard');

    $user     = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    // ১. সার্টিফিকেটের তালিকা ও আবেদন সংখ্যা (LEFT JOIN + UNION ফিল্টার)
    $params   = [];
    $types    = '';

    $query = "
        SELECT t.slug, t.name_bn, COUNT(a.id) AS total_applications
        FROM term_translations AS t
        LEFT JOIN applications AS a
            ON t.slug = a.certificate_type
    ";

    if (!empty($union_id) && $union_id != 0) {
        $query .= " AND a.union_id = ?";
        $params[] = $union_id;
        $types   .= 'i';
    }

    $query .= "
        WHERE t.is_certificate_type = 1
        GROUP BY t.slug, t.name_bn
        ORDER BY t.name_bn ASC
    ";

    $stmt = $mysqli->prepare($query);
    if ($stmt === false) {
        http_response_code(500);
        die("Query prepare failed: " . $mysqli->error);
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $colors = [
        '#007bff', '#dc3545', '#28a745', '#ffc107', '#6610f2', '#fd7e14',
        '#20c997', '#6f42c1', '#e83e8c', '#343a40', '#17a2b8', '#fd6f6f',
        '#ffc0cb', '#90ee90', '#ffa500', '#8a2be2', '#00ced1', '#ff69b4',
        '#cd5c5c', '#4b0082', '#2e8b57', '#ff4500', '#9acd32', '#1e90ff',
        '#ff1493', '#32cd32', '#8b0000', '#00fa9a', '#ff6347', '#4682b4',
        '#b22222', '#ff8c00', '#006400', '#8b008b', '#483d8b', '#2f4f4f',
        '#00bfff', '#ff00ff', '#c71585', '#191970', '#7fff00', '#d2691e',
        '#ff7f50', '#6495ed', '#ffdead', '#daa520', '#808000', '#556b2f',
        '#fa8072', '#f08080', '#e9967a', '#8fbc8f', '#20b2aa', '#87cefa'
    ];

    $datasets           = [];
    $index              = 0;
    $totalApplications  = 0;
    $totalCertificates  = 0;

    while ($row = $result->fetch_assoc()) {
        $datasets[] = [
            'label'           => $row['name_bn'],
            'data'            => [(int)$row['total_applications']],
            'borderColor'     => $colors[$index % count($colors)],
            'backgroundColor' => $colors[$index % count($colors)],
            'fill'            => false
        ];

        $totalApplications += (int)$row['total_applications'];
        $totalCertificates++;
        $index++;
    }
    $stmt->close();

    // ২. মোট সার্টিফিকেট সংখ্যা আলাদাভাবে নেওয়া
    $stmt2 = $mysqli->prepare("SELECT COUNT(*) AS total_certificates FROM term_translations WHERE is_certificate_type = 1");
    if ($stmt2 === false) {
        http_response_code(500);
        die("Query prepare failed: " . $mysqli->error);
    }
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $row2 = $result2->fetch_assoc();
    $totalCertificatesAlt = (int)$row2['total_certificates'];
    $stmt2->close();

    // এখন আউটপুট
    sendJson([
        'total_certificates' => $totalCertificatesAlt,  // আলাদা কুয়েরি থেকে পাওয়া মোট সার্টিফিকেট
        'total_applications' => $totalApplications,     // আগের কুয়েরি থেকে পাওয়া মোট আবেদন সংখ্যা
        'labels'             => ['মোট আবেদন'],         // চার্টের label আগের মতোই
        'datasets'           => $datasets
    ]);
}


function certificateSearchController() {
    global $mysqli;

    header('Content-Type: application/json; charset=utf-8');

    $query = isset($_GET['q']) ? trim($_GET['q']) : '';

    if ($query === '') {
        echo json_encode(['error' => 'No search term provided']);
        exit;
    }

    if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
        echo json_encode(['error' => 'Search term length invalid']);
        exit;
    }

    // Updated query with extra joins + search in extra_data
    $stmt = $mysqli->prepare("
        SELECT
            a.id AS app_id,
            a.application_id,
            a.applicant_id,
            a.sonod_number,
            a.name_bn,
            a.name_en,
            a.issue_date,
            a.certificate_type,
            a.apply_date,
            a.extra_data,
            aa.approval_status,
            am.name_en AS member_name_en,
            am.name_bn AS member_name_bn,
            am.relation_en,
            am.relation_bn,
            am.nid AS member_nid,
            bm.business_name_en,
            bm.business_name_bn,
            bm.vat_id,
            bm.tax_id,
            t.name_bn AS certificate_name_bn,
            t.name_en AS certificate_name_en,
            t.name_bl AS certificate_name_bl
        FROM applications a
        LEFT JOIN term_translations t 
        ON t.slug = a.certificate_type
        LEFT JOIN application_approvals aa 
            ON a.application_id = aa.application_id
        LEFT JOIN application_members am
            ON a.application_id = am.application_id
        LEFT JOIN business_meta bm
            ON a.application_id = bm.application_id
        WHERE
            a.application_id LIKE CONCAT('%', ?, '%')
            OR a.sonod_number LIKE CONCAT('%', ?, '%')
            OR a.name_en LIKE CONCAT('%', ?, '%')
            OR a.name_bn LIKE CONCAT('%', ?, '%')
            OR a.extra_data LIKE CONCAT('%', ?, '%')
            OR am.name_en LIKE CONCAT('%', ?, '%')
            OR am.name_bn LIKE CONCAT('%', ?, '%')
            OR am.nid LIKE CONCAT('%', ?, '%')
            OR bm.business_name_en LIKE CONCAT('%', ?, '%')
            OR bm.business_name_bn LIKE CONCAT('%', ?, '%')
            OR bm.vat_id LIKE CONCAT('%', ?, '%')
            OR bm.tax_id LIKE CONCAT('%', ?, '%')
        ORDER BY a.issue_date DESC
        LIMIT 50
    ");

    if (!$stmt) {
        echo json_encode(['error' => 'Database error preparing statement']);
        exit;
    }

    // Bind the same search query to all placeholders
    $stmt->bind_param(
        str_repeat('s', 12),
        $query, $query, $query, $query, $query, $query,
        $query, $query, $query, $query, $query, $query
    );

    if (!$stmt->execute()) {
        echo json_encode(['error' => 'Database error executing statement']);
        exit;
    }

    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $stmt->close();

    echo json_encode([
        'query' => $query,
        'count' => count($data),
        'results' => $data
    ], JSON_UNESCAPED_UNICODE);
}


/**
 * Show Error Log (Only for Superadmin) — with Twig + Live Reload
 */
function showErrorLogs() {
    global $twig, $mysqli;

    $auth = new AuthManager($mysqli);
    $auth->requireLogin();

    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? 0;

    // 🔒 (Optional) Superadmin check
    // if ($user['role'] !== 'superadmin') {
    //     http_response_code(403);
    //     echo "Access denied.";
    //     exit;
    // }

    $logDir  = __DIR__ . '/../storage/logs';
    $logFile = $logDir . '/error.log';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    if (!file_exists($logFile)) file_put_contents($logFile, '');

    // Clear log
    if (isset($_GET['action']) && $_GET['action'] === 'clear') {
        file_put_contents($logFile, '');
        header('Location: /admin/logs?cleared=1');
        exit;
    }

    // Download log
    if (isset($_GET['action']) && $_GET['action'] === 'download') {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="error.log"');
        readfile($logFile);
        exit;
    }

    // AJAX Load (live refresh)
    if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
        $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $filteredLogs = array_slice($logs, -1000);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['logs' => $filteredLogs], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo $twig->render('errors/error_logs.twig', [
        'title'        => 'Error Logs',
        'header_title' => '🧠 Error Log Viewer',
        'cleared'      => isset($_GET['cleared']),
    ]);
}


// Routes
global $router;
$router->get('/dashboard', 'showDashboard');
$router->get('/chart-data', 'getCertificateData');
$router->get('/chart-data-monthly', 'getMonthlyCertificateData');
$router->get('/chart-data-monthly-status', 'getMonthlyStatusData');
$router->get('/certificate-search', 'certificateSearchController');
$router->get('/admin/logs', 'showErrorLogs');

