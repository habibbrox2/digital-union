<?php
/**
 * controllers/TermTranslationController.php
 * 
 * Term translation management routes - uses closures only.
 * All DB logic is handled by TermTranslation model.
 * No standalone helper functions in this controller.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);
$termTranslation = new TermTranslation($mysqli);

// ================================================================
// GET : Term translations index
// ================================================================
$router->get('/term_translations', function () use ($twig, $mysqli, $authService) {
    $authService->ensureCan('manage_settings', 'settings');

    $termTranslation = new TermTranslation($mysqli);
    $items = $termTranslation->getAll();
    $certificateTypes = $termTranslation->getCertificateTypes();

    echo $twig->render('term_translations/index.twig', [
        'title' => 'শব্দ অনুবাদ',
        'header_title' => 'শব্দ অনুবাদ পরিচালনা',
        'items' => $items,
        'certificate_types' => $certificateTypes
    ]);
});

// ================================================================
// POST : CRUD handler
// ================================================================
$router->post('/ajax/term_translations', function () use ($mysqli, $authService) {
    $authService->ensureCan('manage_settings', 'settings');

    $termTranslation = new TermTranslation($mysqli);
    $action = $_POST['action'] ?? '';

    $slug = sanitize_input($_POST['slug'] ?? '');
    $name_bn = sanitize_input($_POST['name_bn'] ?? '');
    $name_en = sanitize_input($_POST['name_en'] ?? '');
    $name_bl = sanitize_input($_POST['name_bl'] ?? '');
    $is_certificate_type = (isset($_POST['is_certificate_type']) && $_POST['is_certificate_type'] === '1') ? 1 : 0;

    header('Content-Type: application/json; charset=utf-8');

    switch ($action) {
        case 'filter':
            $page = max(1, (int)($_POST['page'] ?? 1));
            $sortColumn = $_POST['sortColumn'] ?? 'slug';
            $sortDirection = $_POST['sortDirection'] ?? 'asc';
            $search = trim($_POST['search'] ?? '');
            $limit = 10;
            $offset = ($page - 1) * $limit;

            $items = $termTranslation->fetchFiltered($search, $sortColumn, $sortDirection, $limit, $offset);
            $total = $termTranslation->countFiltered($search);

            echo json_encode([
                'status' => 'success',
                'data'   => $items,
                'total'  => $total
            ]);
            break;

        case 'get':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'আইডি প্রয়োজন']);
                break;
            }
            $term = $termTranslation->getById($id);
            if (!$term) {
                echo json_encode(['status' => 'error', 'message' => 'এন্ট্রি পাওয়া যায়নি']);
                break;
            }
            echo json_encode(['status' => 'success', 'data' => $term]);
            break;

        case 'create':
            if ($termTranslation->existsBySlugAndNameBl($slug, $name_bl)) {
                echo json_encode(['status' => 'error', 'message' => 'এই এন্ট্রি ইতিমধ্যে উপস্থিত আছে!']);
                break;
            }
            $termTranslation->create($slug, $name_bn, $name_en, $name_bl, $is_certificate_type);
            echo json_encode(['status' => 'success', 'message' => 'সফলভাবে তৈরি হয়েছে']);
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'আইডি প্রয়োজন']);
                break;
            }
            $termTranslation->update($id, $slug, $name_bn, $name_en, $name_bl, $is_certificate_type);
            echo json_encode(['status' => 'success', 'message' => 'সফলভাবে আপডেট হয়েছে']);
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'আইডি প্রয়োজন']);
                break;
            }
            $termTranslation->delete($id);
            echo json_encode(['status' => 'success', 'message' => 'সফলভাবে মুছে ফেলা হয়েছে']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'অবৈধ ক্রিয়া']);
            break;
    }
});
