<?php
/**
 * controllers/ApplicationController.php
 * 
 * Certificate application routes - uses ApplicationService and ApplicationManager.
 * No inline SQL, no helper function definitions, no repetitive code.
 */

global $crypt, $mysqli, $twig, $router;

$authService = new AuthService($mysqli);
$applicationService = new ApplicationService($mysqli);
$appService = $applicationService;
$appManager = $appService->getAppManager();
$appmanager = $appManager;
$unionModel = new UnionModel($mysqli);
$auth = $auth ?? new AuthManager($mysqli);

if (!isset($crypt)) {
    $crypt = get_crypt_manager();
}

// ================================================================
// APPLICATION TYPE LIST
// ================================================================
$router->get('/applications', function() use ($twig, $appManager, $auth, $authService) {
    $authService->ensureCan('manage_applications', 'applications');
    $user = $auth->getUserData(false);
    $types = $appManager->CertificateTypeLists($user['union_id'] ?? null);

    echo $twig->render('applications/types_list.twig', [
        'types' => $types,
        'title' => 'আবেদনের তালিকা',
        'header_title' => 'আবেদন তালিকা',
    ]);
});

// ================================================================
// RE-APPLY FROM EXISTING
// ================================================================
$router->get('/{certificate_type}/apply/from/{applicant_id}', function($certificate_type, $applicant_id) use ($twig, $appManager, $mysqli, $appService) {
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
    $reuse_data = $appManager->getLatestApplicationByApplicantId(sanitize_input($applicant_id));

    if (!$reuse_data) {
        echo $twig->render('errors/404.twig', ['message' => 'Applicant not found.']);
        return;
    }

    $template = $appService->resolveTemplate('applications/forms', $certificate_type, 'reapply.twig');

    if ($certificate_type === 'trade') {
        $businessOwnership = new BusinessOwnershipType($mysqli);
        $reuse_data['business_types'] = $businessOwnership->getBusinessTypes();
        $reuse_data['ownership_types'] = $businessOwnership->getOwnershipTypes();
        $reuse_data['fiscal_year_options'] = $appService->generateFiscalYearOptions(
            $reuse_data['business_meta']['fiscal_year'] ?? null
        );
    }

    // Decode extra_data for the reapply template
    $extra_data = !empty($reuse_data['extra_data'])
        ? (is_string($reuse_data['extra_data']) ? json_decode($reuse_data['extra_data'], true) : $reuse_data['extra_data'])
        : [];

    echo $twig->render($template, [
        'reuse_data' => $reuse_data,
        'certificate_type' => $certificate_type,
        'certificate_type_bn' => $twig->getGlobals()['certificate_type_bn'] ?? null,
        'extra_data' => $extra_data,
        'title' => 'আবেদন ফর্ম পূরণ করুন',
        'header_title' => 'আবেদন ফর্ম পূরণ করুন',
    ]);
});

// ================================================================
// VERIFY APPLICATION & GENERATE PDF
// ================================================================

$verifyHandler = function($url_path, $application_id, $union_code = null, $rmo_code = null) use ($twig, $appService) {
    $application = $appService->getApplicationById($application_id);
    if (!$application) {
        die("Error: No application found for the given application_id.");
    }

    $union = !empty($application['union_id']) ? $appService->getUnionById((int)$application['union_id']) : null;
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $application['certificate_type'];
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;
    $documents = $appService->parseDocuments($application['existing_documents'] ?? null);
    $application = $appService->prepareApplicationData($application);

    // Resolve template — fall back to default if custom one doesn't exist
    $template = "applications/application-copy.twig";
    if (!empty($certificate_type) && $certificate_type !== 'application') {
        $custom_template = "applications/{$certificate_type}-copy.twig";
        $custom_template_path = __DIR__ . "/../templates/{$custom_template}";
        if (file_exists($custom_template_path)) {
            $template = $custom_template;
        }
    }

    $viewData = $appService->buildCertificateViewData($application, $union, [
        'title' => 'আবেদনের কপি',
        'header_title' => 'আবেদনের কপি',
        'certificate_type' => $certificate_type,
        'certificate_type_bn' => $certificate_type_bn,
        'union_code' => $union_code,
        'rmo_code' => $rmo_code,
        'documents' => $documents,
    ]);

    $htmlContent = $twig->render($template, $viewData);
    $appService->generateCertificatePdf($htmlContent, $certificate_type . '_' . $application_id);
};

$router->get('/{url_path}_verify/application/{application_id}', $verifyHandler);
$router->get('/{url_path}_verify/application/{application_id}/{union_code}/{rmo_code}', $verifyHandler);

// ================================================================
// ONLINE VERIFY (Bangla)
// ================================================================


// ================================================================
// ONLINE VERIFY (English)
// ================================================================


// ================================================================
// APPLICATION LIST BY TYPE
// ================================================================
$router->get('/applications/{certificate_type}', function($certificate_type = null) use ($twig, $auth, $unionModel, $authService) {
    $authService->ensureCan('manage_applications', 'applications');

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $unions = $unionModel->getAllUnions();

    echo $twig->render('applications/application_lists.twig', [
        'title' => 'আবেদন তালিকা',
        'header_title' => 'আবেদন তালিকা',
        'union_id' => $union_id,
        'unions' => $unions,
    ]);
});

// ================================================================
// CERTIFICATE (Bangla)
// ================================================================
$router->get('/application/{certificate_type}/bangla/{sonod_number}', function($certificate_type = null, $sonod_number = null) use ($twig, $appService) {
    if (empty($sonod_number)) die("error: sonod_number is required.");

    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;
    $application = $appService->getApplicationBySonodNumber($sonod_number, $certificate_type);

    if (!$application) die("error: no application found for the given sonod_number.");

    $union = !empty($application['union_id']) ? $appService->getUnionById((int)$application['union_id']) : null;
    $template = $appService->resolveTemplate('applications/certificate/bangla', $application['certificate_type']);

    $viewData = $appService->buildCertificateViewData($application, $union, [
        'title' => 'আবেদন সনদ',
        'header_title' => 'আবেদন সনদ',
        'certificate_type' => $certificate_type,
        'certificate_type_bn' => $certificate_type_bn,
    ]);

    $htmlContent = $twig->render($template, $viewData);
    $appService->makeCertificatePdf($htmlContent, $application['certificate_type'] . '_' . $application['sonod_number']);
});

