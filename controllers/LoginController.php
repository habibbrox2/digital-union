<?php
/**
 * LoginController.php
 * Handles login, logout, password reset routes
 * CSRF Protected
 */

// ✅ CSRF middleware should be loaded BEFORE this file
// require_once __DIR__ . '/../config/csrf.php'; (in your main index.php)

// ✅ Session should already be started by csrf.php
// But we double-check here
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth init
$auth = new AuthManager($mysqli);

global $router;

/*
|--------------------------------------------------------------------------
| GET : Login Page
|--------------------------------------------------------------------------
*/
$router->get('/login', function () {
    global $twig, $auth;

    // ✅ Redirect if already logged in
    if ($auth->isLoggedIn()) {
        header('Location: /dashboard');
        exit;
    }

    $redirect = $_GET['redirect'] ?? ($_SESSION['redirect_after_login'] ?? '/dashboard');
    $csrf_token = generateCsrfToken();
    
    // ✅ Check for timeout parameter
    $timeout = isset($_GET['timeout']) && $_GET['timeout'] == '1';

    echo $twig->render('login/login.twig', [
        'title'         => 'Login',
        'header_title'  => 'Login to Your Account',
        'redirect'      => $redirect,
        'timeout'       => $timeout,
        'csrf_token'    => $csrf_token
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
$router->get('/password-reset', function () {
    global $twig;

    $csrf_token = generateCsrfToken();

    echo $twig->render('login/password_reset.twig', [
        'title'        => 'Password Reset',
        'header_title' => 'Reset Your Password',
        'csrf_token'   => $csrf_token
    ]);
});

/*
|--------------------------------------------------------------------------
| GET : New Password Page (with token)
|--------------------------------------------------------------------------
*/
$router->get('/reset-password', function () {
    global $twig;

    $token = $_GET['token'] ?? '';
    $csrf_token = generateCsrfToken();

    if (empty($token)) {
        errorAlert('ত্রুটি', 'অবৈধ রিসেট লিংক।');
        header('Location: /password-reset');
        exit;
    }

    echo $twig->render('login/reset_password.twig', [
        'title'        => 'Set New Password',
        'header_title' => 'Set Your New Password',
        'token'        => $token,
        'csrf_token'   => $csrf_token
    ]);
});

/*
|--------------------------------------------------------------------------
| GET : Logout
|--------------------------------------------------------------------------
*/
$router->get('/logout', function () use ($auth) {
    $auth->logout();
    
    // ✅ Set success message
    session_start(); // Start new session for message
    successAlert('সফল', 'আপনি সফলভাবে লগআউট হয়েছেন।');
    
    header("Location: /login");
    exit;
});

/*
|--------------------------------------------------------------------------
| POST : Login Handle
|--------------------------------------------------------------------------
| ✅ CSRF token automatically verified by middleware before reaching here
*/
$router->post('/login', function () use ($auth) {
    // ✅ Load email helper
    require_once __DIR__ . '/../helpers/email_helper.php';
    
    // ✅ Sanitize inputs
    $username = sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? ''; // Don't trim passwords
    $redirect = sanitize_input($_POST['redirect'] ?? '/dashboard');

    // ✅ Detect AJAX request
    $isAjax = !empty($_POST['return_json']) || 
              (function_exists('isAjaxRequest') && isAjaxRequest());

    // ✅ Validate inputs
    if (empty($username) || empty($password)) {
        $success = false;
        $message = "ইউজারনেম/ইমেইল এবং পাসওয়ার্ড প্রয়োজন";
    } else {
        // ✅ Attempt login
        $result  = $auth->login($username, $password);
        $success = $result['success'];
        $message = $result['message'];
    }

    // ✅ AJAX Response
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($success ? 200 : 401);
        
        echo json_encode([
            'success'  => $success,
            'message'  => $message,
            'redirect' => $success ? $redirect : null
        ]);
        exit;
    }

    // ✅ Normal Form Response
    if ($success) {
        // Clear any old redirect
        unset($_SESSION['redirect_after_login']);
        successAlert('সফল', 'লগইন সফল হয়েছে। রিডাইরেক্ট করা হচ্ছে...');
        header("Location: " . $redirect);
    } else {
        errorAlert('ত্রুটি', $message);
        header("Location: /login" . ($redirect !== '/dashboard' ? '?redirect=' . urlencode($redirect) : ''));
    }
    exit;
});

/*
|--------------------------------------------------------------------------
| POST : Password Reset Request
|--------------------------------------------------------------------------
| ✅ CSRF protected
*/
$router->post('/password-reset', function () use ($auth) {
    // ✅ Load email helper
    require_once __DIR__ . '/../helpers/email_helper.php';
    
    // ✅ Sanitize input
    $email = sanitize_input($_POST['email'] ?? '');
    
    // ✅ Detect AJAX
    $isAjax = !empty($_POST['return_json']) || 
              (function_exists('isAjaxRequest') && isAjaxRequest());

    // ✅ Validate email
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $success = false;
        $message = 'একটি বৈধ ইমেইল প্রদান করুন।';
    } else {
        $result = $auth->sendPasswordReset($email);
        $success = $result['success'];
        $message = $result['message'];
    }

    // ✅ AJAX Response
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($success ? 200 : 400);
        
        echo json_encode([
            'success' => $success,
            'message' => $message
        ]);
        exit;
    }

    // ✅ Normal Response
    if ($success) {
        successAlert('সফল', $message);
    } else {
        errorAlert('ত্রুটি', $message);
    }

    header("Location: /password-reset");
    exit;
});

/*
|--------------------------------------------------------------------------
| POST : New Password Submit
|--------------------------------------------------------------------------
| ✅ CSRF protected
*/
$router->post('/new-password', function () use ($auth) {
    // ✅ Load email helper
    require_once __DIR__ . '/../helpers/email_helper.php';
    
    // ✅ Sanitize inputs
    $token       = sanitize_input($_POST['token'] ?? $_POST['reset_token'] ?? '');
    $newPassword = $_POST['password'] ?? ''; // Don't sanitize password
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // ✅ Detect AJAX
    $isAjax = !empty($_POST['return_json']) || 
              (function_exists('isAjaxRequest') && isAjaxRequest());

    // ✅ Validate inputs
    if (empty($token)) {
        $success = false;
        $message = 'টোকেন অনুপস্থিত।';
    } elseif (empty($newPassword)) {
        $success = false;
        $message = 'পাসওয়ার্ড প্রদান করুন।';
    } elseif (strlen($newPassword) < 6) {
        $success = false;
        $message = 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।';
    } elseif ($newPassword !== $confirmPassword) {
        $success = false;
        $message = 'পাসওয়ার্ড মিলছে না।';
    } else {
        $result = $auth->resetPassword($token, $newPassword);
        $success = $result['success'];
        $message = $result['message'];
    }

    // ✅ AJAX Response
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($success ? 200 : 400);
        
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'redirect' => $success ? '/login' : null
        ]);
        exit;
    }

    // ✅ Normal Response
    if ($success) {
        successAlert('সফল', 'পাসওয়ার্ড সফলভাবে পরিবর্তিত হয়েছে। আপনি এখন লগইন করতে পারেন।');
        header("Location: /login");
    } else {
        errorAlert('ত্রুটি', $message);
        header("Location: /reset-password?token=" . urlencode($token));
    }
    exit;
});

