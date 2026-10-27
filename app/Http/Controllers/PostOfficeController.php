<?php
/**
 * controllers/PostOfficeController.php
 * 
 * Admin CRUD for managing post offices by upazila.
 * All business logic is handled by PostOfficeService.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);

// ================================================================
// GET : Admin page
// ================================================================
$router->get('/settings/post-offices', function () use ($twig, $mysqli, $authService) {
    $authService->ensureCan('manage_settings', 'settings');

    $service = new PostOfficeService($mysqli);
    $upazilas = $service->getAllUpazilas();
    $unions = $service->getAllUnions();

    echo $twig->render('settings/post_offices.twig', [
        'title'        => 'পোস্ট অফিস ব্যবস্থাপনা',
        'header_title' => 'পোস্ট অফিস ব্যবস্থাপনা',
        'upazilas'     => $upazilas,
        'unions'       => $unions,
    ]);
});

// ================================================================
// POST : AJAX CRUD handler
// ================================================================
$router->post('/ajax/post-offices', function () use ($mysqli, $authService) {
    $authService->ensureCan('manage_settings', 'settings');
    header('Content-Type: application/json; charset=utf-8');

    $service = new PostOfficeService($mysqli);
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $result = $service->create($_POST);
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $result = $service->update($id, $_POST);
            break;

        case 'get':
            $id = (int)($_POST['id'] ?? 0);
            $record = $service->getById($id);
            echo json_encode($record ?: ['status' => 'error', 'message' => 'পাওয়া যায়নি']);
            exit;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $result = $service->delete($id);
            break;

        case 'filter':
            $result = $service->filter($_POST);
            break;

        default:
            $result = ['status' => 'error', 'message' => 'অবৈধ ক্রিয়া'];
            break;
    }

    echo json_encode($result);
    exit;
});
