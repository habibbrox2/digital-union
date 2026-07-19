<?php
/**
 * controllers/UnionController.php
 * 
 * Union management routes - uses UnionModel for all database logic.
 * No helper functions or DB logic in this controller.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);
$unionModel = new UnionModel($mysqli);

// ================================================================
// LIST
// ================================================================
$router->get('/unions', function () use ($twig, $unionModel, $authService) {
    $authService->ensureCan('manage_unions', 'unions');

    $search  = $_GET['search'] ?? '';
    $sortBy  = $_GET['sortBy'] ?? 'union_name_en';
    $sortDir = $_GET['sortDir'] ?? 'ASC';
    $page    = (int)($_GET['page'] ?? 1);
    $limit   = (int)($_GET['limit'] ?? 10);

    $result = $unionModel->fetchAllUnions($search, $sortBy, $sortDir, $page, $limit);

    echo $twig->render('unions/index.twig', [
        'unions'       => $result['data'],
        'page'         => $page,
        'limit'        => $limit,
        'totalPages'   => max(1, ceil($result['total'] / $limit)),
        'search'       => $search,
        'sortBy'       => $sortBy,
        'sortDir'      => $sortDir,
        'status'       => '',
        'message'      => '',
        'title'        => 'ইউনিয়ন ব্যবস্থাপনা',
        'header_title' => 'All Unions'
    ]);
});

// ================================================================
// ADD (FORM)
// ================================================================
$router->get('/unions/add', function () use ($twig, $authService) {
    $authService->ensureCan('manage_unions', 'unions');

    echo $twig->render('unions/add.twig', [
        'title'        => 'নতুন ইউনিয়ন তৈরি করুন',
        'header_title' => 'Create Union'
    ]);
});

// ================================================================
// ADD (POST)
// ================================================================
$router->post('/unions/add', function () use ($mysqli, $authService) {
    $authService->ensureCan('manage_unions', 'unions');

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $service = new UnionService($mysqli);

    $errors = $service->validate($_POST);
    if ($errors) {
        if ($isAjax) {
            jsonResponse(false, 'ফর্ম ত্রুটি', implode(', ', $errors));
        }
        errorAlert('Form Error', implode(', ', $errors));
        header('Location: /unions/add');
        exit;
    }

    $result = $service->create($_POST);

    if ($result['success']) {
        if ($isAjax) {
            jsonResponse(true, 'সফল', 'ইউনিয়ন সফলভাবে তৈরি করা হয়েছে', [], '/unions');
        }
        successAlert('Success', $result['message']);
        header('Location: /unions');
    } else {
        if ($isAjax) {
            jsonResponse(false, 'ত্রুটি', 'ইউনিয়ন তৈরি করতে ব্যর্থ হয়েছে');
        }
        errorAlert('Error', $result['message']);
        header('Location: /unions/add');
    }
    exit;
});

// ================================================================
// EDIT (FORM)
// ================================================================
$router->get('/unions/edit/{id}', function ($id) use ($twig, $unionModel, $authService) {
    $authService->ensureCan('manage_unions', 'unions');

    $union = $unionModel->getById((int)$id);
    if (!$union) {
        errorAlert('Not Found', 'Union not found');
        header('Location: /unions');
        exit;
    }

    echo $twig->render('unions/edit.twig', [
        'union'        => $union,
        'title'        => 'ইউনিয়ন সম্পাদনা',
        'header_title' => 'Edit Union'
    ]);
});

// ================================================================
// VIEW
// ================================================================
$router->get('/unions/view/{id}', function ($id) use ($twig, $unionModel, $authService) {
    $authService->ensureCan('manage_unions', 'unions');

    $union = $unionModel->getById((int)$id);
    if (!$union) {
        errorAlert('Not Found', 'Union not found');
        header('Location: /unions');
        exit;
    }

    echo $twig->render('unions/view.twig', [
        'union'        => $union,
        'title'        => 'ইউনিয়নের বিবরণ',
        'header_title' => 'Union Details'
    ]);
});

// ================================================================
// UPDATE
// ================================================================
$router->post('/unions/edit/{id}', function ($id) use ($mysqli, $authService) {
    $authService->ensureCan('manage_unions', 'unions');

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $service = new UnionService($mysqli);

    $result = $service->update((int)$id, $_POST);

    if ($result['success']) {
        if ($isAjax) {
            jsonResponse(true, 'সফল', 'ইউনিয়ন সফলভাবে আপডেট করা হয়েছে', [], "/unions");
        }
        successAlert('Updated', $result['message']);
    } else {
        if ($isAjax) {
            jsonResponse(false, 'ত্রুটি', 'আপডেট করতে ব্যর্থ হয়েছে');
        }
        errorAlert('Error', $result['message']);
    }

    header("Location: /unions/edit/{$id}");
    exit;
});

// ================================================================
// DELETE
// ================================================================
$router->post('/unions/delete/{id}', function ($id) use ($mysqli, $authService) {
    $authService->ensureCan('manage_unions', 'unions');
    header('Content-Type: application/json');

    $service = new UnionService($mysqli);
    $result = $service->delete((int)$id);

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'alert' => [
                'title'   => 'সফল',
                'message' => $result['message'],
                'type'    => 'success'
            ],
            'redirect' => '/unions'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'alert' => [
                'title'   => 'ত্রুটি',
                'message' => $result['message'],
                'type'    => 'error'
            ]
        ]);
    }
    exit;
});
