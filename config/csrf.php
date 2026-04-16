<?php
// ==================== CSRF Middleware (Drop-in) ====================

// 1) Start session
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// 2) Generate CSRF token
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// 3) Verify CSRF token
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(string $token = ''): bool {
        return isset($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// 4) Detect AJAX
if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

// 5) Send error
// 5) Send error
if (!function_exists('sendCsrfError')) {
    function sendCsrfError(int $code = 403, string $message = 'Invalid CSRF token') {
        http_response_code($code);
        if (isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                "success" => false, 
                "error" => $message,
                "code" => $code
            ]);
        } else {
            echo "<!DOCTYPE html>
					<html>
					<head>
						<meta charset='UTF-8'>
						<title>Error {$code}</title>
						<style>
							body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
							.error-box { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
							h1 { color: #d32f2f; margin: 0 0 10px; }
							p { color: #666; margin: 20px 0; }
							a { display: inline-block; padding: 10px 20px; background: #1976d2; color: white; text-decoration: none; border-radius: 4px; margin-top: 10px; }
							a:hover { background: #1565c0; }
						</style>
					</head>
					<body>
						<div class='error-box'>
							<h1>🔒 Error {$code}</h1>
							<p>{$message}</p>
							<a href='javascript:history.back()'>← ফিরে যান</a>
							<a href='/'>🏠 হোম পেজ</a>
						</div>
					</body>
					</html>";
        }
        exit;
    }
}

// 6) Auto verify CSRF on state-changing requests
if (!function_exists('autoVerifyCsrf')) {
    function autoVerifyCsrf(): void {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        $protected = ["POST","PUT","PATCH","DELETE"];

        if (!in_array($method, $protected, true)) return;

        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (!verifyCsrfToken($token)) sendCsrfError();
        unset($_POST['csrf_token'], $_GET['csrf_token']);
    }
}

// Call auto verify immediately
autoVerifyCsrf();

// 7) Twig helper to inject meta
if (!function_exists('csrfMetaTag')) {
    function csrfMetaTag(): string {
        $token = generateCsrfToken();
        return '<meta name="csrf_token" content="'.$token.'">';
    }
}

// 8) Data sanitizer
if (!function_exists('sanitizeRequest')) {
    function sanitizeRequest(array $data): array {
        $clean = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) $clean[$k] = sanitizeRequest($v);
            elseif (is_string($v)) $clean[$k] = htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
            else $clean[$k] = $v;
        }
        return $clean;
    }
}
