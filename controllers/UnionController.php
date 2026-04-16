<?php
// controllers/UnionController.php

$auth       = new AuthManager($mysqli);
$unionModel = new UnionModel($mysqli);

/* =========================================================
   ROUTES
========================================================= */

/* ---------- LIST ---------- */
$router->get('/unions', function () use ($auth, $twig, $unionModel) {

    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_unions', 'unions');

    $search  = $_GET['search'] ?? '';
    $sortBy  = $_GET['sortBy'] ?? 'union_name_en';
    $sortDir = $_GET['sortDir'] ?? 'ASC';
    $page    = (int)($_GET['page'] ?? 1);
    $limit   = (int)($_GET['limit'] ?? 10);

    $result = $unionModel->fetchAllUnions(
        $search,
        $sortBy,
        $sortDir,
        $page,
        $limit
    );

    echo $twig->render('unions/index.twig', [
        'unions'      => $result['data'],
        'page'        => $page,
        'limit'       => $limit,
        'totalPages'  => max(1, ceil($result['total'] / $limit)),
        'search'      => $search,
        'title'       => 'Unions Management',
        'header_title' => 'All Unions'
    ]);
});

/* ---------- ADD (FORM) ---------- */
$router->get('/unions/add', function () use ($auth, $twig) {

    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_unions', 'unions');

    echo $twig->render('unions/add.twig', [
        'title'        => 'Create Union',
        'header_title' => 'Create Union'
    ]);
});

/* ---------- ADD (POST) ---------- */
$router->post('/unions/add', function () use ($auth, $mysqli) {

    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_unions', 'unions');

    // CSRF is verified by middleware

    $errors = [];
    if (empty($_POST['union_name_en'])) $errors[] = 'Union name (EN) required';
    if (empty($_POST['union_code']))    $errors[] = 'Union code required';

    if ($errors) {
        errorAlert('Form Error', implode(', ', $errors));
        header('Location: /unions/add');
        exit;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO unions (
            union_code,
            division_id, district_id, upazila_id,
            union_name_en, union_name_bn,
            upazila_name_en, upazila_name_bn,
            district_name_en, district_name_bn,
            division_name_en, division_name_bn,
            ward_count,
            email, phone, website, postcode,
            logo_url, latitude, longitude,
            is_active, remarks
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "siiisssssssssisssddiis",
        sanitize_input($_POST['union_code']),
        (int)$_POST['division_id'],
        (int)$_POST['district_id'],
        (int)$_POST['upazila_id'],
        sanitize_input($_POST['union_name_en']),
        sanitize_input($_POST['union_name_bn']),
        sanitize_input($_POST['upazila_name_en']),
        sanitize_input($_POST['upazila_name_bn']),
        sanitize_input($_POST['district_name_en']),
        sanitize_input($_POST['district_name_bn']),
        sanitize_input($_POST['division_name_en']),
        sanitize_input($_POST['division_name_bn']),
        (int)($_POST['ward_count'] ?? 9),
        sanitize_input($_POST['email']),
        sanitize_input($_POST['phone']),
        sanitize_input($_POST['website']),
        sanitize_input($_POST['postcode']),
        sanitize_input($_POST['logo_url']),
        $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null,
        $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null,
        isset($_POST['is_active']) ? 1 : 0,
        sanitize_input($_POST['remarks'])
    );

    if ($stmt->execute()) {
        successAlert('Success', 'Union created successfully');
        header('Location: /unions');
    } else {
        errorAlert('Error', 'Failed to create union');
        header('Location: /unions/add');
    }
    exit;
});

/* ---------- EDIT ---------- */
$router->get('/unions/edit/{id}', function ($id) use ($auth, $twig, $unionModel) {

    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_unions', 'unions');

    $union = $unionModel->getById((int)$id);
    if (!$union) {
        errorAlert('Not Found', 'Union not found');
        header('Location: /unions');
        exit;
    }

    echo $twig->render('unions/edit.twig', [
        'union'        => $union,
        'title'        => 'Edit Union',
        'header_title' => 'Edit Union'
    ]);
});

/* ---------- VIEW ---------- */
$router->get('/unions/view/{id}', function ($id) use ($auth, $twig, $unionModel) {

    $auth->requireLogin();
    $user = $auth->getUserData(false);
    // Module-scoped permission check
    ensure_can('manage_unions', 'unions');

    $union = $unionModel->getById((int)$id);
    if (!$union) {
        errorAlert('Not Found', 'Union not found');
        header('Location: /unions');
        exit;
    }

    echo $twig->render('unions/view.twig', [
        'union'        => $union,
        'title'        => 'Union Details',
        'header_title' => 'Union Details'
    ]);
});

/* ---------- UPDATE ---------- */
$router->post('/unions/edit/{id}', function ($id) use ($auth, $mysqli) {

    $auth->requireLogin();

    // CSRF is verified by middleware

    $stmt = $mysqli->prepare("
        UPDATE unions SET
            union_code=?,
            division_id=?, district_id=?, upazila_id=?,
            union_name_en=?, union_name_bn=?,
            upazila_name_en=?, upazila_name_bn=?,
            district_name_en=?, district_name_bn=?,
            division_name_en=?, division_name_bn=?,
            ward_count=?,
            email=?, phone=?, website=?, postcode=?,
            logo_url=?, latitude=?, longitude=?,
            is_active=?, remarks=?
        WHERE union_id=?
    ");

    $stmt->bind_param(
        "siiisssssssssisssddiis",
        sanitize_input($_POST['union_code']),
        (int)$_POST['division_id'],
        (int)$_POST['district_id'],
        (int)$_POST['upazila_id'],
        sanitize_input($_POST['union_name_en']),
        sanitize_input($_POST['union_name_bn']),
        sanitize_input($_POST['upazila_name_en']),
        sanitize_input($_POST['upazila_name_bn']),
        sanitize_input($_POST['district_name_en']),
        sanitize_input($_POST['district_name_bn']),
        sanitize_input($_POST['division_name_en']),
        sanitize_input($_POST['division_name_bn']),
        (int)($_POST['ward_count'] ?? 9),
        sanitize_input($_POST['email']),
        sanitize_input($_POST['phone']),
        sanitize_input($_POST['website']),
        sanitize_input($_POST['postcode']),
        sanitize_input($_POST['logo_url']),
        $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null,
        $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null,
        isset($_POST['is_active']) ? 1 : 0,
        sanitize_input($_POST['remarks']),
        (int)$id
    );

    if ($stmt->execute()) {
        successAlert('Updated', 'Union updated successfully');
    } else {
        errorAlert('Error', 'Update failed');
    }

    header("Location: /unions/edit/{$id}");
    exit;
});

/* ---------- DELETE ---------- */
$router->post('/unions/delete/{id}', function ($id) use ($auth, $mysqli) {

    $auth->requireLogin();
    header('Content-Type: application/json');

    // CSRF is verified by middleware

    try {
        $stmt = $mysqli->prepare("DELETE FROM unions WHERE union_id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        echo json_encode([
            'success' => true,
            'alert' => [
                'title'   => 'সফল',
                'message' => 'ইউনিয়ন মুছে ফেলা হয়েছে',
                'type'    => 'success'
            ],
            'redirect' => '/unions'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'alert' => [
                'title'   => 'ত্রুটি',
                'message' => $e->getMessage(),
                'type'    => 'error'
            ]
        ]);
    }
    exit;
});
