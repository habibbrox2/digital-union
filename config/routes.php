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

    if (!function_exists('processForm')) {
        renderError(500, "processForm() ফাংশন পাওয়া যায়নি।");
    }

    $processed = processForm($_POST);

    if ($processed === false) {
        renderError(403, "CSRF Token validation ব্যর্থ হয়েছে।");
    }

    // Sanitize processed data
    foreach ($processed as $key => $value) {
        if (is_string($value)) {
            $processed[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        }
    }

    // Response (example)
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'data'   => $processed
    ]);
});
