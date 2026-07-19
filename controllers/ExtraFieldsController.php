<?php
/**
 * controllers/ExtraFieldsController.php
 * 
 * Extra fields management routes - uses ExtraFieldsService.
 * No inline data processing, no helper functions.
 * All DB logic delegated to ExtraFields model via service.
 */

global $router, $mysqli, $twig;

$authService = new AuthService($mysqli);
$extraFieldsService = new ExtraFieldsService($mysqli);

// ================================================================
// GET : Admin page
// ================================================================
$router->get('/settings/extra-fields', function() use ($twig, $authService) {
    $authService->ensureCan('manage_settings');
    echo $twig->render('extra-fields/extra-fields.twig', [
        'title' => 'অতিরিক্ত ফিল্ড ব্যবস্থাপনা',
        'header_title' => 'অতিরিক্ত ফিল্ড ব্যবস্থাপনা',
    ]);
});

// ================================================================
// GET : Fetch certificate types for dropdown
// ================================================================
$router->get('/api/certificate-types', function() use ($extraFieldsService) {
    $types = $extraFieldsService->getCertificateTypes();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'data' => $types], JSON_UNESCAPED_UNICODE);
});

// ================================================================
// GET : Fetch all fields (AJAX)
// ================================================================
$router->any('/api/extra-fields', function() use ($extraFieldsService) {
    $certificate_type = sanitize_input($_GET['certificate_type'] ?? null);
    $fields = $extraFieldsService->getAllFields($certificate_type);
    echo json_encode(['status' => 'success', 'data' => $fields]);
});

// ================================================================
// POST : Save/update fields
// ================================================================
$router->post('/api/extra-fields', function() use ($extraFieldsService) {
    $input = json_decode(file_get_contents('php://input'), true);
    $certificate_type = sanitize_input($input['certificate_type'] ?? '');
    $fields = $input['fields'] ?? [];

    $result = $extraFieldsService->saveFields($certificate_type, $fields);
    echo json_encode($result);
});

// ================================================================
// GET : Fetch single field
// ================================================================
$router->any('/api/extra-fields/{id}', function($id) use ($extraFieldsService) {
    $field = $extraFieldsService->getFieldById((int)$id);
    if ($field) {
        echo json_encode(['status' => 'success', 'data' => $field]);
    } else {
        echo json_encode([
            'status' => 'error',
            'alert' => ['type' => 'error', 'title' => 'ত্রুটি', 'message' => 'ফিল্ড পাওয়া যায়নি'],
        ]);
    }
});

// ================================================================
// GET : Fetch all fields as formatted JSON (public API)
// ================================================================
$router->any('/api/v2/extra-fields/json', function() use ($extraFieldsService) {
    $output = $extraFieldsService->getFormattedFieldsJson();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});
