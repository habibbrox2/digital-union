<?php
/**
 * controllers/ApplicationController.php
 * 
 * Certificate application routes - uses ApplicationService and ApplicationManager.
 * No inline SQL, no helper function definitions, no repetitive code.
 */

global $crypt, $mysqli, $twig, $router;

$authService = new AuthService($mysqli);
$appService = new ApplicationService($mysqli);
$appManager = $appService->getAppManager();
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
        'title' => 'Applications list',
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
$router->get('/verify/{url_path}_bn/{sonod_number}/{union_code}/{rmo_code}', function($url_path, $sonod_number = null, $union_code = null, $rmo_code = null) use ($twig, $appService) {
    if (!$sonod_number) {
        renderError(400, 'error: sonod_number is required.');
    }

    $certificate_type = $twig->getGlobals()['certificate_type'] ?? null;
    $certificate_type_bn = $twig->getGlobals()['certificate_type_bn'] ?? null;
    $application = $appService->getApplicationBySonodNumber($sonod_number, $certificate_type);

    if (!$application) {
        renderError(404, 'error: no application found for the given sonod_number.');
    }

    $union = !empty($application['union_id']) ? $appService->getUnionById((int)$application['union_id']) : null;
    $template = $appService->resolveTemplate('applications/online-verify/bangla', $application['certificate_type']);

    $viewData = $appService->buildCertificateViewData($application, $union, [
        'title' => 'যাচাই',
        'header_title' => 'যাচাই',
        'certificate_type' => $certificate_type,
        'certificate_type_bn' => $certificate_type_bn,
        'union_code' => $union_code,
        'rmo_code' => $rmo_code,
    ]);

    echo $twig->render($template, $viewData);
});

// ================================================================
// ONLINE VERIFY (English)
// ================================================================
$router->get('/verify/{url_path}_en/{sonod_number}/{union_code}/{rmo_code}', function($url_path, $sonod_number = null, $union_code = null, $rmo_code = null) use ($twig, $appService) {
    if (!$sonod_number) {
        renderError(400, 'error: sonod_number is required.');
    }

    $certificate_type = $twig->getGlobals()['certificate_type'] ?? null;
    $certificate_type_en = $twig->getGlobals()['certificate_type_en'] ?? null;
    $application = $appService->getApplicationBySonodNumber($sonod_number, $certificate_type);

    if (!$application) {
        renderError(404, 'error: no application found for the given sonod_number.');
    }

    $union = !empty($application['union_id']) ? $appService->getUnionById((int)$application['union_id']) : null;
    $template = $appService->resolveTemplate('applications/online-verify/bangla', $application['certificate_type']);

    $viewData = $appService->buildCertificateViewData($application, $union, [
        'title' => 'যাচাই',
        'header_title' => 'যাচাই',
        'certificate_type' => $certificate_type,
        'certificate_type_en' => $certificate_type_en,
        'union_code' => $union_code,
        'rmo_code' => $rmo_code,
    ]);

    echo $twig->render($template, $viewData);
});

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
    $appService->makeCertificatePdf($htmlContent, $application['certificate_type'] . '_' . $application['application_id']);
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
    $appService->makeCertificatePdf($htmlContent, $application['certificate_type'] . '_' . $application['application_id']);
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
$router->post('/applications/{certificate_type}/delete', function($certificate_type = null) use ($auth, $appService, $authService) {
    $authService->ensureCan('delete', 'applications');

    $application_id = sanitize_input($_POST['applicationId'] ?? '');

    $user = $auth->getUserData(false);
    $union_id = $user['union_id'] ?? null;
    $isSuperAdmin = (isset($user['role_id']) && $user['role_id'] <= 1);

    $result = $appService->deleteApplicationById($application_id, $union_id, $isSuperAdmin);
    echo json_encode($result);
});

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
