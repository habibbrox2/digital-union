<?php
/**
 * controllers/ApplicationControllerV2.php
 * 
 * Certificate application routes (V2) - pure closures using ApplicationService.
 * No inline SQL queries. All DB operations delegated to models/services.
 * All common lookups (union name, cert type name, business fees, union members)
 * handled by ApplicationService.
 */

global $mysqli, $twig, $router, $auth;

$auth = $auth ?? new AuthManager($mysqli);
$authService = new AuthService($mysqli);
$applicationService = new ApplicationService($mysqli);
$appmanager = $applicationService->getAppManager();

// ================================================================
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
        'title'        => 'আবেদন সম্পাদনা',
        'header_title' => 'আবেদন সম্পাদনা',
        'data'         => $merged_data,
        'union'        => $union,
        'extra_data'   => $extra_data,
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
    
    // Look up by sonod_number
    $application = $appmanager->getApplicationBySonodNumber($sonod_number);

    if (!$application) {
        // Fallback: look up by application_id (tracking number)
        $application = $appmanager->getApplicationByApplicationId($sonod_number);
    }

    if (!$application) {
        renderError(404, 'Certificate not found.');
        return;
    }

    $approval = $appmanager->getApprovalByApplicationId($application['application_id']);
    $union = $applicationService->getUnionById((int)$application['union_id']);
    $members = [];
    if (in_array($application['certificate_type'] ?? '', ['warish', 'family'], true)) {
        $members = $appmanager->getMembersByApplication($application['application_id']);
    }

    $data = [
        'title'            => 'সনদ যাচাই',
        'header_title'     => 'অনলাইনে সনদ যাচাই',
        'data'             => Data($application),
        'detail'           => Data($application),
        'citizen'          => Data($application),
        'union'            => $union,
        'certificate_type' => $certificate_type,
    ];

    $certificate_type_bn = $applicationService->getCertificateTypeName($certificate_type);
    $data['certificate_type_bn'] = $certificate_type_bn ?: $certificate_type;

    if (!empty($members)) {
        $data['warish'] = $members;
    }

    if ($application['certificate_type'] === 'trade') {
        $data['business_meta'] = $appmanager->getBusinessMetaByApplicationId($application['application_id']);
    }

    $template = $applicationService->resolveTemplate('applications/online-verify/bangla', $certificate_type);
    echo $twig->render($template, $data);
});
