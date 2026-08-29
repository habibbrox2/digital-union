<?php
/**
 * modules/Services/EmailTestService.php
 * 
 * Service layer for email testing operations.
 * Wraps global helper functions from email_helper.php with clean interfaces.
 * Used exclusively by the email test routes in EmailController.
 */

// Load email helper functions (sendWelcomeEmail, verifyEmailConnection, etc.)
// The helpers/ directory is at the project root, two levels up from modules/Services/
require_once __DIR__ . '/../../helpers/email_helper.php';

class EmailTestService
{
    /**
     * Send a JSON response with proper status code based on result success
     */
    public function sendJsonResponse(array $result): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$result['success']) {
            http_response_code(500);
        }
        echo json_encode($result);
        exit;
    }
    /**
     * SMTP configuration status
     */
    public function getSmtpConfig(): array
    {
        return [
            'configExists' => defined('SMTP_HOST'),
            'host' => defined('SMTP_HOST') ? SMTP_HOST : 'Not configured',
            'port' => defined('SMTP_PORT') ? SMTP_PORT : 'Not configured',
            'logsDir' => defined('EMAIL_LOG_DIR') ? EMAIL_LOG_DIR : '',
        ];
    }

    /**
     * Validate required email test input parameters
     */
    public function validateEmailInput(array $input, array $required = ['email', 'username']): ?string
    {
        foreach ($required as $field) {
            if (empty($input[$field])) {
                $names = [
                    'email' => 'Email',
                    'username' => 'Username',
                    'attempts' => 'Attempts',
                    'device' => 'Device',
                    'browser' => 'Browser',
                    'location' => 'Location',
                ];
                return ($names[$field] ?? ucfirst($field)) . ' and ' . ($names[$required[0]] ?? 'username') . ' required';
            }
        }
        return null;
    }

    /**
     * Send a welcome test email
     */
    public function sendWelcome(string $email, string $username): array
    {
        try {
            $result = sendWelcomeEmail($email, $username);
            if ($result) {
                return [
                    'success' => true,
                    'message' => "Welcome email sent to: $email",
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
            return ['success' => false, 'message' => 'Failed to send welcome email'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Send a password reset test email
     */
    public function sendPasswordReset(string $email, string $username): array
    {
        try {
            $token = bin2hex(random_bytes(32));
            $resetLink = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/reset-password?token=' . urlencode($token);

            $result = sendPasswordResetEmail($email, $username, $resetLink);
            if ($result) {
                return [
                    'success' => true,
                    'message' => "Password reset email sent to: $email",
                    'token' => substr($token, 0, 10) . '...',
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
            return ['success' => false, 'message' => 'Failed to send password reset email'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Send a password changed test email
     */
    public function sendPasswordChanged(string $email, string $username): array
    {
        try {
            $result = sendPasswordChangedEmail($email, $username);
            if ($result) {
                return [
                    'success' => true,
                    'message' => "Password changed confirmation sent to: $email",
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
            return ['success' => false, 'message' => 'Failed to send email'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Send a failed login alert test email
     */
    public function sendFailedLoginAlert(string $email, string $username, int $attempts): array
    {
        try {
            $result = sendFailedLoginAlert(
                $email, $username, $attempts,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );
            if ($result) {
                return [
                    'success' => true,
                    'message' => "Failed login alert sent to: $email ($attempts attempts)",
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
            return ['success' => false, 'message' => 'Failed to send email'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Send a new device login alert test email
     */
    public function sendNewDeviceAlert(string $email, string $username, array $input): array
    {
        try {
            $loginData = [
                'device' => $input['device'] ?? 'Unknown Device',
                'browser' => $input['browser'] ?? 'Unknown Browser',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'location' => $input['location'] ?? 'Unknown Location',
            ];

            $result = sendNewDeviceLoginAlert($email, $username, $loginData);
            if ($result) {
                return [
                    'success' => true,
                    'message' => "New device alert sent to: $email",
                    'device' => $loginData['device'],
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
            return ['success' => false, 'message' => 'Failed to send email'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Verify SMTP connection
     */
    public function verifyConnection(): array
    {
        try {
            $result = verifyEmailConnection();
            if ($result['success']) {
                return [
                    'success' => true,
                    'message' => '✓ SMTP Connection Successful',
                    'details' => [
                        'host' => defined('SMTP_HOST') ? SMTP_HOST : 'Not configured',
                        'port' => defined('SMTP_PORT') ? SMTP_PORT : 'Not configured',
                        'encryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'Not configured',
                    ],
                ];
            }
            return [
                'success' => false,
                'message' => '✗ SMTP Connection Failed',
                'error' => $result['message'] ?? 'Unknown error',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Verification Error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get recent email logs
     */
    public function getLogs(): array
    {
        if (!defined('EMAIL_LOG_DIR') || !is_dir(EMAIL_LOG_DIR)) {
            return ['success' => false, 'message' => 'Log directory not found'];
        }

        $todayLog = EMAIL_LOG_DIR . '/email_' . date('Y-m-d') . '.log';
        $logs = [];

        if (file_exists($todayLog)) {
            $lines = file($todayLog, FILE_IGNORE_NEW_LINES);
            $logs = array_slice($lines, -50);
        }

        return [
            'success' => true,
            'logFile' => $todayLog,
            'timestamp' => date('Y-m-d H:i:s'),
            'lineCount' => count($logs),
            'logs' => $logs,
        ];
    }
}
