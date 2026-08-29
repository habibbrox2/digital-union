<?php
/**
 * controllers/GeoController.php
 * 
 * Geographic data management routes.
 * All business logic is handled by GeoService.
 * No inline curl, no inline DB queries, no closure helpers.
 */

global $router, $twig, $mysqli;

require_once __DIR__ . '/../modules/Services/GeoService.php';

$authService = new AuthService($mysqli);
$geoService = new GeoService($mysqli);

// ================================================================
// GET : Geo management pages
// ================================================================
$router->get('/settings/geo', function() use ($twig, $authService) {
    $authService->ensureCan('manage_geo');
    echo $twig->render('geo/index.twig', [
        'title' => 'জিইও',
        'header_title' => 'ভৌগোলিক অবস্থান',
        'status' => '',
        'message' => '',
    ]);
});

$router->get('/geo', function() use ($twig, $authService) {
    $authService->ensureCan('manage_geo');
    echo $twig->render('geo/index.twig', [
        'title' => 'জিইও',
        'header_title' => 'ভৌগোলিক অবস্থান',
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
// LEGACY GEO CODE API ROUTES (used by main.js / public.twig cascading dropdowns)
// ================================================================

// GET /geo/code/get?id={parent_id}&type={type}
// Returns child geo locations (e.g. upazilas for a district)
// type=3 → upazilas (geo_order 2), type=2 → districts (geo_order 1)
$router->get('/geo/code/get', function() use ($mysqli) {
    header('Content-Type: application/json; charset=utf-8');

    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $type = isset($_GET['type']) ? (int)$_GET['type'] : 0;

    if ($id <= 0) {
        echo json_encode([]);
        return;
    }

    // Map type to child geo_order: type 3 → geo_order 2 (upazila), type 2 → geo_order 1 (district)
    $geoOrder = $type > 1 ? $type - 1 : 1;

    $stmt = $mysqli->prepare(
        "SELECT id, name_bn FROM geo_location WHERE geo_order = ? AND parent_geo_id = ? ORDER BY name_bn"
    );
    if (!$stmt) {
        echo json_encode([]);
        return;
    }
    $stmt->bind_param('ii', $geoOrder, $id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id'      => (int)$row['id'],
            'bn_name' => $row['name_bn'],
        ];
    }
    $stmt->close();

    echo json_encode(['upzilla' => $rows]);
});

// GET /geo/code/get_union?id={upazila_id}
// Returns unions for a given upazila
$router->get('/geo/code/get_union', function() use ($mysqli) {
    header('Content-Type: application/json; charset=utf-8');

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id <= 0) {
        echo json_encode(['union' => []]);
        return;
    }

    $stmt = $mysqli->prepare(
        "SELECT union_code, union_name_bn AS bn_name FROM unions WHERE upazila_id = ? AND is_active = 1 ORDER BY union_name_bn ASC"
    );
    if (!$stmt) {
        echo json_encode(['union' => []]);
        return;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'union_code' => $row['union_code'],
            'bn_name'    => $row['bn_name'],
        ];
    }
    $stmt->close();

    echo json_encode(['union' => $rows]);
});

// GET /geo/code/get_word_no?id={union_id}
// Returns ward numbers for a given union (generated from ward_count)
$router->get('/geo/code/get_word_no', function() use ($mysqli) {
    header('Content-Type: application/json; charset=utf-8');

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id <= 0) {
        echo json_encode(['word' => []]);
        return;
    }

    // Get ward_count from the union
    $stmt = $mysqli->prepare(
        "SELECT ward_count FROM unions WHERE union_id = ? OR union_code = ? LIMIT 1"
    );
    if (!$stmt) {
        echo json_encode(['word' => []]);
        return;
    }
    $unionId = $id;
    $unionCode = (string)$id;
    $stmt->bind_param('is', $unionId, $unionCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $wardCount = (int)($row['ward_count'] ?? 0);
    if ($wardCount <= 0) {
        echo json_encode(['word' => []]);
        return;
    }

    $words = [];
    for ($i = 1; $i <= $wardCount; $i++) {
        $words[] = ['ward_no' => (string)$i];
    }

    echo json_encode(['word' => $words]);
});

// GET /geo/code/get_moholla?id={ward_id}&union_id={union_id}
// Returns mohollas for a given ward within a union
$router->get('/geo/code/get_moholla', function() use ($mysqli) {
    header('Content-Type: application/json; charset=utf-8');

    $id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $unionId = isset($_GET['union_id']) ? (int)$_GET['union_id'] : 0;

    if ($id <= 0) {
        echo json_encode(['moholla' => []]);
        return;
    }

    // Check if mohollas table exists
    $tableCheck = $mysqli->query("SHOW TABLES LIKE 'mohollas'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $stmt = $mysqli->prepare(
            "SELECT id, name FROM mohollas WHERE ward_no = ? AND union_id = ? ORDER BY name"
        );
        if ($stmt) {
            $wardNo = (string)$id;
            $stmt->bind_param('si', $wardNo, $unionId);
            $stmt->execute();
            $result = $stmt->get_result();

            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = [
                    'id'   => (int)$row['id'],
                    'name' => $row['name'],
                ];
            }
            $stmt->close();
            echo json_encode(['moholla' => $rows]);
            return;
        }
    }

    echo json_encode(['moholla' => []]);
});

