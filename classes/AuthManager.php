<?php
/**
 * Authentication Manager - Production Ready
 * Handles user authentication, login/logout, session management
 * Compatible with CSRF middleware
 */

class AuthManager {
    private $mysqli;
    private $timeout = 1800; // 30 মিনিট

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
        
        // ✅ Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // ✅ Generate CSRF token if not exists
        if (function_exists('generateCsrfToken')) {
            generateCsrfToken();
        }
    }

    /**
     * User login with credentials
     */
    public function login($usernameOrEmail, $password) {
        // Build user's permission list using PermissionsManager (role-based)
        require_once __DIR__ . '/PermissionsManager.php';
        $permissionsManager = new PermissionsManager($this->mysqli);
        
        $stmt = $this->mysqli->prepare("SELECT user_id, username, email, password, union_id, role_id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $usernameOrEmail, $usernameOrEmail);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password'])) {
            // 📧 Track failed login attempts for email alerts
            if ($user && function_exists('trackFailedLoginAttempt')) {
                $this->trackFailedLoginAttempt($user['user_id'], $usernameOrEmail);
                
                // Check if alert should be sent (after 3+ attempts)
                $failedAttempts = $this->getFailedLoginAttempts($user['user_id']);
                if ($failedAttempts >= 3 && function_exists('sendFailedLoginAlert')) {
                    sendFailedLoginAlert($user['email'], $user['username'], $failedAttempts);
                }
            }
            return ['success' => false, 'message' => 'ইউজারনেম বা পাসওয়ার্ড ভুল।'];
        }

        // ✅ Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['union_id'] = $user['union_id'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
        // Collect permissions from user's global role
        $perms = [];
        $stmt = $this->mysqli->prepare(
            "SELECT DISTINCT p.name FROM permissions p
             JOIN role_permissions rp ON p.id = rp.permission_id
             JOIN users u ON u.role_id = rp.role_id
             WHERE u.user_id = ?"
        );
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $perms[] = $row['name'];
        }
        $stmt->close();

        // Collect permissions from union-specific roles
        $stmt = $this->mysqli->prepare(
            "SELECT DISTINCT p.name FROM permissions p
             JOIN role_permissions rp ON p.id = rp.permission_id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?"
        );
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $perms[] = $row['name'];
        }
        $stmt->close();

        // Deduplicate
        $_SESSION['permissions'] = array_values(array_unique($perms));

        // ✅ Regenerate CSRF token after login
        if (function_exists('generateCsrfToken')) {
            unset($_SESSION['csrf_token']);
            generateCsrfToken();
        }

        // Update last login time
        $updateStmt = $this->mysqli->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $updateStmt->bind_param("i", $user['user_id']);
        $updateStmt->execute();
        $updateStmt->close();

        // 📧 Clear failed login attempts on successful login
        $this->clearFailedLoginAttempts($user['user_id']);

        // 📧 Track device login (optional - for new device alerts)
        if (function_exists('trackDeviceLogin')) {
            $this->trackDeviceLogin($user['user_id']);
        }

        return ['success' => true, 'message' => 'সফলভাবে লগইন হয়েছে।'];
    }

    /**
     * User registration
     */
    public function register($username, $email, $password, $union_id = null) {
        // ✅ Load email helper
        require_once __DIR__ . '/../helpers/email_helper.php';
        
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        // Check if username or email already exists
        $stmt = $this->mysqli->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'ইউজারনেম বা ইমেইল ইতোমধ্যে ব্যবহৃত হয়েছে।'];
        }
        $stmt->close();

        $role_id = 2; // default user role

        $stmt = $this->mysqli->prepare("INSERT INTO users (username, email, password, union_id, role_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssii", $username, $email, $hashedPassword, $union_id, $role_id);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            // 📧 Send welcome email using email helper
            if (function_exists('sendWelcomeEmail')) {
                sendWelcomeEmail($email, $username);
            }
            
            return ['success' => true, 'message' => 'নিবন্ধন সফল হয়েছে! লগইন করুন।'];
        }
        
        return ['success' => false, 'message' => 'নিবন্ধন ব্যর্থ হয়েছে।'];
    }

    /**
     * Send password reset email
     */
    public function sendPasswordReset($email) {
        // ✅ Load email helper
        require_once __DIR__ . '/../helpers/email_helper.php';
        
        $stmt = $this->mysqli->prepare("SELECT user_id, username FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            // Don't reveal if email exists (security best practice)
            return ['success' => true, 'message' => 'যদি এই ইমেইল আমাদের সিস্টেমে থাকে, তাহলে একটি রিসেট লিংক পাঠানো হবে।'];
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt = $this->mysqli->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token = ?, expires_at = ?");
        $stmt->bind_param("issss", $user['user_id'], $token, $expires, $token, $expires);
        $stmt->execute();
        $stmt->close();

        $resetLink = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'] . "/reset-password?token=" . urlencode($token);
        
        // 📧 Send password reset email using email helper
        if (function_exists('sendPasswordResetEmail')) {
            sendPasswordResetEmail($email, $user['username'], $resetLink);
        }

        return ['success' => true, 'message' => 'যদি এই ইমেইল আমাদের সিস্টেমে থাকে, তাহলে একটি রিসেট লিংক পাঠানো হবে।'];
    }

    /**
     * Reset password with token
     */
    public function resetPassword($token, $newPassword) {
        // ✅ Load email helper
        require_once __DIR__ . '/../helpers/email_helper.php';
        
        $stmt = $this->mysqli->prepare("SELECT user_id FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $reset = $result->fetch_assoc();
        $stmt->close();

        if (!$reset) {
            return ['success' => false, 'message' => 'টোকেনটি অবৈধ বা মেয়াদ শেষ।'];
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->mysqli->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $hashedPassword, $reset['user_id']);
        $stmt->execute();
        $stmt->close();

        // Delete used token
        $stmt = $this->mysqli->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->bind_param("i", $reset['user_id']);
        $stmt->execute();
        $stmt->close();

        // 📧 Get user email to send confirmation
        $userStmt = $this->mysqli->prepare("SELECT email, username FROM users WHERE user_id = ?");
        $userStmt->bind_param("i", $reset['user_id']);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $user = $userResult->fetch_assoc();
        $userStmt->close();

        // 📧 Send password change confirmation email using email helper
        if ($user && function_exists('sendPasswordChangedEmail')) {
            sendPasswordChangedEmail($user['email'], $user['username']);
        }

        return ['success' => true, 'message' => 'পাসওয়ার্ড সফলভাবে রিসেট হয়েছে।'];
    }

    /**
     * Logout user
     */
    public function logout() {
        $_SESSION = [];
        
        // Destroy session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        session_destroy();
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
        
        // Redirect logged-in users away from login page
        if ($logged_in && $current_path === '/login') {
            header('Location: /dashboard');
            exit;
        }
        
        // Redirect non-logged-in users away from dashboard
        if (!$logged_in && $current_path === '/dashboard') {
            header('Location: /login');
            exit;
        }
        
        return $logged_in;
    }

    /**
     * Require user to be logged in
     */
    public function requireLogin(): void {
        require_once __DIR__ . '/../config/error.php';
        
        if (!$this->isLoggedIn()) {
            http_response_code(401);

            $isAjax = (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            );

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'আপনি লগইন করেননি। দয়া করে লগইন করুন।'
                ]);
            } else {
                renderError(401, 'আপনি লগইন করেননি। দয়া করে লগইন করুন।');
            }

            exit;
        }
    }

    /**
     * Get current user data with session timeout check
     */
    public function getUserData($requireLogin = true, $returnJson = false) {
        // Check session timeout
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $this->timeout) {
            $redirectBackTo = $_SERVER['REQUEST_URI'];
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['redirect_after_login'] = $redirectBackTo;

            if ($returnJson) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Session expired',
                    'redirect' => '/login?timeout=1'
                ]);
            } else {
                header("Location: /login?timeout=1&redirect=" . urlencode($redirectBackTo));
            }
            exit;
        }

        // Update last activity
        $_SESSION['last_activity'] = time();

        $user_id = $_SESSION['user_id'] ?? null;
        
        if (!$user_id) {
            if ($requireLogin) {
                if ($returnJson) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'status' => 'error', 
                        'message' => 'Not logged in',
                        'redirect' => '/login'
                    ]);
                } else {
                    header("Location: /login");
                }
                exit;
            }
            return null;
        }

        // Fetch user data with union info
        $stmt = $this->mysqli->prepare("SELECT u.*, un.* FROM users u LEFT JOIN unions un ON u.union_id = un.union_id WHERE u.user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            session_unset();
            session_destroy();
            
            if ($returnJson) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Unauthorized',
                    'redirect' => '/login'
                ]);
            } else {
                header("Location: /login");
            }
            exit;
        }

        // Add role information using RolesManager
        require_once __DIR__ . '/RolesManager.php';
        $rolesManager = new RolesManager($this->mysqli);
        $role = $rolesManager->getRoleById($user['role_id']);
        $user['role_name'] = $role['role_name'] ?? null;
        // Superadmin if role_id is 0 or 1
        $user['is_superadmin'] = (isset($user['role_id']) && $user['role_id'] <= 1);

        return $user;
    }



    /**
     * Check if user has specific permission
     */
    public function hasPermission(string $permission): bool {
        if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions'])) {
            return false;
        }
        
        return in_array($permission, $_SESSION['permissions'], true);
    }

    /**
     * Require specific permission
     */
    public function requirePermission(string $permission): void {
        $this->requireLogin();
        
        if (!$this->hasPermission($permission)) {
            http_response_code(403);
            
            $isAjax = (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            );
            
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'আপনার এই সুবিধা নেই'
                ]);
            } else {
                require_once __DIR__ . '/../config/error.php';
                renderError(403, 'আপনার এই সুবিধা নেই');
            }
            exit;
        }
    }

    /**
     * Track failed login attempts
     */
    private function trackFailedLoginAttempt($userId, $username) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $this->mysqli->prepare(
            "INSERT INTO failed_login_attempts (user_id, username_attempted, ip_address, user_agent, attempted_at) 
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param("isss", $userId, $username, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get count of recent failed login attempts (last 1 hour)
     */
    private function getFailedLoginAttempts($userId) {
        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) as count FROM failed_login_attempts 
             WHERE user_id = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        return $data['count'] ?? 0;
    }

    /**
     * Clear failed login attempts for user
     */
    private function clearFailedLoginAttempts($userId) {
        $stmt = $this->mysqli->prepare("DELETE FROM failed_login_attempts WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Track device/browser login for security alerts
     */
    private function trackDeviceLogin($userId) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $device_fingerprint = hash('sha256', $user_agent . $ip_address);
        
        // Check if this device/location has logged in before
        $checkStmt = $this->mysqli->prepare(
            "SELECT id FROM login_history 
             WHERE user_id = ? AND device_fingerprint = ? AND ip_address = ? 
             LIMIT 1"
        );
        $checkStmt->bind_param("iss", $userId, $device_fingerprint, $ip_address);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $isNewDevice = $checkResult->num_rows === 0;
        $checkStmt->close();

        // Insert login history
        $stmt = $this->mysqli->prepare(
            "INSERT INTO login_history (user_id, ip_address, user_agent, device_fingerprint, is_new_device, logged_in_at) 
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param("isssi", $userId, $ip_address, $user_agent, $device_fingerprint, $isNewDevice);
        $stmt->execute();
        $stmt->close();

        // 📧 Send new device login alert if enabled and it's a new device
        if ($isNewDevice && defined('SEND_LOGIN_ALERT') && SEND_LOGIN_ALERT) {
            $userStmt = $this->mysqli->prepare("SELECT email, username FROM users WHERE user_id = ?");
            $userStmt->bind_param("i", $userId);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            $user = $userResult->fetch_assoc();
            $userStmt->close();

            if ($user && function_exists('sendNewDeviceLoginAlert')) {
                $loginData = [
                    'device' => 'Unknown',
                    'browser' => 'Unknown',
                    'ip_address' => $ip_address,
                    'location' => 'Unknown',
                ];
                sendNewDeviceLoginAlert($user['email'], $user['username'], $loginData);
            }
        }
    }
}