<?php
/**
 * bootstrap/app.php
 * =================
 * Builds the router, registers every route and dispatches the request.
 * Included at the end of public/index.php, so everything here runs in the
 * global scope (controllers rely on `global $router, $twig, $mysqli`).
 */

// ----------------------------
// 1. Initialize Router
// ----------------------------
if (!class_exists('Router')) {
    renderError(500, "সার্ভার ত্রুটি: Router ক্লাস পাওয়া যায়নি।");
    exit;
}

$router = new Router();

// ----------------------------
// 2. Load route definitions (controllers register their routes)
// ----------------------------
foreach (glob(__DIR__ . '/../app/Http/Controllers/*.php') as $controllerFile) {
    require_once $controllerFile;
}

// Now that $router is initialized and controllers are loaded, include
// route files that register routes via $router (they were deferred above).
$deferred = [__DIR__ . '/../routes/web.php', __DIR__ . '/../config/error.php'];
foreach ($deferred as $f) {
    if (file_exists($f)) require_once $f;
}

// ----------------------------
// 3. Request Info
// ----------------------------
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$requestUri    = '/' . trim($requestUri, '/');
if ($requestUri === '//') $requestUri = '/';

// ----------------------------
// 4. Dispatch Request (Only New Router)
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
