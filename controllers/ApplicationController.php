<?php

// controllers/ApplicationController.php


global $crypt, $mysqli, $twig, $router;
$unionModel = new UnionModel($mysqli);
$auth = $auth ?? new AuthManager($mysqli);
$appmanager = $appmanager ?? new ApplicationManager($mysqli);
if (!isset($crypt)) {
    $crypt = get_crypt_manager();
}
require_once __DIR__ . '/../helpers/rbac_helpers.php';

// GET Routes

$router->get('/applications', function() use ($twig, $appmanager, $auth) {
    ensure_can('manage_applications', 'applications');
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $types = $appmanager->CertificateTypeLists($union_id);
    echo $twig->render('applications/types_list.twig', [
        'types' => $types,
        'title' => 'Applications list',
        'header_title' => 'আবেদন তালিকা',
    ]);
});

$router->get('/{certificate_type}/apply/from/{applicant_id}', function($certificate_type, $applicant_id) use ($twig, $appmanager, $mysqli) {
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
    $applicant_id = sanitize_input($applicant_id);
    $reuse_data = $appmanager->getLatestApplicationByApplicantId($applicant_id);
    if (!$reuse_data) {
        echo $twig->render('errors/404.twig', ['message' => 'Applicant not found.']);
        return;
    }
    $template = templatePath('applications/forms', $certificate_type, 'reapply.twig');
    if ($certificate_type === 'trade') {
        $businessOwnership = new BusinessOwnershipType($mysqli);
        $reuse_data['business_types'] = $businessOwnership->getBusinessTypes();
        $reuse_data['ownership_types'] = $businessOwnership->getOwnershipTypes();
    }
    echo $twig->render($template, [
        'reuse_data' => $reuse_data,
        'certificate_type' => $certificate_type,
        'certificate_type_bn' => $twig->getGlobals()['certificate_type_bn'] ?? null,
        'title' => 'আবেদন ফর্ম পূরণ করুন',
        'header_title' => 'আবেদন ফর্ম পূরণ করুন',
    ]);
});

