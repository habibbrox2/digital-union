<?php
/**
 * modules/Services/LoginService.php
 * 
 * Service layer for login, registration, and password reset operations.
 * Extracts duplicated AJAX handling and validation logic from LoginController.
 */

class LoginService
{
    private AuthManager $auth;

    public function __construct(mysqli $mysqli)
    {
        // Load email helper so AuthManager can send welcome/password-reset/failed-login emails
        require_once __DIR__ . '/../../helpers/email_helper.php';

        $this->auth = new AuthManager($mysqli);
    }

    /**
     * Check if the current request is an AJAX request
     */
    public function isAjaxRequest(): bool
    {
        return !empty($_POST['return_json']) ||
               (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    }

    /**
     * Send a JSON response and exit
     */
    public function jsonResponse(bool $success, string $message, ?string $redirect = null, int $successCode = 200, int $errorCode = 400): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($success ? $successCode : $errorCode);
        echo json_encode([
            'success'  => $success,
            'message'  => $message,
            'redirect' => $redirect,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Handle login with standardized validation
     */
    public function handleLogin(array $input): array
    {
        $username = sanitize_input($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $redirect = sanitize_input($input['redirect'] ?? '/dashboard');

        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => 'ইউজারনেম/ইমেইল এবং পাসওয়ার্ড প্রয়োজন',
                'redirect' => null,
            ];
        }

        $result = $this->auth->login($username, $password);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'redirect' => $result['success'] ? $redirect : null,
        ];
    }

    /**
     * Handle password reset request with validation
     */
    public function handlePasswordResetRequest(array $input): array
    {
        $email = sanitize_input($input['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'একটি বৈধ ইমেইল প্রদান করুন।',
            ];
        }

        return $this->auth->sendPasswordReset($email);
    }

    /**
     * Handle new password submission with validation
     */
    public function handleNewPassword(array $input): array
    {
        $token = sanitize_input($input['token'] ?? $input['reset_token'] ?? '');
        $newPassword = $input['password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        if (empty($token)) {
            return ['success' => false, 'message' => 'টোকেন অনুপস্থিত。'];
        }
        if (empty($newPassword)) {
            return ['success' => false, 'message' => 'পাসওয়ার্ড প্রদান করুন。'];
        }
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে。'];
        }
        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'message' => 'পাসওয়ার্ড মিলছে না।'];
        }

        return $this->auth->resetPassword($token, $newPassword);
    }

    /**
     * Handle registration with validation
     */
    public function handleRegistration(array $input): array
    {
        $username = sanitize_input($input['username'] ?? '');
        $email = sanitize_input($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        $union_id = !empty($input['union_id']) ? (int)$input['union_id'] : null;

        if (empty($username) || empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'সকল ফিল্ড পূরণ করুন。'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'বৈধ ইমেইল প্রদান করুন。'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'message' => 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে。'];
        }
        if ($password !== $confirmPassword) {
            return ['success' => false, 'message' => 'পাসওয়ার্ড মিলছে না।'];
        }

        return $this->auth->register($username, $email, $password, $union_id);
    }

    /**
     * Get the AuthManager instance
     */
    public function getAuth(): AuthManager
    {
        return $this->auth;
    }
}
