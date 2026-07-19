<?php
/**
 * controllers/GeoController.php
 * 
 * Geographic data management routes.
 * All business logic is handled by GeoService.
 * No inline curl, no inline DB queries, no closure helpers.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);
$geoService = new GeoService($mysqli);

// ================================================================
// GET : Geo management pages
// ================================================================
$router->get('/settings/geo', function() use ($twig, $authService) {
    $authService->ensureCan('manage_geo');
    echo $twig->render('geo/index.twig', [
        'title' => 'জিইও',
        'header_title' => 'জিইও',
        'status' => '',
        'message' => '',
    ]);
});

$router->get('/geo', function() use ($twig, $authService) {
    $authService->ensureCan('manage_geo');
    echo $twig->render('geo/index.twig', [
        'title' => 'জিইও',
        'header_title' => 'জিইও',
        'status' => '',
        'message' => '',
    ]);
});

// ================================================================
// POST : Store / Import Geo data from URL
// ================================================================
$router->post('/geo/store', function() use ($geoService, $authService) {
    $authService->ensureCan('manage_geo');
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_POST['xml_url']) || !filter_var($_POST['xml_url'], FILTER_VALIDATE_URL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid XML URL or data.']);
        return;
    }

    $result = $geoService->fetchAndImportGeo(filter_var($_POST['xml_url'], FILTER_SANITIZE_URL));
    echo json_encode($result);
});

// ================================================================
// POST : Get Geo Data
// ================================================================
$router->post('/settings/geo/getdata', function() use ($geoService) {
    header('Content-Type: application/json');

    // Validate referer to prevent direct access
    $validReferer = (parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) === $_SERVER['HTTP_HOST']);
    if (!$validReferer) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        return;
    }

    $geoOrder = intval($_POST['geo_order'] ?? 0);
    $parentGeoId = intval($_POST['parent_geo_id'] ?? 0);

    $result = $geoService->getGeoData($geoOrder, $parentGeoId);
    echo json_encode($result);
});

$router->post('/geo/getdata', function() use ($geoService) {
    header('Content-Type: application/json');

    $geoOrder = intval($_POST['geo_order'] ?? 0);
    $parentGeoId = intval($_POST['parent_geo_id'] ?? 0);

    $result = $geoService->getGeoData($geoOrder, $parentGeoId);
    echo json_encode($result);
});

// ================================================================
// POST : Get Geo By Type
// ================================================================
$router->post('/geo/getByType', function() use ($geoService) {
    header('Content-Type: application/json');

    $geoOrder = intval($_POST['geo_order'] ?? 0);
    $parentGeoId = intval($_POST['parent_geo_id'] ?? 0);
    $searchTerm = trim($_POST['search'] ?? '');

    $result = $geoService->getGeoByType($geoOrder, $parentGeoId, $searchTerm);
    echo json_encode($result);
});

// ================================================================
// POST : Get Union
// ================================================================
$router->post('/geo/getUnion', function() use ($geoService) {
    header('Content-Type: application/json');

    $districtNameEn = sanitize_input($_POST['district_name_en'] ?? '');
    $upazilaNameEn = sanitize_input($_POST['upazila_name_en'] ?? '');

    if (!$districtNameEn || !$upazilaNameEn) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => 'জেলার এবং থানার নাম প্রয়োজন']
        ]);
        return;
    }

    $unions = $geoService->getUnionByDistrict($districtNameEn, $upazilaNameEn);
    echo json_encode(['status' => 'success', 'data' => $unions]);
});

$router->post('/geo/geoUnion', function() use ($geoService) {
    header('Content-Type: application/json');

    $upazilaId = filter_input(INPUT_POST, 'upazila_id', FILTER_VALIDATE_INT);
    if (!$upazilaId) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => 'থানা আইডি প্রয়োজন']
        ]);
        return;
    }

    $unions = $geoService->getUnionByUpazila($upazilaId);
    echo json_encode(['status' => 'success', 'data' => $unions]);
});

// ================================================================
// POST : Get By Type Tree (placeholder)
// ================================================================
$router->post('/geo/getByTypeTree', function() {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'data' => []]);
});

// ================================================================
// POST / GET : Post Offices by Union
// ================================================================
$router->post('/api/post-offices', function() use ($geoService) {
    header('Content-Type: application/json; charset=utf-8');

    $unionName = trim($_POST['union_name'] ?? '');
    $unionId   = isset($_POST['union_id']) ? (int)$_POST['union_id'] : null;

    $result = $geoService->getPostOfficesByUnion($unionName, $unionId);
    echo json_encode($result);
});

$router->get('/api/post-offices', function() use ($geoService) {
    header('Content-Type: application/json; charset=utf-8');

    $unionName = trim($_GET['union_name'] ?? '');
    $unionId   = isset($_GET['union_id']) ? (int)$_GET['union_id'] : null;

    $result = $geoService->getPostOfficesByUnion($unionName, $unionId);
    echo json_encode($result);
});
