<?php
/**
 * controllers/BusinessOwnershipController.php
 * 
 * Business type and ownership type management routes.
 * All DB logic is handled by BusinessOwnershipType model.
 * No helper functions in this controller.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);
require_once __DIR__ . '/../models/AuthManager.php';
$auth = new AuthManager($mysqli);
$businessOwnership = new BusinessOwnershipType($mysqli);

// ================================================================
// BUSINESS TYPES
// ================================================================

// GET : List business types
$router->get('/settings/business-types', function() use ($twig, $businessOwnership, $authService, $auth) {
    $authService->ensureCan('manage_settings');

    $limit = 0;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'id';
    $order = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'DESC' : 'ASC';

    $userData = $auth->getUserData(false);
    $isSuperAdmin = $userData && !empty($userData['is_superadmin']);
    $unionId = null;
    if (!$isSuperAdmin && $userData && !empty($userData['union_id'])) {
        $unionId = (int)$userData['union_id'];
    }

    $data = $businessOwnership->fetchBusinessTypes(1, $limit, $search, $sort, $order, $unionId);

    echo $twig->render('settings/business-types.twig', [
        'title' => 'Business Types',
        'header_title' => 'Manage Business Types',
        'businessTypes' => $data['businessTypes'],
        'currentPage' => $data['currentPage'],
        'totalPages' => $data['totalPages'],
        'totalRecords' => $data['totalRecords'] ?? 0,
        'user_union_id' => $unionId,
        'is_superadmin' => $isSuperAdmin,
    ]);
});

// POST : Save business type (add/edit)
$router->post('/settings/business-types/save', function() use ($mysqli, $businessOwnership, $authService, $auth) {
    $authService->ensureCan('manage_settings');
    header('Content-Type: application/json');

    try {
        $id = (int)($_POST['id'] ?? 0);

        // Get logged-in user data for union scoping
        $userData = $auth->getUserData(false);
        $isSuperAdmin = $userData && !empty($userData['is_superadmin']);
        $userUnionId = !$isSuperAdmin && $userData ? (int)($userData['union_id'] ?? 0) : 0;

        // If editing, fetch existing record (for permission check and union_id preservation)
        $existing = null;
        if ($id > 0) {
            $existing = $businessOwnership->getBusinessTypeById($id);
            if (!$existing) {
                throw new Exception('ব্যবসার ধরণ খুঁজে পাওয়া যায়নি।');
            }
            // Union permission check for non-superadmins
            if (!$isSuperAdmin && $userUnionId > 0 && (int)$existing['union_id'] !== $userUnionId) {
                throw new Exception('আপনার এই ব্যবসার ধরণ সম্পাদনা করার অনুমতি নেই।');
            }
        }

        // Build data array — form field names match model expectations
        $data = [
            'business_name_bn' => sanitize_input($_POST['business_name_bn'] ?? ''),
            'business_name_en' => sanitize_input($_POST['business_name_en'] ?? ''),
            'license_fee' => (float)($_POST['license_fee'] ?? 0),
            'vat_amount' => (float)($_POST['vat_amount'] ?? 0),
            'occupation_tax' => (float)($_POST['occupation_tax'] ?? 0),
            'income_tax' => (float)($_POST['income_tax'] ?? 0),
            'signboard_tax' => (float)($_POST['signboard_tax'] ?? 0),
            'surcharge' => (float)($_POST['surcharge'] ?? 0),
        ];

        // Set union_id: for edits preserve existing, for new records use user's union
        $data['union_id'] = $existing ? (int)$existing['union_id'] : $userUnionId;

        if (empty($data['business_name_bn'])) {
            throw new Exception('ব্যবসার নাম (বাংলা) প্রয়োজন।');
        }

        if ($id > 0) {
            $result = $businessOwnership->updateBusinessType($id, $data);
        } else {
            $result = $businessOwnership->addBusinessType($data);
        }

        if ($result['status'] !== 'success') {
            throw new Exception($result['message']);
        }

        echo json_encode(['status' => 'success', 'message' => 'ব্যবসার ধরণ সংরক্ষণ করা হয়েছে']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
});

// POST : Delete business type
$router->post('/settings/business-types/delete/{id}', function($id) use ($businessOwnership, $authService, $auth) {
    $authService->ensureCan('manage_settings');
    header('Content-Type: application/json');

    try {
        $typeId = (int)$id;

        // Verify the record belongs to the user's union
        $userData = $auth->getUserData(false);
        $isSuperAdmin = $userData && !empty($userData['is_superadmin']);
        if (!$isSuperAdmin) {
            $existing = $businessOwnership->getBusinessTypeById($typeId);
            $userUnionId = (int)($userData['union_id'] ?? 0);
            if ($existing && $userUnionId > 0 && (int)$existing['union_id'] !== $userUnionId) {
                throw new Exception('আপনার এই ব্যবসার ধরণ মুছতে অনুমতি নেই।');
            }
        }

        $result = $businessOwnership->deleteBusinessType($typeId);
        if ($result['status'] !== 'success') {
            throw new Exception($result['message']);
        }
        echo json_encode(['status' => 'success', 'message' => 'ব্যবসার ধরণ মুছে ফেলা হয়েছে']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
});

// GET : Edit business type form
$router->get('/settings/business-types/edit/{id}', function($id) use ($twig, $businessOwnership, $authService, $auth) {
    $authService->ensureCan('manage_settings');

    $businessType = $businessOwnership->getBusinessTypeById((int)$id);
    if (!$businessType) {
        echo $twig->render('settings/edit-business-type.twig', [
            'title' => 'Edit Business Type',
            'header_title' => 'Edit Business Type',
            'businessType' => null,
            'status' => 'error',
            'message' => 'ব্যবসার ধরণ খুঁজে পাওয়া যায়নি।',
        ]);
        return;
    }

    // Union scoping: non-superadmin can only edit their own union's records
    $userData = $auth->getUserData(false);
    $isSuperAdmin = $userData && !empty($userData['is_superadmin']);
    $userUnionId = (int)($userData['union_id'] ?? 0);
    if (!$isSuperAdmin && $userUnionId > 0 && (int)$businessType['union_id'] !== $userUnionId && (int)$businessType['union_id'] > 0) {
        echo $twig->render('settings/edit-business-type.twig', [
            'title' => 'Edit Business Type',
            'header_title' => 'Edit Business Type',
            'businessType' => $businessType,
            'status' => 'error',
            'message' => 'আপনার এই ব্যবসার ধরণ সম্পাদনা করার অনুমতি নেই।',
        ]);
        return;
    }

    echo $twig->render('settings/edit-business-type.twig', [
        'title' => 'Edit Business Type',
        'header_title' => 'ব্যবসার ধরণ সম্পাদনা করুন',
        'businessType' => $businessType,
        'status' => null,
        'message' => null,
        'user_union_id' => $userUnionId,
        'is_superadmin' => $isSuperAdmin,
    ]);
});

// ================================================================
// OWNERSHIP TYPES
// ================================================================

// GET : List ownership types
$router->get('/settings/ownership-types', function() use ($twig, $businessOwnership, $authService) {
    $authService->ensureCan('manage_settings');

    $data = $businessOwnership->fetchOwnershipTypes();

    echo $twig->render('settings/ownership-types.twig', [
        'title' => 'Ownership Types',
        'header_title' => 'Manage Ownership Types',
        'ownershipTypes' => $data,
    ]);
});

// POST : Add ownership type (AJAX)
$router->post('/settings/ownership-types/add', function() use ($businessOwnership, $authService) {
    $authService->ensureCan('manage_settings');
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $data = [
            'ownership_name_bn' => sanitize_input($input['ownership_name_bn'] ?? ''),
            'ownership_name_en' => sanitize_input($input['ownership_name_en'] ?? ''),
        ];

        if (empty($data['ownership_name_bn']) || empty($data['ownership_name_en'])) {
            throw new Exception('উভয় ক্ষেত্র পূরণ করুন।');
        }

        $result = $businessOwnership->addOwnershipType($data);
        if ($result['status'] !== 'success') {
            throw new Exception($result['message']);
        }

        echo json_encode(['status' => 'success', 'message' => 'মালিকানা প্রকার সফলভাবে যোগ করা হয়েছে।']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
});

// POST : Edit ownership type (AJAX)
$router->post('/settings/ownership-types/edit/{id}', function($id) use ($businessOwnership, $authService) {
    $authService->ensureCan('manage_settings');
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $data = [
            'ownership_name_bn' => sanitize_input($input['ownership_name_bn'] ?? ''),
            'ownership_name_en' => sanitize_input($input['ownership_name_en'] ?? ''),
        ];

        if (empty($data['ownership_name_bn']) || empty($data['ownership_name_en'])) {
            throw new Exception('উভয় ক্ষেত্র পূরণ করুন।');
        }

        $result = $businessOwnership->updateOwnershipType((int)$id, $data);
        if ($result['status'] !== 'success') {
            throw new Exception($result['message']);
        }

        echo json_encode(['status' => 'success', 'message' => 'মালিকানা প্রকার সফলভাবে আপডেট করা হয়েছে।']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
});

// POST : Delete ownership type (AJAX)
$router->post('/settings/ownership-types/delete/{id}', function($id) use ($businessOwnership, $authService) {
    $authService->ensureCan('manage_settings');
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        // CSRF token check can be added here

        $result = $businessOwnership->deleteOwnershipType((int)$id);
        if ($result['status'] !== 'success') {
            throw new Exception($result['message']);
        }

        echo json_encode(['status' => 'success', 'message' => 'মালিকানা প্রকার মুছে ফেলা হয়েছে।']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
});
