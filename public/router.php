<?php
// public/router.php — router script for PHP's built-in web server.
// Mirrors public/.htaccess: serve real files/dirs directly, route the rest
// through index.php so clean URLs (e.g. /login, /dashboard) keep working.
//
// Usage: php -S 0.0.0.0:8000 public/router.php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// Real files and directories are served as-is (correct MIME types included).
if ($path !== '/' && (is_file($file))) {
    return false;
}

// Root request: let the built-in server find index.php itself.
if ($path === '/') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

// Everything else goes through the front controller.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
return true;