// ================================================================
// V2 GEO API ROUTES (for cascading dropdowns)
// ================================================================

// GET /api/v2/geo/districts — list all districts
$router->get('/api/v2/geo/districts', function() use ($mysqli) {
    header('Content-Type: application/json; charset=utf-8');
    
    $result = $mysqli->query(
        "SELECT id, name_en, name_bn FROM geo_location WHERE geo_order = 1 ORDER BY name_en"
    );
    
    $districts = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $districts[] = $row;
        }
    }
    
    echo json_encode($districts);
});

// GET /api/v2/geo/upazilas/{district_id} — list upazilas for a district
$router->get('/api/v2/geo/upazilas/{district_id}', function($district_id = null) use ($mysqli) {
    header('Content-Type: application/json; charset=utf-8');
    
    $districtId = (int)($district_id ?: 0);
    if ($districtId <= 0) {
        echo json_encode([]);
        return;
    }
    
    $stmt = $mysqli->prepare(
        "SELECT id, name_en, name_bn FROM geo_location WHERE geo_order = 2 AND parent_geo_id = ? ORDER BY name_en"
    );
    
    if (!$stmt) {
        echo json_encode([]);
        return;
    }
    
    $stmt->bind_param('i', $districtId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $upazilas = [];
    while ($row = $result->fetch_assoc()) {
        $upazilas[] = $row;
    }
    $stmt->close();
    
    echo json_encode($upazilas);
});

// GET /api/v2/geo/unions/{upazila_id} — list unions for an upazila
$router->get('/api/v2/geo/unions/{upazila_id}', function($upazila_id = null) use ($mysqli) {
    header('Content-Type: application/json; charset=utf-8');
    
    $upazilaId = (int)($upazila_id ?: 0);
    if ($upazilaId <= 0) {
        echo json_encode([]);
        return;
    }
    
    $stmt = $mysqli->prepare(
        "SELECT union_id AS id, union_name_en AS name_en, union_name_bn AS name_bn, union_code FROM unions WHERE upazila_id = ? AND is_active = 1 ORDER BY union_name_en ASC"
    );
    
    if (!$stmt) {
        echo json_encode([]);
        return;
    }
    
    $stmt->bind_param('i', $upazilaId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $unions = [];
    while ($row = $result->fetch_assoc()) {
        $unions[] = $row;
    }
    $stmt->close();
    
    echo json_encode($unions);
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