// ================================================================
// CERTIFICATE (English)
// ================================================================
$router->get('/application/{certificate_type}/english/{sonod_number}', function($certificate_type = null, $sonod_number = null) use ($twig, $appService) {
    if (empty($sonod_number)) die("error: sonod_number is required.");

    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
    $certificate_type_en = $twig->getGlobals()['certificate_type_en'] ?? null;
    $application = $appService->getApplicationBySonodNumber($sonod_number, $certificate_type);

    if (!$application) die("error: no application found for the given sonod_number.");

    $union = !empty($application['union_id']) ? $appService->getUnionById((int)$application['union_id']) : null;
    $template = $appService->resolveTemplate('applications/certificate/english', $application['certificate_type']);

    $viewData = $appService->buildCertificateViewData($application, $union, [
        'title' => 'আবেদন সনদ',
        'header_title' => 'আবেদন সনদ',
        'certificate_type' => $certificate_type,
        'certificate_type_en' => $certificate_type_en,
    ]);

    $htmlContent = $twig->render($template, $viewData);
    $appService->makeCertificatePdf($htmlContent, $application['certificate_type'] . '_' . $application['sonod_number']);
});

// ================================================================
// VIEW SUBMITTED APPLICATION
// ================================================================
$router->get('/applications/{certificate_type}/view/{application_id}', function($certificate_type = null, $application_id = null) use ($appService, $twig, $auth, $unionModel, $authService) {
    $authService->ensureCan('manage_applications', 'applications');

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $isSuperAdmin = !empty($user['is_superadmin']);

    $application = $appService->getFullApplicationData($application_id, $isSuperAdmin ? null : $union_id, true);
    if (!$application) renderError(404, "Application not found.");
    if (!$isSuperAdmin && $application['union_id'] != $union_id) {
        die("আপনার এই আবেদন দেখার অনুমতি নেই।");
    }

    [$union, $union_code] = $unionModel->getInfo($application['union_id']);
    $documents = $appService->parseDocuments($application['existing_documents'] ?? null);

    echo $twig->render('applications/view-submitted.twig', [
        'title' => 'আবেদন দেখুন',
        'header_title' => 'আবেদন দেখুন',
        'application_details' => $application,
        'documents' => $documents,
        'union_code' => $union_code,
        'extra_data' => $application['extra_data'] ?? [],
    ]);
});

// ================================================================
// REJECT APPLICATION (GET)
// ================================================================
$router->get('/applications/{certificate_type}/reject/{application_id}', function($certificate_type = null, $application_id = null) use ($auth, $appService, $authService) {
    $authService->ensureCan('manage_applications', 'applications');

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $result = $appService->rejectApplication($application_id, 'পুনঃবিবেচনার জন্য প্রত্যাখ্যাত', $union_id, $certificate_type);
    if ($result['status'] !== 'success') {
        renderError(500, $result['message']);
    }
});

// ================================================================
// API: Get single application
// ================================================================
$router->get('/applications/{certificate_type}/api/{application_id}', function($certificate_type = null, $application_id = null) use ($appService, $auth, $authService) {
    header('Content-Type: application/json; charset=utf-8');
    $authService->ensureCan('manage_applications', 'applications');

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    if (empty($application_id)) {
        echo json_encode(['error' => 'Invalid or missing application ID']);
        exit;
    }

    $data = $appService->getApplicationById($application_id, $union_id);
    if (!$data) {
        echo json_encode(['error' => 'Application not found']);
        exit;
    }

    echo json_encode($data);
    exit;
});

// ================================================================
// APPLICATIONS BY APPLICANT
// ================================================================
$router->get('/applications/of/{applicant_id}', function($applicant_id) use ($twig, $crypt, $auth, $appManager, $authService) {
    $authService->ensureCan('manage_applications', 'applications');

    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 10;
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $appData = $appManager->getApplicationsByApplicantId($applicant_id, ($page - 1) * $limit, $limit);

    echo $twig->render('applications/appListByapplicant.twig', [
        'title' => 'আবেদন তালিকা',
        'header_title' => 'আবেদন তালিকা',
        'union_id' => $union_id,
        'applications' => $appData['applications'] ?? [],
        'total_pages' => $appData['total_pages'] ?? 1,
        'page' => $page
    ]);
});

// ================================================================
// LICENSE RENEWAL HISTORY
// ================================================================
$router->get('/applications/trade/renewal-history/{application_id}', function($application_id = null) use ($twig, $appManager, $auth, $authService) {
    $authService->ensureCan('approve', 'applications');

    if (!$application_id) {
        echo $twig->render('errors/404.twig', ['message' => 'Application ID is required.']);
        return;
    }

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $application = $appManager->getApplicationByApplicationId($application_id, $union_id);
    if (!$application || $application['certificate_type'] !== 'trade') {
        echo $twig->render('errors/404.twig', ['message' => 'Trade license not found.']);
        return;
    }

    $business_meta = $appManager->getBusinessMetaByApplicationId($application_id);
    $renewal_history = $appManager->getLicenseHistory($application_id);
    $approval = $appManager->getApprovalByApplicationId($application_id);
    $expiry_info = $appManager->getLicenseExpiryInfo($application_id);

    echo $twig->render('applications/license-renewal-history.twig', [
        'application' => $application,
        'business_meta' => $business_meta,
        'renewal_history' => $renewal_history,
        'renewal_count' => $approval['renewal_count'] ?? 0,
        'expiry_info' => $expiry_info,
        'can_renew' => true,
        'title' => 'লাইসেন্স নবায়ন ইতিহাস',
        'header_title' => 'লাইসেন্স নবায়ন ইতিহাস'
    ]);
});

// ================================================================
// POST : REJECT APPLICATION
// ================================================================
$router->post('/applications/{certificate_type}/reject/{application_id}', function($certificate_type = null, $application_id = null) use ($auth, $appService, $authService) {
    $authService->ensureCan('manage_applications', 'applications');

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $result = $appService->rejectApplicationPost($application_id, sanitize_input($_POST['reject_reason'] ?? ''), $union_id);
    echo json_encode($result);
});

