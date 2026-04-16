<?php
// routes/geoRoutes.php

global $mysqli, $twig;

// Initialize RolesManager
require_once __DIR__ . '/../classes/RolesManager.php';
$rolesManager = new RolesManager($mysqli);

$router = $router ?? null; // Assuming $router is defined elsewhere

// GET Routes (Return HTML/Twig)
$router->get('/settings/geo', function() use ($twig) {
    ensure_can('manage_geo');
    echo $twig->render('geo/index.twig', [
        'title' => 'জিইও',
        'header_title' => 'জিইও',
        'status' => '',
        'message' => '',
    ]);
});

$router->get('/geo', function() use ($twig) {
    ensure_can('manage_geo');
    echo $twig->render('geo/index.twig', [
        'title' => 'জিইও',
        'header_title' => 'জিইও',
        'status' => '',
        'message' => '',
    ]);
});

// POST Routes (JSON APIs)

// --- Store / Import Geo ---
$router->post('/geo/store', function() use ($mysqli) {
    ensure_can('manage_geo');
    header('Content-Type: application/json; charset=utf-8');

    $status = 'error';
    $message = 'Invalid XML URL or data.';

    if (isset($_POST['xml_url']) && filter_var($_POST['xml_url'], FILTER_VALIDATE_URL)) {
        $xmlUrl = filter_var($_POST['xml_url'], FILTER_SANITIZE_URL);

        // Inline secure fetch & process
        $ch = curl_init($xmlUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($ch);
        if (curl_errno($ch)) {
            $message = "cURL Error: " . curl_error($ch);
            curl_close($ch);
            echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>$message]]);
            exit;
        }
        curl_close($ch);

        // Determine JSON or XML
        if (json_decode($content, true) !== null) {
            $data = json_decode($content, true);
        } else {
            libxml_disable_entity_loader(true);
            libxml_use_internal_errors(true);
            $data = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOENT);
        }

        // Process data inline
        $status = 'success';
        $message = '';
        if (isset($data->geoObject) || isset($data['geoObject'])) {
            $geoObjects = is_array($data) ? $data['geoObject'] : $data->geoObject;
            foreach ($geoObjects as $geoObject) {
                $object = is_array($geoObject) ? (object)$geoObject : $geoObject;
                $id = $object->id ?? 0;
                $name_en = $object->nameEn ?? '';
                $name_bn = $object->nameBn ?? '';
                $geo_level_id = $object->geoLevelId ?? 0;
                $geo_code = $object->geoCode ?? 0;
                $parent_geo_id = $object->parentGeoId ?? 0;
                $rmo_code = $object->rmoCode ?? '';
                $ward_number = $object->wardNumber ?? '';
                $is_active_in_address = !empty($object->isActiveInAddress) ? 1 : 0;
                $geo_order = $object->geoLevelId ?? 0;
                $geo_type = $object->geoLevelId ?? 0;

                if ($id <= 0) {
                    $message .= "❌ Skipped invalid ID ($id).\n";
                    continue;
                }

                // Check if exists
                $stmtCheck = $mysqli->prepare("SELECT COUNT(*) FROM geo_location WHERE id=? AND name_en=? AND name_bn=? AND geo_code=? AND geo_order=? AND geo_type=?");
                $stmtCheck->bind_param("issiii", $id, $name_en, $name_bn, $geo_code, $geo_order, $geo_type);
                $stmtCheck->execute();
                $stmtCheck->bind_result($count);
                $stmtCheck->fetch();
                $stmtCheck->close();
                if ($count > 0) {
                    $message .= "⚠️ Skipped exists ID $id.\n";
                    continue;
                }

                // Insert
                $stmtInsert = $mysqli->prepare("INSERT INTO geo_location (id, name_en, name_bn, geo_level_id, geo_code, parent_geo_id, rmo_code, ward_number, is_active_in_address, geo_order, geo_type) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmtInsert->bind_param("issiiiiisii", $id, $name_en, $name_bn, $geo_level_id, $geo_code, $parent_geo_id, $rmo_code, $ward_number, $is_active_in_address, $geo_order, $geo_type);
                if ($stmtInsert->execute()) {
                    $message .= "✅ Inserted ID $id: $name_en\n";
                } else {
                    $message .= "❌ Error inserting ID $id\n";
                }
                $stmtInsert->close();
            }
        } else {
            $status = 'error';
            $message = "Invalid or empty data structure.";
        }
    }

    echo json_encode(['status'=>$status,'message'=>$message]);
});

// --- Get Geo Data ---
$router->post('/settings/geo/getdata', function() use ($mysqli) {
    header('Content-Type: application/json');

    $validReferer = ($_SERVER['REQUEST_METHOD']==='POST') && (parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) === $_SERVER['HTTP_HOST']);
    if ($_SERVER['REQUEST_METHOD']!=='POST') {
        http_response_code(405);
        echo json_encode(['status'=>'error','message'=>'Use POST']);
        return;
    } elseif (!$validReferer) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Forbidden']);
        return;
    }

    $geoOrder = intval($_POST['geo_order'] ?? 0);
    $parentGeoId = intval($_POST['parent_geo_id'] ?? 0);
    $response = [];

    $stmt = $mysqli->prepare("SELECT id,name_en,name_bn,geo_order,geo_code,rmo_code FROM geo_location WHERE geo_order=? AND (parent_geo_id=? OR ?=0) ORDER BY name_en");
    $stmt->bind_param('iii',$geoOrder,$parentGeoId,$parentGeoId);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row=$result->fetch_assoc()) $response[]=$row;
    $stmt->close();

    echo json_encode($response);
});

