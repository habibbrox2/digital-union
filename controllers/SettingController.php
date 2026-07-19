<?php
/**
 * controllers/SettingController.php
 * 
 * Settings management routes - uses SettingService for all business logic.
 * No helper functions or DB logic in this controller.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);

// ================================================================
// GET : Main Settings Page
// ================================================================
$router->get('/settings', function () use ($twig, $mysqli, $authService) {
    $authService->ensureAdminOrCan('manage_settings');

    $settingService = new SettingService($mysqli);
    $unionModel = new UnionModel($mysqli);
    $userModel = new UserModel($mysqli);
    $auth = new AuthManager($mysqli);

    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;
    if (!$userId) {
        renderError(403, "Unauthorized access!");
    }

    $profileData = $userModel->getById($userId);
    $systemSettings = $settingService->getSystemSettings();

    $unionSettings = [];
    if (!empty($profileData['union_id'])) {
        $unionSettings = $unionModel->getById($profileData['union_id']) ?: [];
    }

    $unions = $unionModel->getAllUnions();

    echo $twig->render('settings/setting.twig', [
        'title'            => 'Settings',
        'system_settings'  => $systemSettings,
        'union_settings'   => $unionSettings,
        'unions'           => $unions,
        'header_title'     => 'System & Union Settings',
    ]);
});

// ================================================================
// POST : Update System Settings
// ================================================================
$router->post('/settings/system', function () use ($mysqli, $authService) {
    $authService->ensureAdminOrCan('manage_settings');
    header('Content-Type: application/json');

    $service = new SettingService($mysqli);

    // Handle logo upload
    if (!empty($_FILES['organization_logo']['name'])) {
        $result = $service->uploadOrganizationLogo($_FILES['organization_logo']);
        if ($result['status'] !== 'success') {
            echo json_encode(['status' => 'error', 'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => $result['message']]]);
            exit;
        }
    }

    // Handle text settings
    $settings = $_POST['settings'] ?? [];
    if (!empty($settings)) {
        $result = $service->updateSystemSettings($settings);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['status' => 'success', 'alert' => ['type' => 'success', 'title' => 'সাফল্য', 'message' => 'সিস্টেম সেটিংস সফলভাবে আপডেট হয়েছে']]);
});

// ================================================================
// POST : Update Union Settings
// ================================================================
$router->post('/settings/union', function () use ($mysqli, $authService) {
    $authService->ensureAdminOrCan('manage_settings');
    header('Content-Type: application/json');

    $service = new SettingService($mysqli);
    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        echo json_encode(['status' => 'error', 'alert' => ['type' => 'error', 'title' => 'নিরাপত্তা', 'message' => 'অনুমতি প্রয়োজন']]);
        exit;
    }

    $userModel = new UserModel($mysqli);
    $profile = $userModel->getById($userId);
    $unionId = intval($_POST['union_id'] ?? $profile['union_id'] ?? 0);

    if (!$unionId) {
        echo json_encode(['status' => 'error', 'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => 'ইউনিয়ন পাওয়া যায়নি']]);
        exit;
    }

    // Load existing union data to preserve values not sent via form
    $unionModel = new UnionModel($mysqli);
    $existingUnion = $unionModel->getById($unionId);

    $data = array_map('sanitize_input', $_POST);
    $data['logo_url'] = $existingUnion['logo_url'] ?? $profile['logo_url'] ?? '';
    $data['stamp_logo_url'] = $existingUnion['stamp_logo_url'] ?? '';

    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email']);
        exit;
    }

    // Handle logo upload
    if (!empty($_FILES['union_logo']['name'])) {
        $uploadResult = $service->uploadUnionLogo($_FILES['union_logo'], $data['union_code'] ?? 'union', $unionId);
        if ($uploadResult['status'] === 'success') {
            $data['logo_url'] = $uploadResult['path'];
        }
    }

    // Handle stamp logo upload
    if (!empty($_FILES['union_stamp_logo']['name'])) {
        $uploadResult = $service->uploadUnionStampLogo($_FILES['union_stamp_logo'], $data['union_code'] ?? 'union', $unionId);
        if ($uploadResult['status'] === 'success') {
            $data['stamp_logo_url'] = $uploadResult['path'];
        }
    }

    $result = $service->updateUnionSettings($unionId, $data);
    echo json_encode($result);
});

// ================================================================
// POST : Get Union Data
// ================================================================
$router->any('/settings/get_union_data', function () use ($mysqli) {
    header('Content-Type: application/json');

    $unionId = filter_input(INPUT_POST, 'union_id', FILTER_VALIDATE_INT);
    if (!$unionId) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Union ID']);
        exit;
    }

    $unionModel = new UnionModel($mysqli);
    $union = $unionModel->getById($unionId);

    if ($union) {
        echo json_encode(['status' => 'success', 'data' => $union]);
    } else {
        echo json_encode(['status' => 'error', 'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => 'ইউনিয়ন পাওয়া যায়নি']]);
    }
    exit;
});

// ================================================================
// Security Settings
// ================================================================
$router->get('/settings/security', function () use ($twig, $mysqli, $authService) {
    $authService->ensureAdminOrCan('manage_settings');

    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        renderError(403, "Unauthorized access!");
    }

    $service = new SettingService($mysqli);
    $keys = ['password_policy', 'two_factor_enabled', 'session_timeout_minutes'];
    $securitySettings = $service->getSystemSettingsByKeys($keys);

    echo $twig->render('settings/security.twig', [
        'title'             => 'Security Settings',
        'security_settings' => $securitySettings,
        'header_title'      => 'Security Settings'
    ]);
});

$router->post('/settings/security', function () use ($mysqli) {
    header('Content-Type: application/json');

    $settings = $_POST['settings'] ?? [];
    $service = new SettingService($mysqli);
    $result = $service->updateSecuritySettings($settings);

    echo json_encode($result);
});

// ================================================================
// Notification Settings
// ================================================================
$router->get('/settings/notifications', function () use ($twig, $mysqli, $authService) {
    $authService->ensureAdminOrCan('manage_settings');

    $auth = new AuthManager($mysqli);
    $user = $auth->getUserData(false);
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        echo "Unauthorized access!";
        exit;
    }

    $service = new SettingService($mysqli);
    $keys = ['email_notifications_enabled', 'sms_notifications_enabled', 'push_notifications_enabled'];
    $notificationSettings = $service->getSystemSettingsByKeys($keys);

    echo $twig->render('settings/notifications.twig', [
        'title'                  => 'Notification Settings',
        'notification_settings'  => $notificationSettings,
        'header_title'           => 'Notification Settings'
    ]);
});

// ================================================================
// Email Templates
// ================================================================
$router->get('/settings/email-templates', function () use ($twig, $mysqli, $authService) {
    $authService->ensureAdminOrCan('manage_settings');

    $service = new SettingService($mysqli);
    $keys = [
        'email_template_welcome', 'email_template_email_verification',
        'email_template_password_reset', 'email_template_password_changed',
        'email_template_failed_login_alert', 'email_template_new_device_login'
    ];
    $systemSettings = $service->getSystemSettingsByKeys($keys);

    echo $twig->render('settings/email_templates.twig', [
        'title'           => 'Email Templates',
        'system_settings' => $systemSettings,
        'header_title'    => 'Email Templates',
        'csrf_token'      => generateCsrfToken()
    ]);
});

$router->post('/settings/email-templates', function () use ($mysqli, $authService) {
    $authService->ensureAdminOrCan('manage_settings');
    header('Content-Type: application/json');

    $templates = $_POST['templates'] ?? [];
    $service = new SettingService($mysqli);
    $result = $service->saveEmailTemplates($templates);

    echo json_encode($result);
});

$router->post('/settings/email-templates/preview', function () use ($mysqli, $authService) {
    $authService->ensureAdminOrCan('manage_settings');
    header('Content-Type: application/json; charset=utf-8');

    $name = sanitize_input($_POST['name'] ?? '');
    $service = new SettingService($mysqli);
    $result = $service->previewEmailTemplate($name);

    if (!$result['success']) {
        http_response_code(400);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
});
