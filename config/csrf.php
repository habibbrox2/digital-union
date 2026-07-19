<?php
// ==================== CSRF Middleware (Drop-in) ====================

// 1) Start session
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// 2) Generate CSRF token (session + cookie)
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        // Also sync to cookie for session-loss resilience (double-submit cookie pattern)
        if (!isset($_COOKIE['csrf_cookie']) || $_COOKIE['csrf_cookie'] !== $_SESSION['csrf_token']) {
            $cookieParams = session_get_cookie_params();
            setcookie(
                'csrf_cookie',
                $_SESSION['csrf_token'],
                [
                    'expires' => time() + 86400 * 7,  // 7 days
                    'path' => $cookieParams['path'] ?? '/',
                    'domain' => $cookieParams['domain'] ?? '',
                    'secure' => $cookieParams['secure'] ?? false,
                    'httponly' => false,  // accessible by JS for double-submit pattern; XSS protection via CSP
                    'samesite' => 'Strict',
                ]
            );
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

        // Primary check: session-based
        $valid = verifyCsrfToken($token);

        // Fallback: if session check failed, try cookie-based double-submit pattern
        // This handles the case where the session was lost/regenerated (e.g., login in another tab)
        // but the browser still has the CSRF cookie from the original page render.
        if (!$valid && !empty($token) && isset($_COOKIE['csrf_cookie'])) {
            $valid = hash_equals($_COOKIE['csrf_cookie'], $token);
            if ($valid) {
                // Token matches cookie but not session — session was lost.
                // Restore the session token so subsequent requests in this session work.
                error_log(
                    sprintf(
                        '[CSRF RECOVER] session recovered via cookie fallback: method=%s, uri=%s',
                        $method,
                        $_SERVER['REQUEST_URI'] ?? 'unknown'
                    )
                );
                $_SESSION['csrf_token'] = $token;
            }
        }

        if (!$valid) {
            // Log diagnostic info before rejecting
            $sessionId = session_id();
            $expectedToken = isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 8) . '...' : 'NOT SET';
            $receivedPreview = !empty($token) ? substr($token, 0, 8) . '...' : 'EMPTY';
            $cookieToken = isset($_COOKIE['csrf_cookie']) ? substr($_COOKIE['csrf_cookie'], 0, 8) . '...' : 'NO COOKIE';
            error_log(
                sprintf(
                    '[CSRF FAIL] method=%s, uri=%s, session_id=%s, expected=%s, received=%s, cookie=%s, source=%s',
                    $method,
                    $_SERVER['REQUEST_URI'] ?? 'unknown',
                    $sessionId,
                    $expectedToken,
                    $receivedPreview,
                    $cookieToken,
                    isset($_POST['csrf_token']) ? 'POST' : (isset($_GET['csrf_token']) ? 'GET' : 'HEADER')
                )
            );
            sendCsrfError();
        }
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
