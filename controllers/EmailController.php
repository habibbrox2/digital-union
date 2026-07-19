<?php
/**
 * controllers/EmailController.php
 * 
 * Email test routes - pure closures using EmailTestService.
 * No inline email sending logic, no helper function calls.
 * All email test logic is in modules/Services/EmailTestService.php.
 */

global $router, $mysqli, $twig;

$authService = new AuthService($mysqli);
$emailTestService = new EmailTestService();

// ================================================================
// GET : Show email test interface
// ================================================================
$router->get('/settings/email-test', function() use ($twig, $authService, $emailTestService) {
    $authService->ensureAdminOrCan('manage_settings');

    $config = $emailTestService->getSmtpConfig();

    echo $twig->render('emails/email_test.twig', [
        'title'        => 'Email Test',
        'header_title' => 'ইমেইল টেস্ট সিস্টেম',
        'configExists' => $config['configExists'],
        'logsExist'    => !empty($config['logsDir']) && is_dir($config['logsDir']),
        'logsDir'      => $config['logsDir'],
        'smtpHost'     => $config['host'],
        'smtpPort'     => $config['port'],
    ]);
});

// ================================================================
// POST : Send welcome email
// ================================================================
$router->post('/settings/email-test/send-welcome', function() use ($authService, $emailTestService) {
    $authService->ensureAdminOrCan('manage_settings');

    $error = $emailTestService->validateEmailInput($_POST);
    if ($error) {
        $emailTestService->sendJsonResponse(['success' => false, 'message' => $error]);
        return;
    }

    $emailTestService->sendJsonResponse($emailTestService->sendWelcome($_POST['email'], $_POST['username']));
});

$router->post('/settings/email-test/send-reset', function() use ($authService, $emailTestService) {
    $authService->ensureAdminOrCan('manage_settings');

    $error = $emailTestService->validateEmailInput($_POST);
    if ($error) {
        $emailTestService->sendJsonResponse(['success' => false, 'message' => $error]);
        return;
    }

    $emailTestService->sendJsonResponse($emailTestService->sendPasswordReset($_POST['email'], $_POST['username']));
});

$router->post('/settings/email-test/send-changed', function() use ($authService, $emailTestService) {
    $authService->ensureAdminOrCan('manage_settings');

    $error = $emailTestService->validateEmailInput($_POST);
    if ($error) {
        $emailTestService->sendJsonResponse(['success' => false, 'message' => $error]);
        return;
    }

    $emailTestService->sendJsonResponse($emailTestService->sendPasswordChanged($_POST['email'], $_POST['username']));
});

$router->post('/settings/email-test/send-failed', function() use ($authService, $emailTestService) {
    $authService->ensureAdminOrCan('manage_settings');

    $error = $emailTestService->validateEmailInput($_POST, ['email', 'username']);
    if ($error) {
        $emailTestService->sendJsonResponse(['success' => false, 'message' => $error]);
        return;
    }

    $attempts = (int)($_POST['attempts'] ?? 3);
    $emailTestService->sendJsonResponse($emailTestService->sendFailedLoginAlert($_POST['email'], $_POST['username'], $attempts));
});

$router->post('/settings/email-test/send-device', function() use ($authService, $emailTestService) {
    $authService->ensureAdminOrCan('manage_settings');

    $error = $emailTestService->validateEmailInput($_POST);
    if ($error) {
        $emailTestService->sendJsonResponse(['success' => false, 'message' => $error]);
        return;
    }

    $emailTestService->sendJsonResponse($emailTestService->sendNewDeviceAlert($_POST['email'], $_POST['username'], $_POST));
});

$router->get('/settings/email-test/verify', function() use ($authService, $emailTestService) {
    $authService->ensureAdminOrCan('manage_settings');
    $emailTestService->sendJsonResponse($emailTestService->verifyConnection());
});

// ================================================================
// GET : View email logs
// ================================================================
$router->get('/settings/email-test/logs', function() use ($authService, $emailTestService) {
    $authService->ensureAdminOrCan('manage_settings');

    $result = $emailTestService->getLogs();
    if (!$result['success']) {
        http_response_code(404);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
});
