<?php
// Assuming $mysqli, $twig, $auth (AuthManager instance) are globally available
global $auth;
$auth = new AuthManager($mysqli);

// GET /term_translations
function TermTransindex() {
    global $twig, $mysqli, $auth;
    require_once __DIR__ . '/../helpers/rbac_helpers.php';
    $auth->requireLogin();
    ensure_can('manage_settings', 'settings'); 

    $termTranslation = new TermTranslation($mysqli);
    $items = $termTranslation->getAll();
    $certificateTypes = $termTranslation->getCertificateTypes();

    echo $twig->render('term_translations/index.twig', [
        'title' => 'Term Translations',
        'header_title' => 'Manage Term Translations',
        'items' => $items,
        'certificate_types' => $certificateTypes
    ]);
}

// POST /ajax/term_translations
function TermTransstoreOrUpdate() {
    global $mysqli, $auth;
    require_once __DIR__ . '/../helpers/rbac_helpers.php';
    $auth->requireLogin();
    ensure_can('manage_settings', 'settings');

    $termTranslation = new TermTranslation($mysqli);
    $action = $_POST['action'] ?? '';

    // Input sanitization (assumed sanitize_input() function defined somewhere)
    $slug = sanitize_input($_POST['slug'] ?? '');
    $name_bn = sanitize_input($_POST['name_bn'] ?? '');
    $name_en = sanitize_input($_POST['name_en'] ?? '');
    $name_bl = sanitize_input($_POST['name_bl'] ?? '');
    $is_certificate_type = (isset($_POST['is_certificate_type']) && $_POST['is_certificate_type'] === '1') ? 1 : 0;

    switch ($action) {
        case 'create':
            if ($termTranslation->existsBySlugAndNameBl($slug, $name_bl)) {
                echo json_encode(['status' => 'error', 'message' => 'এই এন্ট্রি ইতিমধ্যে উপস্থিত আছে!']);
                break;
            }
            $termTranslation->create($slug, $name_bn, $name_en, $name_bl, $is_certificate_type);
            echo json_encode(['status' => 'success', 'message' => 'সফলভাবে তৈরি হয়েছে']);
            break;

        case 'update':
            $id = (int) ($_POST['id'] ?? 0);
            $existing = $termTranslation->getById($id);
            if (!$existing) {
                echo json_encode(['status' => 'error', 'message' => 'ডেটা পাওয়া যায়নি']);
                break;
            }

            $oldSlug = $existing['slug'];
            $termTranslation->update($id, $slug, $name_bn, $name_en, $name_bl, $is_certificate_type);

            // Check if this is a certificate_type and slug has changed
            if ($existing['is_certificate_type'] == 1 && $oldSlug !== $slug) {
                $termTranslation->updateSlugInRelatedTables($oldSlug, $slug);
            }

            echo json_encode(['status' => 'success', 'message' => 'সফলভাবে হালনাগাদ হয়েছে']);
            break;

        case 'get':
            $id = (int) ($_POST['id'] ?? 0);
            $term = $termTranslation->getById($id);
            echo json_encode($term);
            break;

        case 'delete':
            $id = (int) ($_POST['id'] ?? 0);
            $termTranslation->delete($id);
            echo json_encode(['status' => 'success', 'message' => 'সফলভাবে মোছা হয়েছে']);
            break;

        case 'filter':
            $search = sanitize_input($_POST['search'] ?? '');
            $sortColumn = sanitize_input($_POST['sortColumn'] ?? 'id');
            $sortDirection = sanitize_input($_POST['sortDirection'] ?? 'ASC');
            $page = (int) ($_POST['page'] ?? 1);
            $limit = (int) ($_POST['limit'] ?? 10);
            $offset = ($page - 1) * $limit;

            $data = $termTranslation->fetchFiltered($search, $sortColumn, $sortDirection, $limit, $offset);
            $total = $termTranslation->countFiltered($search);

            echo json_encode([
                'status' => 'success',
                'data' => $data,
                'total' => $total
            ]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'অবৈধ ক্রিয়া']);
            break;
    }
    exit;
}


// GET /ajax/term_translations
function TermTransfetchAll() {
    global $mysqli, $auth;
    require_once __DIR__ . '/../helpers/rbac_helpers.php';
    $auth->requireLogin();
    ensure_can('manage_settings', 'settings');

    header('Content-Type: application/json');

    $termTranslation = new TermTranslation($mysqli);
    $terms = $termTranslation->getAll();

    echo json_encode([
        'status' => 'success',
        'data' => $terms
    ]);
    exit;
}


// GET /ajax/term_translation_labels
function TermTransLabels() {
    global $mysqli, $auth;


    header('Content-Type: application/json');

    $termTranslation = new TermTranslation($mysqli);
    $types = $termTranslation->getCertificateTypes(); // শুধু is_certificate_type = 1

    $labelMap = [];

    foreach ($types as $type) {
        $slug = $type['slug'];
        $labelMap[$slug] = [
            'name_bn' => $type['name_bn'],
            'name_en' => $type['name_en'],
            'name_bl' => $type['name_bl']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $labelMap
    ]);
    exit;
}

// Routes
global $router;
$router->get('/term_translations', function() { TermTransindex(); });
$router->get('/ajax/term_translations', function() { TermTransfetchAll(); });
$router->get('/ajax/translation_labels', function() { TermTransLabels(); });
$router->post('/ajax/term_translations', function() { TermTransstoreOrUpdate(); });