// ================================================================
// POST : DELETE APPLICATION
// ================================================================


// ================================================================
// POST : FETCH ALL APPLICATIONS
// ================================================================
$router->post('/applications/{certificate_type}/fetch_all', function($certificate_type = null) use ($auth, $appService, $authService) {
    header('Content-Type: application/json');
    $authService->ensureCan('manage_applications', 'applications');

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $roleId = $user['role_id'] ?? null;

    $result = $appService->fetchApplicationsList($_POST, $union_id, $roleId, $certificate_type);
    echo json_encode($result);
});

// ================================================================
// POST : FETCH EXISTING APPLICATION
// ================================================================
$router->post('/applications/{certificate_type}/fetch_existing', function($certificate_type = null) use ($auth, $appService) {
    header('Content-Type: application/json; charset=utf-8');

    $application_id = sanitize_input($_POST['application_id'] ?? $_POST['id'] ?? '');
    if (!$application_id) {
        echo json_encode(['status' => 'error', 'message' => 'অ্যাপ্লিকেশন আইডি প্রয়োজন।']);
        return;
    }

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $isSuperAdmin = (isset($user['role_id']) && $user['role_id'] <= 1);

    $data = $appService->fetchExistingApplication($application_id, $union_id, $isSuperAdmin);
    if (!$data) {
        echo json_encode(['status' => 'error', 'message' => 'আবেদন পাওয়া যায়নি।']);
        return;
    }
    echo json_encode(['status' => 'success', 'data' => $data]);
});

// ================================================================
// POST : ON HOLD / REACTIVATE / FIX SONOD STATUS
// ================================================================
$router->post('/applications/{certificate_type}/on_hold', function($certificate_type = null) use ($appManager, $auth, $authService) {
    $authService->ensureCan('manage_applications', 'applications');

    $application_id = sanitize_input($_POST['id'] ?? '');
    $note = sanitize_input($_POST['note'] ?? '');

    if (!$application_id) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']);
        return;
    }
    if (!$note) {
        echo json_encode(['status' => 'error', 'message' => 'Hold note is required.']);
        return;
    }

    $user = $auth->getUserData(false);
    $result = $appManager->setApplicationOnHold($application_id, $note, $user['union_id'] ?? null);
    echo json_encode($result);
});

$router->post('/applications/{certificate_type}/reactivate', function($certificate_type = null) use ($appManager, $auth, $authService) {
    $authService->ensureCan('manage_applications', 'applications');

    $application_id = sanitize_input($_POST['id'] ?? '');
    if (!$application_id) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']);
        return;
    }

    $user = $auth->getUserData(false);
    $result = $appManager->reactivateApplication($application_id, $user['union_id'] ?? null);
    echo json_encode($result);
});

$router->post('/applications/{certificate_type}/fix_sonod_status', function($certificate_type = null) use ($auth, $appService, $authService) {
    $authService->ensureCan('manage_applications', 'applications');

    $application_id = sanitize_input($_POST['application_id'] ?? '');
    if (!$application_id) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']);
        return;
    }

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $result = $appService->fixSonodStatus($application_id, $union_id);
    echo json_encode($result);
});

// ================================================================
// POST : API APPLICANT LOOKUP
// ================================================================
$router->post('/api/applications/{certificate_type}/applicant', function($certificateType = null) use ($mysqli, $appManager, $auth, $twig) {
    header('Content-Type: application/json');

    $applicant_id = sanitize_input($_POST['applicant_id'] ?? '');
    if (empty($applicant_id) || !is_numeric($applicant_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing applicant ID.']);
        exit;
    }

    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificateType;
    $application = $appManager->getLatestApplicationByApplicantId((int)$applicant_id);

    if (!$application) {
        echo json_encode(['success' => false, 'message' => 'No data found for this applicant.']);
        exit;
    }

    $allTypes = $appManager->getAllCertificateTypes();
    echo json_encode(['success' => true, 'certificate_types' => $allTypes, 'application_data' => $application]);
});

// Try to set certificate type from URL
if (function_exists('trySetCertificateTypeFromURL')) {
    trySetCertificateTypeFromURL();
}


/**
 * Merged V2 application routes
 * 
 * Certificate application routes (V2) - pure closures using ApplicationService.
 * No inline SQL queries. All DB operations delegated to models/services.
 * All common lookups (union name, cert type name, business fees, union members)
 * handled by ApplicationService.
 */

// SEARCH ROUTE
// ================================================================

$router->any('/api/applications/search', function() use ($appmanager, $applicationService) {
    header('Content-Type: application/json; charset=utf-8');

    $union_code = sanitize_input($_POST['union_code'] ?? '');
    $union = getUnionByCode($union_code);
    $union_id = $union['union_id'] ?? null;

    $identifier = trim($_POST['query'] ?? '');
    if (!$identifier) {
        echo json_encode(['status' => 'error', 'message' => 'সার্চ ভ্যালু প্রদান করুন']);
        return;
    }

    $identifier = convertBanglaToEnglishNumber(sanitize_input($identifier));

    // Step 1: Try local database search
    $application = $appmanager->findApplicationByIdentifier($identifier, $union_id);
    if ($application) {
        $application['union_name_bn'] = !empty($application['union_id'])
            ? $applicationService->getUnionNameById((int)$application['union_id'])
            : '';

        $dbCertType = $application['certificate_type'] ?? '';
        $ctBn = $dbCertType ? $applicationService->getCertificateTypeName($dbCertType) : $dbCertType;
        $application['certificate_type_bn'] = $ctBn ?: $dbCertType;
        $application['source'] = 'local';

        echo json_encode(['status' => 'success', 'data' => $application]);
        return;
    }

    // Step 2: Fallback — search remote admin API via service
    $remoteData = $applicationService->remoteSearch($identifier, $union_id, sanitize_input($_POST['certificate_type'] ?? ''));
    if ($remoteData) {
        echo json_encode(['status' => 'success', 'data' => $remoteData]);
        return;
    }

    echo json_encode(['status' => 'error', 'message' => 'কোনো তথ্য পাওয়া যায়নি']);
});

// ================================================================
// CHECK EXISTING APPLICATION (API)
// ================================================================

$router->post('/api/check/existing/application', function() use ($appmanager) {
    header('Content-Type: application/json; charset=utf-8');
    
    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        $input = $_POST;
    }
    
    $searchData = sanitize_input($input['searchData'] ?? '');
    $applicationType = sanitize_input($input['applicationType'] ?? '');  // '1' = application, '2' = certificate
    $type = sanitize_input($input['type'] ?? '');  // numeric certificate type ID
    
    if (empty($searchData) || empty($applicationType)) {
        echo json_encode(['status' => 'error', 'message' => 'অনুগ্রহ করে সব তথ্য পূরণ করুন।']);
        return;
    }
    
    if ($applicationType === '2') {
        // Search by certificate (sonod_number)
        $application = $appmanager->getApplicationBySonodNumber($searchData);
        
        if ($application && !empty($application['sonod_number'])) {
            echo json_encode([
                'status' => 'success',
                'message' => 'সনদ পাওয়া গেছে',
                'data' => [
                    'sonod_no' => $application['sonod_number'],
                    'pin' => $application['application_id'],
                    'union_id' => $application['union_id'] ?? '',
                    'type' => $type,
                    'tracking' => $application['application_id'],
                ]
            ]);
            return;
        }
        
        echo json_encode(['status' => 'error', 'message' => 'দুঃখিত! আপনার সনদটি পাওয়া যায়নি।']);
        
    } else {
        // Search by application (tracking number / applicant ID)
        $application = $appmanager->getApplicationByApplicationId($searchData);
        
        if (!$application) {
            $application = $appmanager->findApplicationByIdentifier($searchData);
        }
        
        if ($application) {
            echo json_encode([
                'status' => 'success',
                'message' => 'আবেদন পাওয়া গেছে',
                'data' => [
                    'tracking' => $application['application_id'],
                    'pin' => $application['application_id'],
                    'union_id' => $application['union_id'] ?? '',
                    'type' => $type,
                    'sonod_no' => $application['sonod_number'] ?? '',
                ]
            ]);
            return;
        }
        
        echo json_encode(['status' => 'error', 'message' => 'দুঃখিত! আপনার আবেদনটি পাওয়া যায়নি।']);
    }
});

