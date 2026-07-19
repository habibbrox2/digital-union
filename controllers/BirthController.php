<?php
/**
 * controllers/BirthController.php
 * 
 * Birth and Death registration routes.
 * All business logic is handled by BirthService.
 * No inline data processing, no helper function definitions.
 */

global $router, $twig, $mysqli;

$authService = new AuthService($mysqli);
$birthService = new BirthService($mysqli);
$birthModel = $birthService->getModel();

// ================================================================
// BIRTH ROUTES
// ================================================================

$router->get('/birth', function() use ($twig, $authService) {
    $authService->ensureCan('manage_births');
    echo $twig->render('pdf/birth/list.twig', [
        'title' => 'জন্ম নিবন্ধন তালিকা',
        'header_title' => 'জন্ম নিবন্ধন তালিকা'
    ]);
});

$router->get('/api/births', function() use ($birthService) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($birthService->formatBirthList(), JSON_UNESCAPED_UNICODE);
});

$router->get('/api/birth/addresses', function() use ($birthModel) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode($birthModel->searchAddresses($q), JSON_UNESCAPED_UNICODE);
});

$router->get('/birth/form', function() use ($twig, $authService) {
    $authService->ensureCan('manage_births');
    echo $twig->render('pdf/birth/form.twig', [
        'title' => 'নতুন জন্ম নিবন্ধন ফরম',
        'header_title' => 'নতুন জন্ম নিবন্ধন ফরম',
        'birth' => []
    ]);
});

$router->get('/birth/edit/{id}', function($id) use ($twig, $birthModel, $authService) {
    $authService->ensureCan('manage_births');
    $birth = $birthModel->getById((int)$id);
    if (!$birth) {
        echo "<div class='alert alert-danger text-center my-5'>❌ জন্ম নিবন্ধন রেকর্ড পাওয়া যায়নি।</div>";
        return;
    }
    echo $twig->render('pdf/birth/form.twig', [
        'title' => 'জন্ম নিবন্ধন সম্পাদনা',
        'header_title' => 'জন্ম নিবন্ধন সম্পাদনা',
        'birth' => $birth
    ]);
});

// POST : Birth save - all processing delegated to BirthService
$router->post('/birth/save', function() use ($birthService, $authService) {
    $authService->ensureCan('manage_births');
    header('Content-Type: application/json; charset=utf-8');

    try {
        $result = $birthService->saveBirth($_POST);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode([
            'alert' => ['type' => 'danger', 'message' => $e->getMessage() ?: '⚠️ সার্ভার ত্রুটি ঘটেছে।']
        ], JSON_UNESCAPED_UNICODE);
    }
});

// GET : Birth PDF - all processing delegated to BirthService
$router->get('/birth/pdf/{id}', function($id) use ($birthService) {
    $birthService->generateBirthPdf((int)$id);
});

// POST : Birth delete
$router->post('/birth/delete/{id}', function($id) use ($birthService, $authService) {
    $authService->ensureCan('manage_births');
    header('Content-Type: application/json; charset=utf-8');

    try {
        $result = $birthService->deleteBirth((int)$id);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode([
            'alert' => ['type' => 'danger', 'message' => $e->getMessage()]
        ], JSON_UNESCAPED_UNICODE);
    }
});

// ================================================================
// BDRIS Integration
// ================================================================
$router->get('/birth/bdris/init', function() {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $res = bdris_generate_captcha();
        if (!$res || ($res['status'] ?? 'error') === 'error') {
            echo json_encode(['ok' => false, 'error' => $res['message'] ?? 'Captcha fetch failed']);
            return;
        }
        echo json_encode([
            'ok' => true,
            'token' => $res['token'] ?? '',
            'captcha_de_text' => $res['captcha_de_text'] ?? '',
            'captcha_data_uri' => $res['captcha_data_uri'] ?? ''
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
});

$router->post('/birth/bdris/submit', function() {
    header('Content-Type: application/json; charset=utf-8');
    $ubrn = $_POST['ubrn'] ?? '';
    $dob  = sanitize_input($_POST['birthdate'] ?? '');
    $captcha = $_POST['captcha'] ?? '';
    $token = $_POST['server_token'] ?? '';
    $captcha_de_text = $_POST['captcha_de_text'] ?? '';

    if (!$ubrn || !$dob || !$captcha) {
        echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
        return;
    }

    try {
        $res = bdris_fetch_birth_data($ubrn, $dob, $captcha, $token, $captcha_de_text);
        if (!$res || ($res['status'] ?? '') === 'error') {
            echo json_encode(['ok' => false, 'error' => $res['message'] ?? 'Fetch failed']);
            return;
        }
        echo json_encode(['ok' => true, 'data' => $res]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
});

// ================================================================
// DEATH ROUTES
// ================================================================

$router->get('/death', function() use ($twig, $authService) {
    $authService->ensureCan('manage_deaths');
    echo $twig->render('pdf/death/list.twig', [
        'title' => 'মৃত্যু নিবন্ধন তালিকা',
        'header_title' => 'মৃত্যু নিবন্ধন তালিকা'
    ]);
});

$router->get('/api/deaths', function() use ($birthService, $authService) {
    $authService->ensureCan('manage_deaths');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($birthService->formatDeathList(), JSON_UNESCAPED_UNICODE);
});

$router->get('/death/form', function() use ($twig, $authService) {
    $authService->ensureCan('manage_deaths');
    echo $twig->render('pdf/death/form.twig', [
        'title' => 'নতুন মৃত্যু নিবন্ধন ফরম',
        'header_title' => 'নতুন মৃত্যু নিবন্ধন ফরম',
        'death' => []
    ]);
});

$router->get('/death/edit/{id}', function($id) use ($twig, $birthModel, $authService) {
    $authService->ensureCan('manage_deaths');
    $death = $birthModel->getById((int)$id);
    if (!$death) {
        echo "<div class='alert alert-danger text-center my-5'>❌ মৃত্যু নিবন্ধন রেকর্ড পাওয়া যায়নি।</div>";
        return;
    }
    echo $twig->render('pdf/death/form.twig', [
        'title' => 'মৃত্যু নিবন্ধন সম্পাদনা',
        'header_title' => 'মৃত্যু নিবন্ধন সম্পাদনা',
        'death' => $death
    ]);
});

// POST : Death save - all processing delegated to BirthService
$router->post('/death/save', function() use ($birthService, $authService) {
    $authService->ensureCan('manage_deaths');
    header('Content-Type: application/json; charset=utf-8');
    try {
        $result = $birthService->saveDeath($_POST);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode([
            'alert' => ['type' => 'danger', 'message' => $e->getMessage()]
        ], JSON_UNESCAPED_UNICODE);
    }
});

// GET : Death PDF - all processing delegated to BirthService
$router->get('/death/pdf/{id}', function($id) use ($birthService, $authService) {
    $authService->ensureCan('manage_deaths');
    $birthService->generateDeathPdf((int)$id);
});

// POST : Death delete
$router->post('/death/delete/{id}', function($id) use ($birthService, $authService) {
    $authService->ensureCan('manage_deaths');
    header('Content-Type: application/json; charset=utf-8');
    try {
        $result = $birthService->deleteBirth((int)$id);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode([
            'alert' => ['type' => 'danger', 'message' => $e->getMessage()]
        ], JSON_UNESCAPED_UNICODE);
    }
});
