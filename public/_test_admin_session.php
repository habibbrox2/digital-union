<?php
// TEMPORARY test helper — seeds a superadmin session for admin API tests.
// MUST be deleted before deployment.
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
header('Content-Type: application/json');
echo json_encode(['session' => session_id(), 'csrf_token' => $_SESSION['csrf_token']]);