// ================================================================
// V2 APPLICATION SEARCH (API) — GET with query params
// ================================================================

$router->get('/api/v2/applications/search', function() use ($mysqli, $appmanager, $applicationService) {
    header('Content-Type: application/json; charset=utf-8');
    
    $query = trim($_GET['query'] ?? '');
    $district = sanitize_input($_GET['district'] ?? '');
    $upazila = sanitize_input($_GET['upazila'] ?? '');
    $union = sanitize_input($_GET['union'] ?? '');
    
    if (empty($query)) {
        echo json_encode(['status' => 'error', 'data' => [], 'message' => 'সার্চ আইডি দিন']);
        return;
    }
    
    // Convert Bengali numbers to English
    $identifier = convertBanglaToEnglishNumber($query);
    
    // Resolve union_id from filter parameters
    $unionId = null;
    if (!empty($union)) {
        $stmt = $mysqli->prepare(
            "SELECT union_id FROM unions WHERE (union_id = ? OR union_code = ? OR union_name_bn = ? OR union_name_en = ?) LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('ssss', $union, $union, $union, $union);
            $stmt->execute();
            $stmt->bind_result($foundId);
            if ($stmt->fetch()) {
                $unionId = $foundId;
            }
            $stmt->close();
        }
    }
    
    // Broader search: returns multiple results from name/id LIKE search
    $applications = $appmanager->searchApplications($identifier, $unionId);
    
    $results = [];
    foreach ($applications as $application) {
        $certType = $application['certificate_type'] ?? '';
        $certTypeBn = $certType ? $applicationService->getCertificateTypeName($certType) : $certType;
        
        $results[] = [
            'name_bn' => $application['name_bn'] ?? '',
            'father_name_bn' => $application['father_name_bn'] ?? '',
            'sonod_number' => $application['sonod_number'] ?? '',
            'certificate_type_bn' => $certTypeBn ?: $certType,
            'certificate_type' => $certType,
            'status' => $application['status'] ?? 'pending',
            'application_id' => $application['application_id'] ?? '',
        ];
    }
    
    echo json_encode($results);
});

// ================================================================
// CHECK LICENSE AVAILABILITY (API)
// ================================================================

$router->get('/api/check-license-availability', function() use ($appmanager) {
    header('Content-Type: application/json; charset=utf-8');    $sonod_number = sanitize_input($_GET['sonod_number'] ?? '');
    $certificate_type = sanitize_input($_GET['certificate_type'] ?? '');
    $exclude_application_id = sanitize_input($_GET['exclude_application_id'] ?? '');

    if (empty($sonod_number)) {
        echo json_encode([
            'available' => false,
            'existing_application_id' => null,
            'message' => 'সনদ নম্বর প্রদান করা হয়নি।'
        ]);
        return;
    }

    // 🔒 Validate sonod_number is exactly 17 digits
    $digitsOnly = preg_replace('/\D/', '', $sonod_number);
    if (strlen($digitsOnly) !== 17) {
        echo json_encode([
            'available' => false,
            'existing_application_id' => null,
            'message' => 'সনদ নম্বর অবশ্যই ১৭ অংকের হতে হবে। (Currently: ' . strlen($digitsOnly) . ' digits)'
        ]);
        return;
    }
    $sonod_number = $digitsOnly;

    // Look up the sonod_number in the database
    $existing = $appmanager->getApplicationBySonodNumber($sonod_number, $certificate_type ?: null);

    if ($existing && (!empty($exclude_application_id) && $existing['application_id'] === $exclude_application_id)) {
        // The only match is the current application itself — license is available for this one
        echo json_encode([
            'available' => true,
            'existing_application_id' => null,
            'message' => 'সনদ নম্বরটি ব্যবহারের জন্য উপলব্ধ।'
        ]);
        return;
    }

    if ($existing) {
        // License number already taken by another application
        echo json_encode([
            'available' => false,
            'existing_application_id' => $existing['application_id'],
            'message' => 'এই সনদ নম্বর (' . $sonod_number . ') ইতিমধ্যে আরেকটি আবেদনের জন্য ব্যবহৃত হয়েছে।'
        ]);
        return;
    }

    // License number is available
    echo json_encode([
        'available' => true,
        'existing_application_id' => null,
        'message' => 'সনদ নম্বরটি ব্যবহারের জন্য উপলব্ধ।'
    ]);
});

