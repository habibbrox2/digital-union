<?php
// public/index.php
// ============================

// ----------------------------
// 0. Error Handling Configuration
// ----------------------------
$showErrors = true; // production = false, development = true
$logDir = __DIR__ . '/../storage/logs';
$logFile = $logDir . '/error.log';
$fallbackLogFile = $logDir . '/bdris_log.txt';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

if (!file_exists($logFile)) {
    @touch($logFile);
}
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
require_once __DIR__ . '/../classes/ErrorHandler.php';
ErrorHandler::init($logFile, $showErrors);

// ----------------------------
// 0a. Custom Error & Exception Handlers
// ----------------------------
set_error_handler(function ($severity, $message, $file, $line) use ($logFile) {
    if (!(error_reporting() & $severity)) {
        return;
    }

    error_log(
        "[" . date('Y-m-d H:i:s') . "] $message in $file on line $line\n",
        3,
        $logFile
    );
    return true;
});

set_exception_handler(function ($exception) use ($logFile, $showErrors) {
    error_log(
        "[" . date('Y-m-d H:i:s') . "] Uncaught Exception: "
        . $exception->getMessage() . " in "
        . $exception->getFile() . " on line "
        . $exception->getLine() . "\n",
        3,
        $logFile
    );

    http_response_code(500);
    if (function_exists('renderError')) {
        renderError(500, "সার্ভার ত্রুটি: একটি অপ্রত্যাশিত ব্যতিক্রম ঘটেছে।");
    } else {
        echo "<h1>500 Internal Server Error</h1>";
        if ($showErrors) {
            echo "<pre>{$exception->getMessage()}</pre>";
        }
    }
    exit;
});

// ----------------------------
// 0b. Fatal Shutdown Handler
// ----------------------------
register_shutdown_function(function () use ($logFile, $showErrors) {
    $error = error_get_last();

    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        
        // Use ErrorHandler if available
        if (class_exists('ErrorHandler')) {
            ErrorHandler::handleFatalError($error);
        } else {
            error_log(
                "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: "
                . $error['message'] . " in "
                . $error['file'] . " on line "
                . $error['line'] . "\n",
                3,
                $logFile
            );

            if (!headers_sent()) {
                http_response_code(500);
            }

            if (ob_get_length() === 0) {
                if (function_exists('renderError')) {
                    renderError(500, "সার্ভার ত্রুটি: একটি মারাত্মক সমস্যা হয়েছে।");
                } else {
                    echo "<h1>500 Internal Server Error</h1>";
                    if ($showErrors) {
                        echo "<pre>{$error['message']}</pre>";
                    }
                }
            }
        }
    }
});

// ----------------------------
// 1. Load Config Files
// ----------------------------
foreach (glob(__DIR__ . '/../config/*.php') as $file) {
    require_once $file;
}

// ----------------------------
// 1a. Setup Migration Permissions (RBAC)
// ----------------------------
require_once __DIR__ . '/../helpers/migration_helper.php';
if (function_exists('setupMigrationPermissions')) {
    setupMigrationPermissions($mysqli);
}

// ----------------------------
// 2. Autoloader (PSR-4 style)
// ----------------------------
spl_autoload_register(function ($className) {
    $classFile = __DIR__ . '/../classes/' . str_replace('\\', '/', $className) . '.php';

    if (!file_exists($classFile)) {
        error_log("Class not found: {$className}");
        if (function_exists('renderError')) {
            renderError(500, "সার্ভার ত্রুটি: '{$className}' ক্লাস পাওয়া যায়নি।");
        } else {
            http_response_code(500);
            echo "<h3>ক্লাস '{$className}' পাওয়া যায়নি</h3>";
        }
        exit;
    }

    require_once $classFile;
});

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

    renderError(404, "<h3>404 ত্রুটি</h3><p>পৃষ্ঠাটি পাওয়া যায়নি</p>");

} catch (MethodNotAllowedException $e) {

    renderError(405, "<h3>405 ত্রুটি</h3><p>এই Method অনুমোদিত নয়</p>");

} catch (Throwable $e) {

    error_log("Router Error: {$e->getMessage()}");
    renderError(500, "সার্ভার ত্রুটি: একটি অপ্রত্যাশিত সমস্যা হয়েছে।");
}
