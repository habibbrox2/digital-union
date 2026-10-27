<?php
/**
 * bootstrap/autoload.php
 * ======================
 * Autoloading + configuration bootstrap for the restructured project.
 *
 * Replaces the old inline autoloader in public/index.php and the old
 * config/autoload.php bulk-loader.
 */

// ----------------------------
// 1. Composer dependencies (require_once => idempotent)
// ----------------------------
require_once __DIR__ . '/../vendor/autoload.php';

// ----------------------------
// 2. Application class autoloader
//     MUST be registered before the config files are loaded, because
//     e.g. TwigManager/PermissionsManager are needed during Twig setup.
// ----------------------------
spl_autoload_register(function ($className) {
    $relative = str_replace('\\', '/', $className) . '.php';

    $dirs = [
        __DIR__ . '/../app/Core',
        __DIR__ . '/../app/Models',
        __DIR__ . '/../app/Services',
        __DIR__ . '/../app/Helpers',
        __DIR__ . '/../app/Http/Controllers',
    ];

    foreach ($dirs as $dir) {
        $file = $dir . '/' . $relative;
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    // Case-insensitive fallback (handles Linux case-sensitivity)
    foreach (glob(__DIR__ . '/../app/Models/*.php') as $f) {
        if (strcasecmp(basename($f, '.php'), $className) === 0) {
            require_once $f;
            return;
        }
    }
    foreach (glob(__DIR__ . '/../app/Helpers/*.php') as $f) {
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
// 3. Helper files define plain global functions (not classes), so the
//     autoloader never fires for them - load them eagerly as before.
// ----------------------------
foreach (glob(__DIR__ . '/../app/Helpers/*.php') as $file) {
    require_once $file;
}

// ----------------------------
// 4. Load config files (top level only, same as before)
// ----------------------------
foreach (glob(__DIR__ . '/../config/*.php') as $file) {
    require_once $file;
}
