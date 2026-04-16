<?php
/**
 * Email Controller php
 * Use this controller to test email functionality
 * 
 * Routes:
 * GET  /settings/email-test                 - Show test interface
 * POST /settings/email-test/send-welcome    - Test welcome email
 * POST /settings/email-test/send-reset      - Test password reset email
 * POST /settings/email-test/send-changed    - Test password changed email
 * POST /settings/email-test/send-failed     - Test failed login alert
 * POST /settings/email-test/send-device     - Test new device alert
 * GET  /settings/email-test/verify          - Verify SMTP connection
 * GET  /settings/email-test/logs            - View email logs
 */

/**
 * Display email test interface
 */
global $router;

// Show email test interface
$router->get('/settings/email-test', function() {
    ensure_admin_or_can('manage_settings');
    global $twig, $mysqli;

    $configExists = defined('SMTP_HOST');
    $logsDir = defined('EMAIL_LOG_DIR') ? EMAIL_LOG_DIR : '';
    $logsExist = !empty($logsDir) && is_dir($logsDir);

    echo $twig->render('emails/email_test.twig', [
        'title'        => 'Email Test',
        'header_title' => 'ইমেইল টেস্ট সিস্টেম',
        'configExists' => $configExists,
        'logsExist'    => $logsExist,
        'logsDir'      => $logsDir,
        'smtpHost'     => defined('SMTP_HOST') ? SMTP_HOST : 'Not configured',
        'smtpPort'     => defined('SMTP_PORT') ? SMTP_PORT : 'Not configured',
    ]);
});

// Send welcome email
$router->post('/settings/email-test/send-welcome', function() {
    ensure_admin_or_can('manage_settings');
    header('Content-Type: application/json; charset=utf-8');

    $email = $_POST['email'] ?? '';
    $username = $_POST['username'] ?? '';

    if (!$email || !$username) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and username required']);
        return;
    }

    try {
        $result = sendWelcomeEmail($email, $username);

        if ($result) {
            echo json_encode([
                'success'   => true,
                'message'   => "Welcome email sent to: $email",
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to send welcome email']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
    }
});

// Send password reset email
$router->post('/settings/email-test/send-reset', function() {
    ensure_admin_or_can('manage_settings');
    header('Content-Type: application/json; charset=utf-8');

    $email = $_POST['email'] ?? '';
    $username = $_POST['username'] ?? '';

    if (!$email || !$username) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and username required']);
        return;
    }

    try {
        $token = bin2hex(random_bytes(32));
        $resetLink = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://".$_SERVER['HTTP_HOST']."/reset-password?token=".urlencode($token);

        $result = sendPasswordResetEmail($email, $username, $resetLink);

        if ($result) {
            echo json_encode([
                'success'   => true,
                'message'   => "Password reset email sent to: $email",
                'token'     => substr($token, 0, 10).'...',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to send password reset email']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
    }
});

// Send password changed email
$router->post('/settings/email-test/send-changed', function() {
    ensure_admin_or_can('manage_settings');
    header('Content-Type: application/json; charset=utf-8');

    $email = $_POST['email'] ?? '';
    $username = $_POST['username'] ?? '';

    if (!$email || !$username) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and username required']);
        return;
    }

    try {
        $result = sendPasswordChangedEmail($email, $username);

        if ($result) {
            echo json_encode([
                'success'   => true,
                'message'   => "Password changed confirmation sent to: $email",
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to send email']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
    }
});

// Send failed login alert
$router->post('/settings/email-test/send-failed', function() {
    ensure_admin_or_can('manage_settings');
    header('Content-Type: application/json; charset=utf-8');

    $email = $_POST['email'] ?? '';
    $username = $_POST['username'] ?? '';
    $attempts = (int)($_POST['attempts'] ?? 3);

    if (!$email || !$username) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and username required']);
        return;
    }

    try {
        $result = sendFailedLoginAlert($email, $username, $attempts, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        if ($result) {
            echo json_encode([
                'success'   => true,
                'message'   => "Failed login alert sent to: $email ($attempts attempts)",
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to send email']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
    }
});

// Send new device login alert
$router->post('/settings/email-test/send-device', function() {
    ensure_admin_or_can('manage_settings');
    header('Content-Type: application/json; charset=utf-8');

    $email = $_POST['email'] ?? '';
    $username = $_POST['username'] ?? '';

    if (!$email || !$username) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and username required']);
        return;
    }

    try {
        $loginData = [
            'device'    => $_POST['device'] ?? 'Unknown Device',
            'browser'   => $_POST['browser'] ?? 'Unknown Browser',
            'ip_address'=> $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'location'  => $_POST['location'] ?? 'Unknown Location'
        ];

        $result = sendNewDeviceLoginAlert($email, $username, $loginData);

        if ($result) {
            echo json_encode([
                'success'   => true,
                'message'   => "New device alert sent to: $email",
                'device'    => $loginData['device'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to send email']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
    }
});

// Verify SMTP connection
$router->get('/settings/email-test/verify', function() {
    ensure_admin_or_can('manage_settings');
    header('Content-Type: application/json; charset=utf-8');

    try {
        $result = verifyEmailConnection();

        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => '✓ SMTP Connection Successful',
                'details' => [
                    'host'       => defined('SMTP_HOST') ? SMTP_HOST : 'Not configured',
                    'port'       => defined('SMTP_PORT') ? SMTP_PORT : 'Not configured',
                    'encryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'Not configured',
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => '✗ SMTP Connection Failed', 'error' => $result['message'] ?? 'Unknown error']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Verification Error', 'error' => $e->getMessage()]);
    }
});

// View email logs
$router->get('/settings/email-test/logs', function() {
    ensure_admin_or_can('manage_settings');

    if (!defined('EMAIL_LOG_DIR') || !is_dir(EMAIL_LOG_DIR)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Log directory not found']);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');

    $todayLog = EMAIL_LOG_DIR.'/email_'.date('Y-m-d').'.log';
    $logs = [];

    if (file_exists($todayLog)) {
        $lines = file($todayLog, FILE_IGNORE_NEW_LINES);
        $logs = array_slice($lines, -50);
    }

    echo json_encode([
        'success'   => true,
        'logFile'   => $todayLog,
        'timestamp' => date('Y-m-d H:i:s'),
        'lineCount' => count($logs),
        'logs'      => $logs
    ]);
});
