<?php
/**
 * controllers/LoginController.php
 * 
 * Login, logout, password reset routes - pure closures using LoginService.
 * No inline validation, no duplicated AJAX handling, no inline helper loading.
 * All business logic is in modules/Services/LoginService.php.
 */

// Session check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $mysqli, $router, $twig;

$loginService = new LoginService($mysqli);
$auth = $loginService->getAuth();

/*
|--------------------------------------------------------------------------
| GET : Login Page
|--------------------------------------------------------------------------
*/
$router->get('/login', function () use ($twig, $auth) {
    if ($auth->isLoggedIn()) {
        header('Location: /dashboard');
        exit;
    }

    $redirect = $_GET['redirect'] ?? ($_SESSION['redirect_after_login'] ?? '/dashboard');
    $timeout = isset($_GET['timeout']) && $_GET['timeout'] == '1';

    echo $twig->render('login/login.twig', [
        'title'         => 'Login',
        'header_title'  => 'Login to Your Account',
        'redirect'      => $redirect,
        'timeout'       => $timeout,
        'csrf_token'    => generateCsrfToken(),
    ]);
});

/*
|--------------------------------------------------------------------------
| GET : Admin Login (alias)
|--------------------------------------------------------------------------
*/
$router->get('/admin', function () {
    header('Location: /login');
    exit;
});

/*
|--------------------------------------------------------------------------
| GET : Password Reset Page
|--------------------------------------------------------------------------
*/
$router->get('/password-reset', function () use ($twig) {
    echo $twig->render('login/password_reset.twig', [
        'title'        => 'Password Reset',
        'header_title' => 'Reset Your Password',
        'csrf_token'   => generateCsrfToken(),
    ]);
});

/*
|--------------------------------------------------------------------------
| GET : New Password Page (with token)
|--------------------------------------------------------------------------
*/
$router->get('/reset-password', function () use ($twig) {
    $token = $_GET['token'] ?? '';

    if (empty($token)) {
        errorAlert('ত্রুটি', 'অবৈধ রিসেট লিংক।');
        header('Location: /password-reset');
        exit;
    }

    echo $twig->render('login/reset_password.twig', [
        'title'        => 'Set New Password',
        'header_title' => 'Set Your New Password',
        'token'        => $token,
        'csrf_token'   => generateCsrfToken(),
    ]);
});

/*
|--------------------------------------------------------------------------
| GET : Logout
|--------------------------------------------------------------------------
*/
$router->get('/logout', function () use ($auth) {
    $auth->logout();

    session_start();
    successAlert('সফল', 'আপনি সফলভাবে লগআউট হয়েছেন।');
    header("Location: /login");
    exit;
});

/*
|--------------------------------------------------------------------------
| POST : Login Handle
|--------------------------------------------------------------------------
*/
$router->post('/login', function () use ($loginService) {
    $result = $loginService->handleLogin($_POST);

    if ($loginService->isAjaxRequest()) {
        $loginService->jsonResponse(
            $result['success'],
            $result['message'],
            $result['redirect'],
            $result['success'] ? 200 : 401
        );
    }

    if ($result['success']) {
        unset($_SESSION['redirect_after_login']);
        successAlert('সফল', 'লগইন সফল হয়েছে। রিডাইরেক্ট করা হচ্ছে...');
        header("Location: " . $result['redirect']);
    } else {
        errorAlert('ত্রুটি', $result['message']);
        $redirectParam = $result['redirect'] !== '/dashboard' ? '?redirect=' . urlencode($result['redirect']) : '';
        header("Location: /login" . $redirectParam);
    }
    exit;
});

/*
|--------------------------------------------------------------------------
| POST : Password Reset Request
|--------------------------------------------------------------------------
*/
$router->post('/password-reset', function () use ($loginService) {
    $result = $loginService->handlePasswordResetRequest($_POST);

    if ($loginService->isAjaxRequest()) {
        $loginService->jsonResponse(
            $result['success'],
            $result['message'],
            null,
            $result['success'] ? 200 : 400
        );
    }

    if ($result['success']) {
        successAlert('সফল', $result['message']);
    } else {
        errorAlert('ত্রুটি', $result['message']);
    }

    header("Location: /password-reset");
    exit;
});

/*
|--------------------------------------------------------------------------
| POST : New Password Submit
|--------------------------------------------------------------------------
*/
$router->post('/new-password', function () use ($loginService) {
    $result = $loginService->handleNewPassword($_POST);

    if ($loginService->isAjaxRequest()) {
        $loginService->jsonResponse(
            $result['success'],
            $result['message'],
            $result['success'] ? '/login' : null,
            $result['success'] ? 200 : 400
        );
    }

    if ($result['success']) {
        successAlert('সফল', 'পাসওয়ার্ড সফলভাবে পরিবর্তিত হয়েছে। আপনি এখন লগইন করতে পারেন।');
        header("Location: /login");
    } else {
        errorAlert('ত্রুটি', $result['message']);
        $token = sanitize_input($_POST['token'] ?? $_POST['reset_token'] ?? '');
        header("Location: /reset-password?token=" . urlencode($token));
    }
    exit;
});

/*
|--------------------------------------------------------------------------
| GET : Register Page
|--------------------------------------------------------------------------
*/
$router->get('/register', function () use ($twig, $auth) {
    if ($auth->isLoggedIn()) {
        header('Location: /dashboard');
        exit;
    }

    echo $twig->render('login/register.twig', [
        'title'         => 'Register',
        'header_title'  => 'নতুন অ্যাকাউন্ট তৈরি করুন',
        'csrf_token'    => generateCsrfToken(),
    ]);
});

/*
|--------------------------------------------------------------------------
| POST : Register Handle
|--------------------------------------------------------------------------
*/
$router->post('/register', function () use ($loginService) {
    $result = $loginService->handleRegistration($_POST);

    if ($loginService->isAjaxRequest()) {
        $loginService->jsonResponse(
            $result['success'],
            $result['message'],
            $result['success'] ? '/login' : null,
            $result['success'] ? 200 : 400
        );
    }

    if ($result['success']) {
        successAlert('সফল', $result['message']);
        header("Location: /login");
    } else {
        errorAlert('ত্রুটি', $result['message']);
        header("Location: /register");
    }
    exit;
});
