<?php
// config/routes.php

if (!isset($router) || $router === null) {
    return;
}


/*
|--------------------------------------------------------------------------
| GET : Friendly URL under /applications
|--------------------------------------------------------------------------
*/
$router->get('/applications/{friendly_url}', function ($friendly_url) {

    if (!$friendly_url) {
        renderError(404, "পৃষ্ঠাটি পাওয়া যায়নি");
    }

    echo "Requested page: " . htmlspecialchars($friendly_url, ENT_QUOTES, 'UTF-8');
});

/*
|--------------------------------------------------------------------------
| GET : Global Friendly URL
|--------------------------------------------------------------------------
*/
$router->get('/{friendly_url}', function ($friendly_url) {

    if (!$friendly_url) {
        renderError(404, "পৃষ্ঠাটি পাওয়া যায়নি");
    }

    echo "Requested page: " . htmlspecialchars($friendly_url, ENT_QUOTES, 'UTF-8');
});

/*
|--------------------------------------------------------------------------
| POST : CSRF Protected Endpoint
|--------------------------------------------------------------------------
*/
$router->post('/csrf', function () {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        renderError(405, "শুধুমাত্র POST অনুরোধ অনুমোদিত।");
    }

    // CSRF is auto-verified by autoVerifyCsrf() from config/csrf.php
    // Sanitize the incoming data using the already-available sanitizeRequest()
    if (!function_exists('sanitizeRequest')) {
        renderError(500, "sanitizeRequest() ফাংশন পাওয়া যায়নি।");
    }

    $data = $_POST;
    unset($data['csrf_token']);
    $processed = sanitizeRequest($data);

    // Response (example)
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'data'   => $processed
    ]);
});