// ================================================================
// APPLY HANDLER
// ================================================================

$applyHandler = function($certificate_type = null) use ($twig, $auth, $applicationService, $mysqli) {
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $union = null;
    if ($union_id) {
        $union = $applicationService->getUnionById((int)$union_id);
    }

    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? '';
    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;

    $merged_data = [
        'union_id' => $union_id,
        'members' => [],
    ];

    if ($certificate_type === 'trade') {
        $businessOwnership = new BusinessOwnershipType($mysqli);
        $merged_data['business_types'] = $businessOwnership->getBusinessTypes();
        $merged_data['ownership_types'] = $businessOwnership->getOwnershipTypes();
        $merged_data['business_meta'] = [
            'business_name' => '',
            'business_type' => '',
            'ownership_type' => '',
            'business_address' => '',
        ];
    }

    $tpl = 'applications/forms/' . basename(($certificate_type ?? '')) . '.twig';
    if (!$twig->getLoader()->exists($tpl)) {
        $tpl = 'applications/forms/default.twig';
    }

    echo $twig->render($tpl, [
        'title'        => $certificate_type_bn . ' - নতুন আবেদন',
        'header_title' => $certificate_type_bn . ' - নতুন আবেদন',
        'data'         => $merged_data,
        'union'        => $union,
        'extra_data'   => [],
    ]);
};

// Register apply routes
$router->get('/{certificate_type}/apply', $applyHandler);

$router->get('/apply/{encrypted_token}', function($encrypted_token = null) use ($twig, $auth, $applicationService, $applyHandler) {
    if (empty($encrypted_token)) {
        renderError(404, 'Invalid application link.');
        return;
    }
    $crypt = get_crypt_manager();
    $decrypted = $crypt->decrypt($encrypted_token);
    if ($decrypted === false) {
        renderError(404, 'Invalid or expired application link.');
        return;
    }
    $certificate_type = sanitize_input($decrypted);

    $twig->addGlobal('certificate_type', $certificate_type);
    $certificate_type_bn = $applicationService->getCertificateTypeName($certificate_type);
    if ($certificate_type_bn) {
        $twig->addGlobal('certificate_type_bn', $certificate_type_bn);
    }

    $twig->addGlobal('show_breadcrumbs', true);
    $twig->addGlobal('breadcrumbs', [
        ['name' => 'হোম', 'url' => '/', 'icon' => 'fas fa-home'],
        ['name' => 'আবেদন'],
        ['name' => $certificate_type_bn ?: $certificate_type, 'is_active' => true],
    ]);

    $applyHandler($certificate_type);
});

// ================================================================
// POST APPLY ROUTE
// ================================================================

$router->post('/applications/{certificate_type}/apply', function($certificate_type = null) use ($applicationService) {
    header('Content-Type: application/json; charset=utf-8');

    $certificateType = $certificate_type ?: 'application';
    $result = $applicationService->submitApplication($_POST, $_FILES, $certificateType);

    echo json_encode($result);
});

// ================================================================
// EDIT ROUTES
// ================================================================

$router->get('/applications/{certificate_type}/edit/{application_id}', function($certificate_type = null, $application_id = null) use ($appmanager, $applicationService, $twig, $auth, $mysqli) {
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $application = $appmanager->getApplicationByApplicationId($application_id, $union_id);
    if (!$application) {
        renderError(404, 'Application not found');
    }

    $union = null;
    if (isset($application['union_id'])) {
        $union = $applicationService->getUnionById((int)$application['union_id']);
    }

    $merged_data = $application;

    $extra_data_json = $application['extra_data'] ?? '';
    $extra_data = !empty($extra_data_json) ? json_decode($extra_data_json, true) : [];

    if (($application['certificate_type'] ?? '') === 'trade') {
        $businessOwnership = new BusinessOwnershipType($mysqli);
        $merged_data['business_types'] = $businessOwnership->getBusinessTypes();
        $merged_data['ownership_types'] = $businessOwnership->getOwnershipTypes();
        $business_meta = $appmanager->getBusinessMetaByApplicationId($application_id) ?? [];
        $merged_data = array_merge($merged_data, $business_meta);
    }

    $merged_data['members'] = $appmanager->getMembersByApplication($application_id);

    $tpl = 'applications/forms/' . basename(($application['certificate_type'] ?? '')) . '-v2.twig';
    if (!$twig->getLoader()->exists($tpl)) {
        $tpl = 'applications/forms/default.twig';
    }

    echo $twig->render($tpl, [
        'title'            => 'আবেদন সম্পাদনা',
        'header_title'     => 'আবেদন সম্পাদনা',
        'data'             => $merged_data,
        'union'            => $union,
        'extra_data'       => $extra_data,
        'certificate_type' => $application['certificate_type'] ?? '',
    ]);
});

$router->post('/applications/{certificate_type}/edit/{application_id}', function($certificate_type = null, $application_id = null) use ($applicationService) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$application_id) {
        echo json_encode(['status' => 'error', 'message' => 'Application ID is required']);
        return;
    }

    $certificateType = $certificate_type ?: 'application';
    $result = $applicationService->updateApplication($application_id, $_POST, $_FILES, $certificateType);

    echo json_encode($result);
});

// ================================================================
// REAPPLY ROUTE
// ================================================================