/*
|--------------------------------------------------------------------------
| POST : Register Handle (Optional)
|--------------------------------------------------------------------------
*/
$router->post('/register', function () use ($auth) {
    // ✅ Load email helper
    require_once __DIR__ . '/../helpers/email_helper.php';
    
    // ✅ Sanitize inputs
    $username = sanitize_input($_POST['username'] ?? '');
    $email    = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $union_id = !empty($_POST['union_id']) ? (int)$_POST['union_id'] : null;
    
    // ✅ Detect AJAX
    $isAjax = !empty($_POST['return_json']) || 
              (function_exists('isAjaxRequest') && isAjaxRequest());

    // ✅ Validate inputs
    if (empty($username) || empty($email) || empty($password)) {
        $success = false;
        $message = 'সকল ফিল্ড পূরণ করুন।';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $success = false;
        $message = 'বৈধ ইমেইল প্রদান করুন।';
    } elseif (strlen($password) < 6) {
        $success = false;
        $message = 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।';
    } elseif ($password !== $confirmPassword) {
        $success = false;
        $message = 'পাসওয়ার্ড মিলছে না।';
    } else {
        $result = $auth->register($username, $email, $password, $union_id);
        $success = $result['success'];
        $message = $result['message'];
    }

    // ✅ AJAX Response
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($success ? 200 : 400);
        
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'redirect' => $success ? '/login' : null
        ]);
        exit;
    }

    // ✅ Normal Response
    if ($success) {
        successAlert('সফল', $message);
        header("Location: /login");
    } else {
        errorAlert('ত্রুটি', $message);
        header("Location: /register");
    }
    exit;
});