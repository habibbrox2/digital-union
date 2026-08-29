<?php
// Simple router: serves public/ assets and scripts/test_datepicker_modal.html
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$public = __DIR__ . '/../public';
$file = $public . $path;
if ($path !== '/' && is_file($file)) {
    // Serve from public
    return false;
}
// Also allow /scripts/test_datepicker_modal.html
$scriptFile = __DIR__ . '/test_datepicker_modal.html';
if ($path === '/scripts/test_datepicker_modal.html' && is_file($scriptFile)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($scriptFile);
    exit;
}
// Fallback
http_response_code(404);
echo 'Not Found';