$router->get('/applications/{certificate_type}/reapply/{applicant_id}', function($certificate_type = null, $applicant_id = null) use ($twig, $appmanager, $auth, $applicationService, $mysqli) {
    $applicant_id = sanitize_input($applicant_id);
    $reuse_data = $appmanager->getApprovedApplicationByApplicantId($applicant_id);

    if (!$reuse_data) {
        echo $twig->render('errors/error.twig', ['message' => 'Applicant not found.']);
        return;
    }

    $certificate_type = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
    if (empty($certificate_type) && !empty($reuse_data['certificate_type'])) {
        $certificate_type = $reuse_data['certificate_type'];
    }

    if ($certificate_type === 'trade') {
        $businessOwnership = new BusinessOwnershipType($mysqli);
        $reuse_data['business_types']   = $businessOwnership->getBusinessTypes();
        $reuse_data['ownership_types']  = $businessOwnership->getOwnershipTypes();
    }

    // Decode extra_data for the reapply template
    $extra_data = !empty($reuse_data['extra_data'])
        ? (is_string($reuse_data['extra_data']) ? json_decode($reuse_data['extra_data'], true) : $reuse_data['extra_data'])
        : [];

    echo $twig->render('applications/forms/default.twig', [
        'data'                  => $reuse_data,
        'reuse_mode'            => true,
        'certificate_type'      => $certificate_type,
        'certificate_type_bn'   => $twig->getGlobals()['certificate_type_bn'] ?? null,
        'extra_data'            => $extra_data,
        'title'                 => 'আবেদন ফর্ম পূরণ করুন',
        'header_title'          => 'আবেদন ফর্ম পূরণ করুন',
    ]);
});

// ================================================================
// APPROVE DATA API (for modal)
// ================================================================

$router->get('/api/applications/{certificate_type}/approve-data/{application_id}', function($certificate_type = null, $application_id = null) use ($twig, $auth, $authService, $applicationService) {
    header('Content-Type: application/json; charset=utf-8');
    $auth->requireLogin();
    $user      = $auth->getUserData(false);
    $union_id  = $user['union_id'] ?? null;

    $authService->ensureCan('manage_applications', 'applications');

    $certificate_type    = $twig->getGlobals()['certificate_type'] ?? $certificate_type;
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;

    $pageData = $applicationService->prepareApprovalPageData($application_id, $union_id, $certificate_type, $certificate_type_bn);

    if (isset($pageData['error'])) {
        echo json_encode(['status' => 'error', 'message' => $pageData['error']]);
        return;
    }

    // Flatten data for JSON response
    $app = $pageData['application'];
    $approval = $pageData['approval'] ?? [];
    $union = $pageData['union'] ?? null;
    $businessMeta = $app['business_meta'] ?? null;

    echo json_encode([
        'status' => 'success',
        'data' => [
            // Preserve the complete joined application row for the modal; the explicit fields below remain backwards compatible.
            '_all_fields'        => $app,
            'application_id'      => $app['application_id'] ?? '',
            'certificate_type'    => $app['certificate_type'] ?? '',
            'certificate_type_bn' => $app['certificate_type_bn'] ?? ($pageData['certificate_type_bn'] ?? ''),
            'status'              => $app['status'] ?? '',
            'name_bn'             => $app['name_bn'] ?? '',
            'name_en'             => $app['name_en'] ?? '',
            'father_name_bn'      => $app['father_name_bn'] ?? '',
            'father_name_en'      => $app['father_name_en'] ?? '',
            'mother_name_bn'      => $app['mother_name_bn'] ?? '',
            'mother_name_en'      => $app['mother_name_en'] ?? '',
            'spouse_name_bn'      => $app['spouse_name_bn'] ?? '',
            'spouse_name_en'      => $app['spouse_name_en'] ?? '',
            'nid'                 => $app['nid'] ?? '',
            'birth_id'            => $app['birth_id'] ?? '',
            'passport_no'         => $app['passport_no'] ?? '',
            'birth_date'          => $app['birth_date'] ?? '',
            'gender'              => $app['gender'] ?? '',
            'religion'            => $app['religion'] ?? '',
            'occupation'          => $app['occupation'] ?? '',
            'resident'            => $app['resident'] ?? '',
            'educational_qualification' => $app['educational_qualification'] ?? '',
            'marital_status'      => $app['marital_status'] ?? '',
            'applicant_name'      => $app['applicant_name'] ?? '',
            'applicant_phone'     => $app['applicant_phone'] ?? '',
            'applicant_photo'     => $app['applicant_photo'] ?? '',
            'sonod_number'        => $app['sonod_number'] ?? '',
            'present_village_bn'  => $app['present_village_bn'] ?? '',
            'present_village_en'  => $app['present_village_en'] ?? '',
            'present_holding_no'  => $app['present_holding_no'] ?? '',
            'present_ward_no'     => $app['present_ward_no'] ?? '',
            'present_postoffice_bn' => $app['present_postoffice_bn'] ?? '',
            'present_union_bn'    => $app['present_union_bn'] ?? '',
            'present_upazila_bn'  => $app['present_upazila_bn'] ?? '',
            'present_district_bn' => $app['present_district_bn'] ?? '',
            'permanent_village_bn' => $app['permanent_village_bn'] ?? '',
            'permanent_holding_no' => $app['permanent_holding_no'] ?? '',
            'permanent_ward_no'   => $app['permanent_ward_no'] ?? '',
            'permanent_postoffice_bn' => $app['permanent_postoffice_bn'] ?? '',
            'permanent_union_bn'  => $app['permanent_union_bn'] ?? '',
            'permanent_upazila_bn' => $app['permanent_upazila_bn'] ?? '',
            'permanent_district_bn' => $app['permanent_district_bn'] ?? '',
            'union_name_bn'       => $app['union_name_bn'] ?? '',
            'union_id'            => $app['union_id'] ?? null,
            'documents'           => $pageData['documents'] ?? [],
            'union'               => $union ? ['union_id' => $union['union_id'] ?? '', 'union_code' => $union['union_code'] ?? ''] : null,
        ],
        'approval' => [
            'verifier_id'         => $approval['verifier_id'] ?? '',
            'verifier_name_bn'    => $approval['verifier_name_bn'] ?? '',
            'verifier_name_en'    => $approval['verifier_name_en'] ?? '',
            'verifier_ward_no'    => $approval['verifier_ward_no'] ?? '',
            'verification_date'   => $approval['verification_date'] ?? date('d-m-Y'),
            'verification_note'   => $approval['verification_note'] ?? 'সফলভাবে যাচাই করা হয়েছে।',
            'approver_id'         => $approval['approver_id'] ?? '',
            'approver_name_bn'    => $approval['approver_name_bn'] ?? '',
            'approver_name_en'    => $approval['approver_name_en'] ?? '',
            'approver_ward_no'    => $approval['approver_ward_no'] ?? '',
            'approval_date'       => $approval['approval_date'] ?? date('d-m-Y'),
            'issue_time'          => $approval['issue_time'] ?? '',
            'approval_note'       => $approval['approval_note'] ?? 'আবেদনটি অনুমোদিত হলো।',
            'certificate_fee'     => $approval['certificate_fee'] ?? '',
            'payment_method'      => $approval['payment_method'] ?? 'Cash',
            'payment_status'      => $approval['payment_status'] ?? 'Unpaid',
        ],
        'business_meta'       => $businessMeta,
        'business_types'      => $pageData['business_types'] ?? [],
        'ownership_types'     => $pageData['ownership_types'] ?? [],
        'fiscal_year_options' => generateFiscalYearOptions($pageData['fiscal_year'] ?? null),
        'license_number'      => $pageData['license_number'] ?? '',
        'union_members'       => $pageData['union_members'] ?? [],
        'extra_data'          => $app['extra_data'] ?? [],
    ]);
});

