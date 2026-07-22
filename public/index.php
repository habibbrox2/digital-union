<?php
// public/index.php
// ============================

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
require_once __DIR__ . '/../models/ErrorHandler.php';
ErrorHandler::init($logFile, $showErrors, null, [
    'route' => $logFiles['route'],
    'twig'  => $logFiles['twig'],
    'fatal' => $logFiles['fatal'],
    '404'   => $logFiles['404'],
    'http'  => $logFiles['http'],
]);

// ----------------------------
// 0a. Global Error/Exception/Shutdown Handlers
//     (Centralized in config/error.php — loaded via glob below)
// ----------------------------
// set_error_handler(), set_exception_handler(), register_shutdown_function()
// are all registered in config/error.php using ErrorHandler class methods.

// ----------------------------
// 1. Autoloader (PSR-4 style) — MUST be registered before loading config files
//     because models like PermissionsManager are needed during TwigManager setup.
// ----------------------------
spl_autoload_register(function ($className) {
    // Search in classes/ directory first
    $classFile = __DIR__ . '/../models/' . str_replace('\\', '/', $className) . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
        return;
    }

    // Then search in modules/Services/ directory
    $serviceFile = __DIR__ . '/../modules/Services/' . str_replace('\\', '/', $className) . '.php';
    
    if (file_exists($serviceFile)) {
        require_once $serviceFile;
        return;
    }

    // Then search in helpers/ directory (case-insensitive on Linux)
    $helperFile = __DIR__ . '/../helpers/' . str_replace('\\', '/', $className) . '.php';
    if (file_exists($helperFile)) {
        require_once $helperFile;
        return;
    }
    // Fallback: case-insensitive glob scan for helpers (handles Linux case-sensitivity)
    foreach (glob(__DIR__ . '/../helpers/*.php') as $f) {
        if (strcasecmp(basename($f, '.php'), $className) === 0) {
            require_once $f;
            return;
        }
    }

    error_log("Class not found: {$className}");
    if (function_exists('renderError')) {
        renderError(500, "সার্ভার ত্রুটি: '{$className}' ক্লাস পাওয়া যায়নি।");
    } else {
        http_response_code(500);
        echo "<h3>ক্লাস '{$className}' পাওয়া যায়নি</h3>";
    }
    exit;
});

// ----------------------------
// 2. Load Config Files
// ----------------------------
foreach (glob(__DIR__ . '/../config/*.php') as $file) {
    require_once $file;
}

// ----------------------------
// 2a. Setup Migration Permissions (RBAC)
// ----------------------------
require_once __DIR__ . '/../helpers/migration_helper.php';
if (function_exists('setupMigrationPermissions')) {
    setupMigrationPermissions($mysqli);
}

// ----------------------------
// 3. Initialize Router
// ----------------------------
if (!class_exists('Router')) {
    renderError(500, "সার্ভার ত্রুটি: Router ক্লাস পাওয়া যায়নি।");
    exit;
}

$router = new Router();

// ----------------------------
// 4. Load Route Definitions (New System)
// ----------------------------
foreach (glob(__DIR__ . '/../controllers/*.php') as $controllerFile) {
    require_once $controllerFile;
}

// Now that $router is initialized and controllers are loaded, include
// route files that register routes via $router (they were deferred above).
$deferred = [__DIR__ . '/../config/routes.php', __DIR__ . '/../config/error.php'];
foreach ($deferred as $f) {
    if (file_exists($f)) require_once $f;
}

// ----------------------------
// 5. Request Info
// ----------------------------
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$requestUri    = '/' . trim($requestUri, '/');
if ($requestUri === '//') $requestUri = '/';

// ----------------------------
// 6. Dispatch Request (Only New Router)
// ----------------------------
try {
    $router->dispatch($requestMethod, $requestUri);

} catch (RouteNotFoundException $e) {

    // Log 404 to dedicated route-404.log
    if (class_exists('ErrorHandler')) {
        ErrorHandler::logRoute404($requestMethod, $requestUri);
    }

    renderError(404, "<h3>404 ত্রুটি</h3><p>পৃষ্ঠাটি পাওয়া যায়নি</p>");

} catch (MethodNotAllowedException $e) {

    renderError(405, "<h3>405 ত্রুটি</h3><p>এই Method অনুমোদিত নয়</p>");

} catch (Throwable $e) {

    error_log("Router Error: {$e->getMessage()}");
    renderError(500, "সার্ভার ত্রুটি: একটি অপ্রত্যাশিত সমস্যা হয়েছে।");
}