$router->get('/{url_path}_verify/application/{application_id}', function($url_path, $application_id) use ($twig, $appmanager, $mysqli) {
    $application = $appmanager->getApplicationByApplicationId($application_id);
    if ($application) {
        $union = null;
        if ($application['union_id']) {
            // Fetch union from database
            $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
            $stmt->bind_param("i", $application['union_id']);
            $stmt->execute();
            $union = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $certificate_type = $twig->getGlobals()['certificate_type'] ?? $application['certificate_type'];
        $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;
        $documents = isset($application['existing_documents']) ? json_decode($application['existing_documents'], true) : [];
        $template = "applications/application-copy.twig";
        if (!empty($certificate_type) && $certificate_type !== 'application') {
            $custom_template = "applications/{$certificate_type}-copy.twig";
            $custom_template_path = __DIR__ . "/../templates/{$custom_template}";
            if (file_exists($custom_template_path)) $template = $custom_template;
        }
        $business_meta = null;
        if ($certificate_type === 'trade' && !empty($application['application_id'])) {
            $business_meta = $appmanager->getBusinessMetaByApplicationId($application['application_id']);
        }
        if ($certificate_type === 'warish' && !empty($application['application_id'])) {
            $application['warish_members'] = $appmanager->getMembersByApplication($application_id);
        }
        $htmlContent = $twig->render($template, [
            'title' => 'আবেদনের কপি',
            'header_title' => 'আবেদনের কপি',
            'detail' => Data($application),
            'documents' => $documents,
            'union' => $union,
            'certificate_type' => $certificate_type,
            'certificate_type_bn' => $certificate_type_bn,
            'business_meta' => $business_meta,
        ]);
        generatePdf($htmlContent, "application_copy");
    } else {
        die("Error: No application found for the given application_id.");
    }
});

$router->get('/{url_path}_verify/application/{application_id}/{union_code}/{rmo_code}', function($url_path, $application_id, $union_code = null, $rmo_code = null) use ($twig, $appmanager, $mysqli) {
    $application = $appmanager->getApplicationByApplicationId($application_id);
    if ($application) {
        $union = null;
        if ($application['union_id']) {
            $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
            $stmt->bind_param("i", $application['union_id']);
            $stmt->execute();
            $union = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $certificate_type = $twig->getGlobals()['certificate_type'] ?? $application['certificate_type'];
        $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;
        $documents = isset($application['existing_documents']) ? json_decode($application['existing_documents'], true) : [];
        $template = "applications/application-copy.twig";
        if (!empty($certificate_type) && $certificate_type !== 'application') {
            $custom_template = "applications/{$certificate_type}-copy.twig";
            $custom_template_path = __DIR__ . "/../templates/{$custom_template}";
            if (file_exists($custom_template_path)) $template = $custom_template;
        }
        $business_meta = null;
        if ($certificate_type === 'trade' && !empty($application['application_id'])) {
            $business_meta = $appmanager->getBusinessMetaByApplicationId($application['application_id']);
        }
        if ($certificate_type === 'warish' && !empty($application['application_id'])) {
            $application['warish_members'] = $appmanager->getMembersByApplication($application_id);
        }
        $htmlContent = $twig->render($template, [
            'title' => 'আবেদনের কপি',
            'header_title' => 'আবেদনের কপি',
            'detail' => Data($application),
            'documents' => $documents,
            'union' => $union,
            'certificate_type' => $certificate_type,
            'certificate_type_bn' => $certificate_type_bn,
            'business_meta' => $business_meta,
        ]);
        generatePdf($htmlContent, "application_copy");
    } else {
        die("Error: No application found for the given application_id.");
    }
});

$router->get('/verify/{url_path}_bn/{sonod_number}/{union_code}/{rmo_code}', function($url_path, $sonod_number = null, $union_code = null, $rmo_code = null) use ($twig, $appmanager, $mysqli) {
    if (!$sonod_number) {
        renderError(400,'error: sonod_number is required.');
    }
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? null;
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;
    $application = $appmanager->getapplicationbysonodnumber($sonod_number, $certificate_type);
    if (!$application) {
        renderError(404,'error: no application found for the given sonod_number.');
    }
    // Fetch union info
    $union = null;
    if (!empty($application['union_id'])) {
        $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
        $stmt->bind_param("i", $application['union_id']);
        $stmt->execute();
        $union = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $approval = !empty($application['application_id']) ? $appmanager->getApprovalByApplicationId($application['application_id']) : null;
    $business_meta = ($certificate_type === 'trade' && !empty($application['application_id'])) ? $appmanager->getBusinessMetaByApplicationId($application['application_id']) : null;
    if (!empty($application['application_id'])) {
        $members = $appmanager->getMembersByApplication($application['application_id']);
        if (!empty($members)) $application['warish_members'] = $members;
    }
    if (!empty($application['extra_data'])) $application['extra'] = json_decode($application['extra_data'], true);
    $template = templatePath('applications/online-verify/bangla', $application['certificate_type']);
    echo $twig->render($template, [
        'title' => 'যাচাই',
        'header_title' => 'যাচাই',
        'citizen' => data($application),
        'union' => $union,
        'certificate_type' => $certificate_type,
        'certificate_type_bn' => $certificate_type_bn,
        'union_code' => $union_code,
        'rmo_code' => $rmo_code,
        'approval' => $approval,
        'business_meta' => $business_meta,
    ]);
});

$router->get('/verify/{url_path}_en/{sonod_number}/{union_code}/{rmo_code}', function($url_path, $sonod_number = null, $union_code = null, $rmo_code = null) use ($twig, $appmanager, $mysqli) {
    if (!$sonod_number) {
        renderError(400,'error: sonod_number is required.');
    }
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? null;
    $certificate_type_en = $twig->getGlobals()['certificate_type_en'] ?? null;
    $application = $appmanager->getapplicationbysonodnumber($sonod_number, $certificate_type);
    if (!$application) {
        renderError(404,'error: no application found for the given sonod_number.');
    }
    // Fetch union info
    $union = null;
    if (!empty($application['union_id'])) {
        $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
        $stmt->bind_param("i", $application['union_id']);
        $stmt->execute();
        $union = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $approval = !empty($application['application_id']) ? $appmanager->getApprovalByApplicationId($application['application_id']) : null;
    $business_meta = ($certificate_type === 'trade' && !empty($application['application_id'])) ? $appmanager->getBusinessMetaByApplicationId($application['application_id']) : null;
    if (!empty($application['application_id'])) {
        $members = $appmanager->getMembersByApplication($application['application_id']);
        if (!empty($members)) $application['warish_members'] = $members;
    }
    if (!empty($application['extra_data'])) $application['extra'] = json_decode($application['extra_data'], true);
    $template = templatePath('applications/online-verify/bangla', $application['certificate_type']);
    echo $twig->render($template, [
        'title' => 'যাচাই',
        'header_title' => 'যাচাই',
        'citizen' => data($application),
        'union' => $union,
        'certificate_type' => $certificate_type,
        'certificate_type_en' => $certificate_type_en,
        'union_code' => $union_code,
        'rmo_code' => $rmo_code,
        'approval' => $approval,
        'business_meta' => $business_meta,
    ]);
});

$router->get('/applications/{certificate_type}', function($certificate_type = null) use ($twig, $auth, $mysqli) {
    ensure_can('manage_applications', 'applications');
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    
    // Fetch all unions from database
    $unions = [];
    $result = $mysqli->query("SELECT * FROM unions ORDER BY union_name_en ASC");
    if ($result) {
        $unions = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    echo $twig->render('applications/application_lists.twig', [
        'title' => 'আবেদন তালিকা',
        'header_title' => 'আবেদন তালিকা',
        'union_id' => $union_id,
        'unions' => $unions,
    ]);
});

$router->get('/application/{certificate_type}/bangla/{sonod_number}', function($certificate_type = null, $sonod_number = null) use ($twig, $appmanager, $mysqli) {
    if (empty($sonod_number)) die("error: sonod_number is required.");
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;
    $application = $appmanager->getapplicationbysonodnumber($sonod_number, $certificate_type);
    if (!$application) die("error: no application found for the given sonod_number.");
    
    // Fetch union info
    $union = null;
    if (!empty($application['union_id'])) {
        $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
        $stmt->bind_param("i", $application['union_id']);
        $stmt->execute();
        $union = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    
    $approval = !empty($application['application_id']) ? $appmanager->getApprovalByApplicationId($application['application_id']) : null;
    $business_meta = ($certificate_type === 'trade' && !empty($application['application_id'])) ? $appmanager->getBusinessMetaByApplicationId($application['application_id']) : null;
    if (!empty($application['application_id'])) {
        $members = $appmanager->getMembersByApplication($application['application_id']);
        if (!empty($members)) $application['warish_members'] = $members;
    }
    if (!empty($application['extra_data'])) $application['extra'] = json_decode($application['extra_data'], true);
    $template = templatePath('applications/certificate/bangla', $application['certificate_type']);
    $htmlcontent = $twig->render($template, [
        'title' => 'আবেদন সনদ',
        'header_title' => 'আবেদন সনদ',
        'data' => Data($application),
        'union' => $union,
        'approval' => $approval,
        'business_meta' => $business_meta,
        'certificate_type' => $certificate_type,
        'certificate_type_bn' => $certificate_type_bn,
    ]);
    makePdf($htmlcontent, "application-certificate");
});

$router->get('/application/{certificate_type}/english/{sonod_number}', function($certificate_type = null, $sonod_number = null) use ($twig, $appmanager, $mysqli) {
    if (empty($sonod_number)) die("error: sonod_number is required.");
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
    $certificate_type_en = $twig->getGlobals()['certificate_type_en'] ?? null;
    $application = $appmanager->getapplicationbysonodnumber($sonod_number, $certificate_type);
    if (!$application) die("error: no application found for the given sonod_number.");
    
    // Fetch union info
    $union = null;
    if (!empty($application['union_id'])) {
        $stmt = $mysqli->prepare("SELECT * FROM unions WHERE union_id = ? LIMIT 1");
        $stmt->bind_param("i", $application['union_id']);
        $stmt->execute();
        $union = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    
    $approval = !empty($application['application_id']) ? $appmanager->getApprovalByApplicationId($application['application_id']) : null;
    $business_meta = ($certificate_type === 'trade' && !empty($application['application_id'])) ? $appmanager->getBusinessMetaByApplicationId($application['application_id']) : null;
    if (!empty($application['application_id'])) {
        $members = $appmanager->getMembersByApplication($application['application_id']);
        if (!empty($members)) $application['warish_members'] = $members;
    }
    if (!empty($application['extra_data'])) $application['extra'] = json_decode($application['extra_data'], true);
    $template = templatePath('applications/certificate/english', $application['certificate_type']);
    $htmlcontent = $twig->render($template, [
        'title' => 'আবেদন সনদ',
        'header_title' => 'আবেদন সনদ',
        'data' => Data($application),
        'union' => $union,
        'approval' => $approval,
        'business_meta' => $business_meta,
        'certificate_type' => $certificate_type,
        'certificate_type_en' => $certificate_type_en,
    ]);
    makePdf($htmlcontent, "application-certificate");
});

$router->get('/applications/{certificate_type}/view/{application_id}', function($certificate_type = null, $application_id = null) use ($appmanager, $twig, $auth, $unionModel) {
    ensure_can('manage_applications', 'applications');
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $isSuperAdmin = !empty($user['is_superadmin']);
    $application = getFullApplicationData($application_id, $isSuperAdmin ? null : $union_id, true);
    if (!$application) renderError(404,"Application not found.");
    if (!$isSuperAdmin && $application['union_id'] != $union_id) { die("আপনার এই আবেদন দেখার অনুমতি নেই।"); }
    [$union, $union_code] = $unionModel->getInfo($application['union_id']);
    $documents = isset($application['existing_documents']) ? json_decode($application['existing_documents'], true) : [];
    echo $twig->render('applications/view-submitted.twig', [
        'title' => 'আবেদন দেখুন',
        'header_title' => 'আবেদন দেখুন',
        'application_details' => $application,
        'documents' => $documents,
        'union_code' => $union_code,
        'extra_data' => $application['extra_data'] ?? [],
    ]);
});



$router->get('/applications/{certificate_type}/reject/{application_id}', function($certificate_type = null, $application_id = null) {
    if (function_exists('applicationRejectForm')) {
        applicationRejectForm($certificate_type, $application_id);
    } else {
        renderError(500, 'applicationRejectForm function not found');
    }
});

$router->get('/applications/{certificate_type}/api/{application_id}', function($certificate_type = null, $application_id = null) use ($appmanager, $auth) {
    header('Content-Type: application/json; charset=utf-8');
    ensure_can('manage_applications', 'applications');
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    if (empty($application_id) || !is_numeric($application_id)) {
        echo json_encode(['error' => 'Invalid or missing application ID']); exit;
    }
    $data = $appmanager->getApplicationByApplicationId($application_id, $union_id);
    if (!$data) { echo json_encode(['error' => 'Application not found']); exit; }
    echo json_encode($data); exit;
});

$router->get('/applications/of/{applicant_id}', function($applicant_id) use ($twig, $crypt, $auth, $appmanager) {
    ensure_can('manage_applications', 'applications');
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $appData = $appmanager->getApplicationsByApplicantId($applicant_id, $offset, $limit);
    $applications = $appData['applications'] ?? [];
    $total_pages = $appData['total_pages'] ?? 1;
    echo $twig->render('applications/appListByapplicant.twig', [
        'title' => 'আবেদন তালিকা',
        'header_title' => 'আবেদন তালিকা',
        'union_id' => $union_id,
        'applications' => $applications,
        'total_pages' => $total_pages,
        'page' => $page
    ]);
});





$router->post('/applications/{certificate_type}/reject/{application_id}', function($certificate_type = null, $application_id = null) use ($appmanager, $auth) {
    ensure_can('manage_applications', 'applications');
    $reject_reason = sanitize_input($_POST['reject_reason'] ?? '');
    if (empty($reject_reason)) { echo json_encode(['status' => 'error', 'message' => 'Reject reason is required!']); return; }
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $application = $appmanager->getApplication($application_id, $union_id);
    $certificate_type = isset($application['certificate_type']) ? $application['certificate_type'] : 'application';
    $data = ['reject_reason' => $reject_reason, 'status' => 'Rejected', 'approval_status' => 'Rejected', 'certificate_type' => $certificate_type];
    $result = $appmanager->rejectApplication($application_id, $data, $certificate_type, $union_id);
    echo json_encode($result);
});

$router->post('/applications/{certificate_type}/delete', function($certificate_type = null) {
    if (function_exists('deleteApplication')) {
        deleteApplication($certificate_type);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'deleteApplication function not found']);
    }
});

$router->post('/applications/{certificate_type}/fetch_all', function($certificate_type = null) use ($appmanager, $auth, $mysqli) {
    header('Content-Type: application/json');
    ensure_can('manage_applications', 'applications');
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $search = isset($_POST['search']) ? sanitize_input($_POST['search']) : '';
    $sort_by = isset($_POST['sort_by']) ? sanitize_input($_POST['sort_by']) : 'application_id';
    $sort_order = isset($_POST['sort_order']) && strtolower($_POST['sort_order']) === 'asc' ? 'ASC' : 'DESC';
    $records_per_page = isset($_POST['records_per_page']) ? intval($_POST['records_per_page']) : 10;
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    require_once __DIR__ . '/../classes/RolesManager.php';
    $rolesManager = new RolesManager($mysqli);
    $roleId = $user['role_id'] ?? null;
    if (($roleId !== null && $roleId <= 1) && !empty($_POST['union_id'])) {
        $tmp = filter_var($_POST['union_id'], FILTER_VALIDATE_INT);
        if ($tmp !== false) $union_id = $tmp;
    }
    $result = $appmanager->fetchAllApplications($union_id, $page, $search, $records_per_page, $sort_by, $sort_order, $certificate_type);
    echo json_encode($result);
});

$router->post('/applications/{certificate_type}/fetch_existing', function($certificate_type = null) {
    if (function_exists('fetchApplicationById')) {
        fetchApplicationById($certificate_type);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'fetchApplicationById function not found']);
    }
});

$router->post('/applications/{certificate_type}/on_hold', function($certificate_type = null) use ($mysqli, $auth, $appmanager) {
    ensure_can('manage_applications', 'applications');
    $application_id = isset($_POST['id']) ? sanitize_input($_POST['id']) : null;
    $note = isset($_POST['note']) ? sanitize_input($_POST['note']) : null;
    if (!$application_id) { echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']); return; }
    if (!$note) { echo json_encode(['status' => 'error', 'message' => 'Hold note is required.']); return; }
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $result = $appmanager->setApplicationOnHold($application_id, $note, $union_id);
    echo json_encode($result);
});

$router->post('/applications/{certificate_type}/reactivate', function($certificate_type = null) use ($mysqli, $auth, $appmanager) {
    ensure_can('manage_applications', 'applications');
    $application_id = isset($_POST['id']) ? sanitize_input($_POST['id']) : null;
    if (!$application_id) { echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']); return; }
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $result = $appmanager->reactivateApplication($application_id, $union_id);
    echo json_encode($result);
});

$router->post('/applications/{certificate_type}/fix_sonod_status', function($certificate_type = null) use ($mysqli, $auth, $appmanager, $unionModel) {
    ensure_can('manage_applications', 'applications');
    $application_id = isset($_POST['application_id']) ? sanitize_input($_POST['application_id']) : null;
    if (!$application_id) { echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']); return; }
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $application = $appmanager->getApplication($application_id, $union_id);
    if (!$application) { echo json_encode(['status' => 'error', 'message' => 'Application not found.']); return; }
    $status = $application['status'] ?? '';
    $sonod_number = $application['sonod_number'] ?? '';
    $union_code = null;
    if (isset($application['union_id'])) {
        $union = $unionModel->getById($application['union_id']);
        $union_code = $union['union_code'] ?? null;
    }
    $update_needed = false;
    $new_sonod_number = $sonod_number;
    $new_status = $status;
    if ($status === 'Approved' && empty($sonod_number)) {
        $new_sonod_number = generateSonodNumber('applications', $union_code);
        $update_needed = true;
    } elseif (!empty($sonod_number) && (empty($status) || strtolower($status) === 'pending')) {
        $new_status = 'Approved';
        $update_needed = true;
    }
    if ($update_needed) {
        $update_result = $appmanager->updateSonodStatus($application_id, $union_id, $new_sonod_number, $new_status);
        if ($update_result) echo json_encode(['status' => 'success', 'message' => 'Updated successfully', 'sonod_number' => $new_sonod_number, 'status_val' => $new_status]);
        else echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    } else {
        echo json_encode(['status' => 'info', 'message' => 'No update needed']);
    }
});

$router->post('/api/applications/{certificate_type}/applicant', function($certificateType = null) use ($mysqli, $appmanager, $auth, $twig) {
    header('Content-Type: application/json');
    $applicant_id = sanitize_input($_POST['applicant_id'] ?? '');
    if (empty($applicant_id) || !is_numeric($applicant_id)) { echo json_encode(['success' => false, 'message' => 'Invalid or missing applicant ID.']); exit; }
    $applicant_id = intval($applicant_id);
    if (!$applicant_id) { echo json_encode(['success' => false, 'message' => 'Invalid applicant ID.']); exit; }
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificateType;
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;
    $appmanager = new ApplicationManager($mysqli);
    $application = $appmanager->getLatestApplicationByApplicantId($applicant_id);
    if (!$application) { echo json_encode(['success' => false, 'message' => 'No data found for this applicant.']); exit; }
    $allTypes = $appmanager->getAllCertificateTypes();
    echo json_encode(['success' => true, 'certificate_types' => $allTypes, 'application_data' => $application]);
});

// Try to set certificate type from URL if needed
if (function_exists('trySetCertificateTypeFromURL')) {
    trySetCertificateTypeFromURL();
}