// ================================================================
// APPROVE ROUTES
// ================================================================

$router->get('/applications/{certificate_type}/approve/{application_id}', function($certificate_type = null, $application_id = null) use ($twig, $auth, $authService, $applicationService) {
    $auth->requireLogin();
    $user      = $auth->getUserData(false);
    $union_id  = $user['union_id'] ?? null;

    $authService->ensureCan('manage_applications', 'applications');

    $certificate_type    = $twig->getGlobals()['certificate_type'] ?? null;
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;

    $pageData = $applicationService->prepareApprovalPageData($application_id, $union_id, $certificate_type, $certificate_type_bn);

    if (isset($pageData['error'])) {
        die($pageData['error']);
    }

    echo $twig->render('applications/approve-page.twig', [
        'title'              => 'আবেদন অনুমোদন ফর্ম',
        'header_title'       => 'অনুমোদন ফর্ম',
        'data'               => $pageData['application'],
        'approval'           => $pageData['approval'],
        'documents'          => $pageData['documents'],
        'union'              => $pageData['union'],
        'business_meta'      => $pageData['application']['business_meta'] ?? null,
        'business_types'     => $pageData['business_types'],
        'ownership_types'    => $pageData['ownership_types'],
        'fiscal_year_options' => generateFiscalYearOptions($pageData['fiscal_year']),
        'license_number'     => $pageData['license_number'],
        'certificate_type'   => $pageData['certificate_type'],
        'certificate_type_bn' => $pageData['certificate_type_bn'],
        'extra_data'         => $pageData['application']['extra_data'] ?? [],
        'union_members'      => $pageData['union_members'],
    ]);
});

$router->post('/applications/{certificate_type}/approve/{application_id}', function($certificate_type = null, $application_id = null) use ($auth, $authService, $applicationService) {
    header('Content-Type: application/json; charset=utf-8');

    $auth->requireLogin();
    $user     = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $authService->ensureCan('manage_applications', 'applications');

    $isSuperAdmin = (isset($user['role_id']) && $user['role_id'] <= 1);
    if (!$isSuperAdmin && empty($union_id)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'আপনার ইউনিয়ন আইডি পাওয়া যায়নি। অনুমোদন সম্ভব নয়।'
        ]);
        return;
    }

    $result = $applicationService->approveApplication(
        $application_id,
        $_POST,
        $union_id,
        $isSuperAdmin
    );

    echo json_encode($result);
});

// ================================================================
// TRADE FEE UPDATE ROUTE (update business_meta fees independently)
// ================================================================

$router->post('/applications/{certificate_type}/update-fees/{application_id}', function($certificate_type = null, $application_id = null) use ($auth, $authService, $applicationService) {
    header('Content-Type: application/json; charset=utf-8');

    $auth->requireLogin();
    $user     = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    $authService->ensureCan('manage_applications', 'applications');

    if ($certificate_type !== 'trade') {
        echo json_encode(['status' => 'error', 'message' => 'শুধুমাত্র ট্রেড লাইসেন্সের ফি হালনাগাদ করা যায়।']);
        return;
    }

    $result = $applicationService->updateTradeFees($application_id, $_POST, $union_id);
    echo json_encode($result);
});

// ================================================================
// RENEWAL ROUTE
// ================================================================

$router->post('/applications/{certificate_type}/renew/{application_id}', function($certificate_type = null, $application_id = null) use ($auth, $authService, $applicationService) {
    header('Content-Type: application/json; charset=utf-8');

    $auth->requireLogin();
    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;

    if ($certificate_type !== 'trade') {
        echo json_encode(['status' => 'error', 'message' => 'শুধুমাত্র ট্রেড লাইসেন্স নবায়ন করা যায়।']);
        return;
    }

    try {
        $authService->ensureCan('approve', 'applications');
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'এই কাজের অনুমতি নেই।']);
        return;
    }

    $isSuperAdmin = (isset($user['role_id']) && $user['role_id'] <= 1);
    $result = $applicationService->renewTradeLicense($application_id, $_POST, $union_id, $isSuperAdmin);
    echo json_encode($result);
});

// ================================================================
// DELETE ROUTE
// ================================================================

$router->post('/applications/{certificate_type}/delete', function() use ($auth, $authService, $applicationService) {
    $authService->ensureCan('delete', 'applications');
    header('Content-Type: application/json');

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $isSuperAdmin = (isset($user['role_id']) && $user['role_id'] <= 1);

    $applicationId = sanitize_input($_POST['applicationId'] ?? '');
    $result = $applicationService->deleteApplicationById($applicationId, $union_id, $isSuperAdmin);
    echo json_encode($result);
});

// ================================================================
// VERIFICATION ROUTE
// ================================================================

