<?php
// public/index.php
// ============================
// Web entry point: sets up error handling, then hands over to bootstrap/.

// ----------------------------
// 0. Error Handling Configuration
// ----------------------------
// 🔒 PRODUCTION: Always false (hides error details from users, display_errors=0)
//    DEVELOPMENT: Set to true  (shows error details, display_errors=1)
//
// Note: $_ENV['APP_ENV'] from .env is NOT available here yet
//       because config.php (which loads dotenv) runs later via glob().
//       Change this to true for local development only.
$showErrors = false;
$logDir = __DIR__ . '/../storage/logs';
$logFile = $logDir . '/error.log';
$fallbackLogFile = $logDir . '/bdris_log.txt';

// Ensure log directory exists
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Create all separate log files
$logFiles = [
    'error' => $logFile,
    'route' => $logDir . '/route-error.log',
    'twig'  => $logDir . '/twig-error.log',
    'fatal' => $logDir . '/fatal-error.log',
    '404'   => $logDir . '/route-404.log',
    'http'  => $logDir . '/http-error.log',
];

foreach ($logFiles as $path) {
    if (!file_exists($path)) {
        @touch($path);
    }
}

// Main error.log writability check (fallback to bdris_log.txt)
if (!is_writable($logFile)) {
    $logFile = $fallbackLogFile;
    if (!file_exists($logFile)) {
        @touch($logFile);
    }
}

$htaccess = $logDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
}

ini_set('log_errors', '1');
ini_set('error_log', $logFile);

if ($showErrors) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    // Log all errors except notices and deprecations
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// Load ErrorHandler class early
require_once __DIR__ . '/../app/Models/ErrorHandler.php';
ErrorHandler::init($logFile, $showErrors, null, [
    'route' => $logFiles['route'],
    'twig'  => $logFiles['twig'],
    'fatal' => $logFiles['fatal'],
    '404'   => $logFiles['404'],
    'http'  => $logFiles['http'],
]);

// ----------------------------
// 0a. Global Error/Exception/Shutdown Handlers
//     (Centralized in config/error.php — loaded by bootstrap/autoload.php)
// ----------------------------
// set_error_handler(), set_exception_handler(), register_shutdown_function()
// are all registered in config/error.php using ErrorHandler class methods.

// ----------------------------
// 1. Autoloader + config files (bootstrap/autoload.php)
// ----------------------------
require_once __DIR__ . '/../bootstrap/autoload.php';

// ----------------------------
// 2. Router, routes and dispatch (bootstrap/app.php)
// ----------------------------
require_once __DIR__ . '/../bootstrap/app.php';