$router->post('/geo/getdata', function() use ($mysqli) {
    // Same as above
    header('Content-Type: application/json');
    $geoOrder = intval($_POST['geo_order'] ?? 0);
    $parentGeoId = intval($_POST['parent_geo_id'] ?? 0);
    $response = [];
    $stmt = $mysqli->prepare("SELECT id,name_en,name_bn,geo_order,geo_code,rmo_code FROM geo_location WHERE geo_order=? AND (parent_geo_id=? OR ?=0) ORDER BY name_en");
    $stmt->bind_param('iii',$geoOrder,$parentGeoId,$parentGeoId);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row=$result->fetch_assoc()) $response[]=$row;
    $stmt->close();
    echo json_encode($response);
});

// --- Get Geo By Type ---
$router->post('/geo/getByType', function() use ($mysqli) {
    header('Content-Type: application/json');

    $response = ['status' => 'success', 'data' => []];

    // 1. Validate Request Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Use POST.']);
        return;
    }

    // 2. Get POST params safely
    $geoOrder = isset($_POST['geo_order']) ? intval($_POST['geo_order']) : 0;
    $parentGeoId = isset($_POST['parent_geo_id']) ? intval($_POST['parent_geo_id']) : 0;
    $searchTerm = isset($_POST['search']) ? trim($_POST['search']) : '';

    // 3. Validate Input Parameters
    if ($geoOrder < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid geo_order parameter. Must be non-negative.']);
        return;
    }

    $nextGeoOrder = $geoOrder + 1;

    // 4. Build SQL
    $sql = "SELECT g.id, g.name_en, g.name_bn, g.geo_order, g.geo_code, g.rmo_code,
                   (SELECT COUNT(*) FROM geo_location c 
                    WHERE c.parent_geo_id = g.id AND c.geo_order = ?) AS child_count
            FROM geo_location g
            WHERE g.geo_order = ?
              AND (? = 0 OR g.parent_geo_id = ?)";

    // Add search filter
    if ($searchTerm !== '') {
        $sql .= " AND g.name_en LIKE CONCAT('%', ?, '%')";
    }

    $sql .= " ORDER BY g.name_en";

    // 5. Prepare statement
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        error_log('Database prepare failed: ' . $mysqli->error . ' (SQL: ' . substr($sql, 0, 100) . '...)');
        echo json_encode(['status' => 'error', 'message' => 'Server error preparing geo data.']);
        return;
    }

    // 6. Bind parameters dynamically
    $bindTypes = 'iiii';
    $bindParams = [$nextGeoOrder, $geoOrder, $parentGeoId, $parentGeoId];

    if ($searchTerm !== '') {
        $bindTypes .= 's';
        $bindParams[] = $searchTerm;
    }

    // Helper to convert array values to references
    $refValues = function($arr) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    };

    call_user_func_array([$stmt, 'bind_param'], $refValues(array_merge([$bindTypes], $bindParams)));

    // 7. Execute statement
    if (!$stmt->execute()) {
        http_response_code(500);
        error_log('Database execute failed: ' . $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Server error retrieving geo data.']);
        $stmt->close();
        return;
    }

    // 8. Fetch results
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $response['data'][] = $row;
    }

    $stmt->close();

    // 9. Return JSON
    echo json_encode($response);
});

// --- Get Union by district/upazila ---
$router->post('/geo/getUnion', function() use ($mysqli) {
    header('Content-Type: application/json');
    $districtNameEn = filter_input(INPUT_POST,'district_name_en',FILTER_SANITIZE_STRING);
    $upazilaNameEn  = filter_input(INPUT_POST,'upazila_name_en',FILTER_SANITIZE_STRING);
    if(!$districtNameEn || !$upazilaNameEn) {http_response_code(400); echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>'জেলার এবং থানার নাম প্রয়োজন']]); return;}
    $stmt=$mysqli->prepare("SELECT union_id,union_name_en,union_name_bn,union_code FROM unions WHERE district_name_en=? AND upazila_name_en=? ORDER BY union_name_en ASC");
    $stmt->bind_param("ss",$districtNameEn,$upazilaNameEn);
    $stmt->execute();
    $result=$stmt->get_result();
    $unions=[]; while($row=$result->fetch_assoc()) $unions[]=$row;
    echo json_encode(['status'=>'success','data'=>$unions]);
});

// --- Get Union by upazila_id ---
$router->post('/geo/geoUnion', function() use ($mysqli) {
    header('Content-Type: application/json');
    $upazilaId = filter_input(INPUT_POST,'upazila_id',FILTER_VALIDATE_INT);
    if(!$upazilaId){http_response_code(400);echo json_encode(['status'=>'error','alert'=>['type'=>'error','title'=>'ত্রুটি','message'=>'থানা আইডি প্রয়োজন']]); return;}
    $stmt=$mysqli->prepare("SELECT union_id,union_name_en,union_name_bn,union_code FROM unions WHERE upazila_id=? AND is_active=1 ORDER BY union_name_en ASC");
    $stmt->bind_param("i",$upazilaId);
    $stmt->execute();
    $result=$stmt->get_result();
    $unions=[]; while($row=$result->fetch_assoc()) $unions[]=$row;
    echo json_encode(['status'=>'success','data'=>$unions]);
});

// --- Placeholder for getByTypeTree (You can inline similar to getByType) ---
$router->post('/geo/getByTypeTree', function() use ($mysqli){
    header('Content-Type: application/json');
    echo json_encode(['status'=>'success','data'=>[]]);
});