$router->get('/verify/{url_path}_bn/{sonod_number}/{union_code}/{rmo_code}', function($url_path = null, $sonod_number = null, $union_code = null, $rmo_code = null) use ($twig, $appmanager, $applicationService) {
    $certificate_type = $url_path ?: 'application';
    
    // Resolve the certificate number consistently, including Bangla digits.
    $lookupNumber = convertBanglaToEnglishNumber(sanitize_input($sonod_number));
    $application = $appmanager->getApplicationBySonodNumber($lookupNumber, $certificate_type);

    // Some older records may have a different/empty certificate type.
    if (!$application) {
        $application = $appmanager->getApplicationBySonodNumber($lookupNumber);
    }

    // Fallback: the URL may contain an application/tracking ID.
    if (!$application) {
        $application = $appmanager->getApplicationByApplicationId($lookupNumber);
    }

    // Last local fallback: tolerate formatting differences in legacy sonod values.
    if (!$application && method_exists($appmanager, 'searchApplications')) {
        $matches = $appmanager->searchApplications($lookupNumber, null, 1);
        $candidate = $matches[0] ?? null;
        if ($candidate && (
            empty($candidate['certificate_type']) ||
            $candidate['certificate_type'] === $certificate_type
        )) {
            $application = $candidate;
        }
    }    if (!$application) {
        http_response_code(404);
        echo $twig->render('errors/not_found.twig', [
            'title'         => 'সনদ পাওয়া যায়নি',
            'header_title'  => 'সনদ যাচাই',
            'error_code'    => 404,
            'sonod_number'  => $sonod_number ?? '',
            'language'      => 'bn',
        ]);
        return;
    }

    $approval = $appmanager->getApprovalByApplicationId($application['application_id']);
    $union = $applicationService->getUnionById((int)$application['union_id']);
    
    // Attach warish/family members directly to the application array
    // so they become available via citizen.warish_members in the template
    if (in_array($application['certificate_type'] ?? '', ['warish', 'family'], true)) {
        $members = $appmanager->getMembersByApplication($application['application_id']);
        $application['warish_members'] = $members;
    }

    // Decode extra_data if it's a JSON string
    if (!empty($application['extra_data']) && is_string($application['extra_data'])) {
        $decoded = json_decode($application['extra_data'], true);
        if (!empty($decoded)) {
            $application['extra_data'] = $decoded;
            $application['extra'] = $decoded;
        }
    }

    $data = [
        'title'            => 'সনদ যাচাই',
        'header_title'     => 'অনলাইনে সনদ যাচাই',
        'approval'         => $approval,
        'data'             => Data($application),
        'detail'           => Data($application),
        'citizen'          => Data($application),
        'union'            => $union,
        'certificate_type' => $certificate_type,
    ];

    $certificate_type_bn = $applicationService->getCertificateTypeName($certificate_type);
    $data['certificate_type_bn'] = $certificate_type_bn ?: $certificate_type;

    if ($application['certificate_type'] === 'trade') {
        $data['business_meta'] = $appmanager->getBusinessMetaByApplicationId($application['application_id']);
    }

    $template = $applicationService->resolveTemplate('applications/online-verify/bangla', $certificate_type);
    echo $twig->render($template, $data);
});

// ================================================================
// VERIFICATION ROUTE (English)
// ================================================================

$router->get('/verify/{url_path}_en/{sonod_number}/{union_code}/{rmo_code}', function($url_path = null, $sonod_number = null, $union_code = null, $rmo_code = null) use ($twig, $appmanager, $applicationService) {
    $certificate_type = $url_path ?: 'application';
    
    // Resolve the certificate number consistently, including Bangla digits.
    $lookupNumber = convertBanglaToEnglishNumber(sanitize_input($sonod_number));
    $application = $appmanager->getApplicationBySonodNumber($lookupNumber, $certificate_type);

    // Some older records may have a different/empty certificate type.
    if (!$application) {
        $application = $appmanager->getApplicationBySonodNumber($lookupNumber);
    }

    // Fallback: the URL may contain an application/tracking ID.
    if (!$application) {
        $application = $appmanager->getApplicationByApplicationId($lookupNumber);
    }

    // Last local fallback: tolerate formatting differences in legacy sonod values.
    if (!$application && method_exists($appmanager, 'searchApplications')) {
        $matches = $appmanager->searchApplications($lookupNumber, null, 1);
        $candidate = $matches[0] ?? null;
        if ($candidate && (
            empty($candidate['certificate_type']) ||
            $candidate['certificate_type'] === $certificate_type
        )) {
            $application = $candidate;
        }
    }

    if (!$application) {
        http_response_code(404);
        echo $twig->render('errors/not_found.twig', [
            'title'         => 'Certificate Not Found',
            'header_title'  => 'Certificate Verification',
            'error_code'    => 404,
            'sonod_number'  => $sonod_number ?? '',
            'language'      => 'en',
        ]);
        return;
    }

    $approval = $appmanager->getApprovalByApplicationId($application['application_id']);
    $union = $applicationService->getUnionById((int)$application['union_id']);
    
    // Attach warish/family members directly to the application array
    // so they become available via citizen.warish_members in the template
    if (in_array($application['certificate_type'] ?? '', ['warish', 'family'], true)) {
        $members = $appmanager->getMembersByApplication($application['application_id']);
        $application['warish_members'] = $members;
    }

    // Decode extra_data if it's a JSON string
    if (!empty($application['extra_data']) && is_string($application['extra_data'])) {
        $decoded = json_decode($application['extra_data'], true);
        if (!empty($decoded)) {
            $application['extra_data'] = $decoded;
            $application['extra'] = $decoded;
        }
    }

    $data = [
        'title'            => 'Certificate Verification',
        'header_title'     => 'Online Certificate Verification',
        'approval'         => $approval,
        'data'             => Data($application),
        'detail'           => Data($application),
        'citizen'          => Data($application),
        'union'            => $union,
        'certificate_type' => $certificate_type,
    ];

    if ($application['certificate_type'] === 'trade') {
        $data['business_meta'] = $appmanager->getBusinessMetaByApplicationId($application['application_id']);
    }

    $template = $applicationService->resolveTemplate('applications/online-verify/english', $certificate_type);
    echo $twig->render($template, $data);
});

