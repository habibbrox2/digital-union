<?php



// ==================== PHP Controller ====================

// controllers/BusinessOwnershipController.php

$businessOwnership = new BusinessOwnershipType($mysqli);

$auth              = new AuthManager($mysqli);



function listBusinessTypes() {

    global $auth, $businessOwnership, $twig;
    ensure_can('manage_settings');

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'id';

    $order = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'DESC' : 'ASC';



    $data = $businessOwnership->fetchBusinessTypes($page, $limit, $search, $sort, $order);



    echo $twig->render('settings/business-types.twig', [

        'title' => 'Business Types',

        'header_title' => 'Manage Business Types',


        'businessTypes' => $data['businessTypes'],

        'currentPage' => $data['currentPage'],

        'totalPages' => $data['totalPages'],

        'limit' => $limit,

        'search' => $search,

        'sort' => $sort,

        'order' => $order,

        'status' => '',

        'message_bn' => '',

    ]);

}



function addBusinessType() {

    global $auth, $businessOwnership, $twig;


    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        header("Location: /settings/business-types");

        exit;

    }



    $data = [

        'business_name_bn' => sanitize_input($_POST['business_name_bn'] ?? ''),

        'business_name_en' => sanitize_input($_POST['business_name_en'] ?? ''),

        'license_fee' => floatval($_POST['license_fee'] ?? 0),

        'vat_amount' => floatval($_POST['vat_amount'] ?? 0),

        'occupation_tax' => floatval($_POST['occupation_tax'] ?? 0),

        'income_tax' => floatval($_POST['income_tax'] ?? 0),

        'signboard_tax' => floatval($_POST['signboard_tax'] ?? 0),

        'surcharge' => floatval($_POST['surcharge'] ?? 0),

        'union_id' => intval($_POST['union_id'] ?? 0),

    ];



    if (

        empty($data['business_name_bn']) ||

        $data['license_fee'] <= 0 ||

        $data['vat_amount'] < 0 ||

        $data['occupation_tax'] < 0

    ) {

        $status = "error";

        $message = "সব তথ্য অবশ্যই পূরণ করতে হবে।";

    } else {

        $result = $businessOwnership->addBusinessType($data);

        $status = $result['status'];

        $message = $result['message'];

    }



    $page = 1;

    $businessTypesData = $businessOwnership->fetchBusinessTypes($page);



    echo $twig->render('settings/business-types.twig', [

        'title' => 'Business Types',

        'header_title' => 'Manage Business Types',


        'businessTypes' => $businessTypesData['businessTypes'],

        'currentPage' => $businessTypesData['currentPage'],

        'totalPages' => $businessTypesData['totalPages'],

        'status' => $status,

        'message_bn' => $message,

    ]);

}



function editBusinessTypeForm($id) {

    global $auth, $businessOwnership, $twig;

    ensure_can('manage_settings');

    $id = intval($id);

    $status = null;

    $message = null;



    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $data = [

            'business_name_bn' => sanitize_input($_POST['business_name_bn'] ?? ''),

            'business_name_en' => sanitize_input($_POST['business_name_en'] ?? ''),

            'license_fee' => floatval($_POST['license_fee'] ?? 0),

            'vat_amount' => floatval($_POST['vat_amount'] ?? 0),

            'occupation_tax' => floatval($_POST['occupation_tax'] ?? 0),

            'income_tax' => floatval($_POST['income_tax'] ?? 0),

            'signboard_tax' => floatval($_POST['signboard_tax'] ?? 0),

            'surcharge' => floatval($_POST['surcharge'] ?? 0),

        ];



        if (

            empty($data['business_name_bn']) ||

            empty($data['business_name_en']) ||

            $data['license_fee'] <= 0

        ) {

            $status = "error";

            $message = "All fields are required.";

        } else {

            $result = $businessOwnership->updateBusinessType($id, $data);

            $status = $result['status'];

            $message = $result['message'];

        }

    }



    $businessType = $businessOwnership->getBusinessTypeById($id);



    if (!$businessType) {

        die("Business type not found");

    }



    echo $twig->render('settings/edit-business-type.twig', [
        'title'           => 'Edit Business Type',
        'header_title'    => 'Edit Business Type',
        'businessType'    => $businessType,
        'status'          => $status,
        'message'         => $message,
    ]);

}



function deleteBusinessType() {

    global $auth, $businessOwnership;
    ensure_can('manage_settings');

    $id = sanitize_input($_POST['id'] ?? '');

    if (empty($id) || !is_numeric($id)) {

        echo json_encode(['status' => 'error', 'message' => 'Invalid ID provided.']);

        return;

    }



    $result = $businessOwnership->deleteBusinessType((int)$id);

    echo json_encode($result);

}



// ------------- Ownership Types ---------------



function listOwnershipTypes() {

    global $auth, $businessOwnership, $twig;

    ensure_can('manage_settings');

    $ownershipTypes = $businessOwnership->fetchOwnershipTypes();



    echo $twig->render('settings/ownership-types.twig', [

        'title' => 'Ownership Types',

        'header_title' => 'Manage Ownership Types',


        'ownershipTypes' => $ownershipTypes,

        'status' => '',

        'message_bn' => '',

    ]);

}



function addOwnershipType() {

    global $auth, $businessOwnership, $twig;


    $status = '';

    $message = '';



    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        header("Location: /settings/ownership-types");

        exit;

    }



    $data = [

        'ownership_name_bn' => sanitize_input($_POST['ownership_name_bn'] ?? ''),

        'ownership_name_en' => sanitize_input($_POST['ownership_name_en'] ?? ''),

    ];



    if (empty($data['ownership_name_bn']) || empty($data['ownership_name_en'])) {

        $status = "error";

        $message = "Both Ownership Name (Bangla) and Ownership Name (English) are required.";

    } else {

        $result = $businessOwnership->addOwnershipType($data);

        $status = $result['status'];

        $message = $result['message'];

    }



    $ownershipTypes = $businessOwnership->fetchOwnershipTypes();



    echo $twig->render('settings/ownership-types.twig', [

        'title' => 'Ownership Types',

        'header_title' => 'Manage Ownership Types',


        'ownershipTypes' => $ownershipTypes,

        'status' => $status,

        'message_bn' => $message,

    ]);

}



function editOwnershipTypeForm($id) {

    global $auth, $businessOwnership, $twig;

    ensure_can('manage_settings');

    $id = intval($id);

    $status = null;

    $message = null;



    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $data = [

            'ownership_name_bn' => sanitize_input($_POST['ownership_name_bn'] ?? ''),

            'ownership_name_en' => sanitize_input($_POST['ownership_name_en'] ?? ''),

        ];



        if (empty($data['ownership_name_bn']) || empty($data['ownership_name_en'])) {

            $status = "error";

            $message = "Ownership name (Bangla & English) is required.";

        } else {

            $result = $businessOwnership->updateOwnershipType($id, $data);

            $status = $result['status'];

            $message = $result['message'];

        }

    }



    $ownershipType = $businessOwnership->getOwnershipTypeById($id);



    if (!$ownershipType) {

        die("Ownership type not found");

    }



    echo $twig->render('settings/edit-ownership-type.twig', [
        'title'           => 'Edit Ownership Type',
        'header_title'    => 'Edit Ownership Type',
        'ownershipType'   => $ownershipType,
        'status'          => $status,
        'message'         => $message,
    ]);

}



function deleteOwnershipType() {

    global $auth, $businessOwnership;
    ensure_can('manage_settings');

    $id = sanitize_input($_POST['id'] ?? '');

    if (empty($id) || !is_numeric($id)) {

        echo json_encode(['status' => 'error', 'message' => 'Invalid ID provided.']);

        return;

    }



    $result = $businessOwnership->deleteOwnershipType((int)$id);

    echo json_encode($result);

}



// ==================== Routes ====================

global $router;

// Business Types
$router->get('/settings/business-types', 'listBusinessTypes');
$router->post('/settings/business-types/add', 'addBusinessType');
$router->get('/settings/business-types/edit/{id}', 'editBusinessTypeForm');
$router->post('/settings/business-types/edit/{id}', 'editBusinessTypeForm');
$router->post('/settings/business-types/delete', 'deleteBusinessType');

// Ownership Types
$router->get('/settings/ownership-types', 'listOwnershipTypes');
$router->post('/settings/ownership-types/add', 'addOwnershipType');
$router->get('/settings/ownership-types/edit/{id}', 'editOwnershipTypeForm');
$router->post('/settings/ownership-types/edit/{id}', 'editOwnershipTypeForm');
$router->post('/settings/ownership-types/delete', 'deleteOwnershipType');

