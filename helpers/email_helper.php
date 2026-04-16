<?php
/**
 * Email Helper Functions
 * Direct email sending functions with production-ready error handling
 * 
 * NOTE: All email subjects and content should be in Bengali (বাংলা) for consistency.
 * All user-facing messages are already configured in Bengali.
 */

declare(strict_types=1);

/**
 * Get EmailService instance
 * @return EmailService
 * @throws Exception
 */
if (!function_exists('getEmailService')) {
    function getEmailService() {
        if (!class_exists('EmailService')) {
            throw new Exception('EmailService class not found');
        }
        return new EmailService();
    }
}

/**
 * Send welcome email to new user
 * @param string $email User email
 * @param string $username User username
 * @return bool
 */
if (!function_exists('sendWelcomeEmail')) {
    function sendWelcomeEmail(string $email, string $username): bool {
        try {
            if (!defined('SEND_WELCOME_EMAIL') || !SEND_WELCOME_EMAIL) {
                return true; // Skip if disabled
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: $email");
            }

            $emailService = getEmailService();

            $data = [
                'username' => htmlspecialchars($username),
                'email' => htmlspecialchars($email),
                'subject' => 'স্বাগতম ' . (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'লগ ধাকা') . ' এ',
            ];

            return $emailService->sendTemplate(
                $email,
                'welcome',
                $data,
                $data['subject']
            );
        } catch (Exception $e) {
            error_log("Welcome email error: {$e->getMessage()}");
            return false;
        }
    }
}

/**
 * Send email verification email
 * @param string $email User email
 * @param string $username User username
 * @param string $token Verification token
 * @return bool
 */
if (!function_exists('sendEmailVerification')) {
    function sendEmailVerification(string $email, string $username, string $token): bool {
        try {
            if (!defined('SEND_EMAIL_VERIFICATION') || !SEND_EMAIL_VERIFICATION) {
                return true; // Skip if disabled
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: $email");
            }

            if (empty($token)) {
                throw new Exception("Verification token cannot be empty");
            }

            $emailService = getEmailService();

            $verificationLink = (defined('SITE_URL') ? SITE_URL : '') . '/verify-email?token=' . urlencode($token);
            $expiryHours = ceil((defined('EMAIL_VERIFICATION_EXPIRY') ? EMAIL_VERIFICATION_EXPIRY : 86400) / 3600);

            $data = [
                'username' => htmlspecialchars($username),
                'email' => htmlspecialchars($email),
                'verification_link' => $verificationLink,
                'expiry_hours' => $expiryHours,
                'subject' => 'ইমেইল যাচাই করুন',
            ];

            return $emailService->sendTemplate(
                $email,
                'email_verification',
                $data,
                $data['subject']
            );
        } catch (Exception $e) {
            error_log("Email verification error: {$e->getMessage()}");
            return false;
        }
    }
}

/**
 * Send password reset email
 * @param string $email User email
 * @param string $username User username
 * @param string $resetLink Password reset link
 * @return bool
 */
if (!function_exists('sendPasswordResetEmail')) {
    function sendPasswordResetEmail(string $email, string $username, string $resetLink): bool {
        try {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: $email");
            }

            if (empty($resetLink)) {
                throw new Exception("Reset link cannot be empty");
            }

            $emailService = getEmailService();

            $expiryHours = ceil((defined('PASSWORD_RESET_EXPIRY') ? PASSWORD_RESET_EXPIRY : 3600) / 3600);

            $data = [
                'username' => htmlspecialchars($username),
                'email' => htmlspecialchars($email),
                'reset_link' => filter_var($resetLink, FILTER_VALIDATE_URL) ? $resetLink : '#',
                'expiry_hours' => $expiryHours,
                'subject' => 'আপনার পাসওয়ার্ড রিসেট করুন',
            ];

            return $emailService->sendTemplate(
                $email,
                'password_reset',
                $data,
                $data['subject']
            );
        } catch (Exception $e) {
            error_log("Password reset email error: {$e->getMessage()}");
            return false;
        }
    }
}

/**
 * Send password changed confirmation email
 * @param string $email User email
 * @param string $username User username
 * @return bool
 */
if (!function_exists('sendPasswordChangedEmail')) {
    function sendPasswordChangedEmail(string $email, string $username): bool {
        try {
            if (!defined('SEND_PASSWORD_CHANGE_ALERT') || !SEND_PASSWORD_CHANGE_ALERT) {
                return true; // Skip if disabled
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: $email");
            }

            $emailService = getEmailService();

            $data = [
                'username' => htmlspecialchars($username),
                'email' => htmlspecialchars($email),
                'changed_at' => date('Y-m-d H:i:s'),
                'subject' => 'পাসওয়ার্ড পরিবর্তন সম্পন্ন',
            ];

            return $emailService->sendTemplate(
                $email,
                'password_changed',
                $data,
                $data['subject']
            );
        } catch (Exception $e) {
            error_log("Password changed email error: {$e->getMessage()}");
            return false;
        }
    }
}

/**
 * Send failed login alert email
 * @param string $email User email
 * @param string $username User username
 * @param int $attempts Number of failed attempts
 * @param string $ipAddress IP address of attempts
 * @param string $userAgent User agent string
 * @return bool
 */
if (!function_exists('sendFailedLoginAlert')) {
    function sendFailedLoginAlert(
        string $email,
        string $username,
        int $attempts = 3,
        string $ipAddress = '',
        string $userAgent = ''
    ): bool {
        try {
            if (!defined('SEND_FAILED_LOGIN_ALERT') || !SEND_FAILED_LOGIN_ALERT) {
                return true; // Skip if disabled
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: $email");
            }

            if ($attempts < 1) {
                throw new Exception("Attempts must be greater than 0");
            }

            $emailService = getEmailService();

            $data = [
                'username' => htmlspecialchars($username),
                'email' => htmlspecialchars($email),
                'failed_attempts' => $attempts,
                'attempted_at' => date('Y-m-d H:i:s'),
                'ip_address' => htmlspecialchars($ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? 'Unknown')),
                'user_agent' => htmlspecialchars($userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown')),
                'subject' => 'সতর্কতা: একাধিক ব্যর্থ লগইন প্রচেষ্টা',
            ];

            return $emailService->sendTemplate(
                $email,
                'failed_login_alert',
                $data,
                $data['subject']
            );
        } catch (Exception $e) {
            error_log("Failed login alert error: {$e->getMessage()}");
            return false;
        }
    }
}

/**
 * Send new device login alert email
 * @param string $email User email
 * @param string $username User username
 * @param array $loginData Login information
 * @return bool
 */
if (!function_exists('sendNewDeviceLoginAlert')) {
    function sendNewDeviceLoginAlert(string $email, string $username, array $loginData = []): bool {
        try {
            if (!defined('SEND_LOGIN_ALERT') || !SEND_LOGIN_ALERT) {
                return true; // Skip if disabled
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: $email");
            }

            $emailService = getEmailService();

            $data = [
                'username' => htmlspecialchars($username),
                'email' => htmlspecialchars($email),
                'login_at' => date('Y-m-d H:i:s'),
                'device' => htmlspecialchars($loginData['device'] ?? 'Unknown'),
                'browser' => htmlspecialchars($loginData['browser'] ?? 'Unknown'),
                'ip_address' => htmlspecialchars($loginData['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'Unknown')),
                'location' => htmlspecialchars($loginData['location'] ?? 'Unknown'),
                'subject' => 'নতুন ডিভাইস থেকে লগইন',
            ];

            return $emailService->sendTemplate(
                $email,
                'new_device_login',
                $data,
                $data['subject']
            );
        } catch (Exception $e) {
            error_log("New device login alert error: {$e->getMessage()}");
            return false;
        }
    }
}

/**
 * Send custom email
 * @param string $to Recipient email
 * @param string $subject Email subject (Bengali recommended)
 * @param string $body Email body (HTML, Bengali recommended)
 * @param array $cc CC addresses
 * @param array $bcc BCC addresses
 * @return bool
 */
if (!function_exists('sendCustomEmail')) {
    function sendCustomEmail(string $to, string $subject, string $body, array $cc = [], array $bcc = []): bool {
        try {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid recipient email: $to");
            }

            if (empty($subject)) {
                throw new Exception("Subject cannot be empty");
            }

            if (empty($body)) {
                throw new Exception("Body cannot be empty");
            }

            $emailService = getEmailService();
            return $emailService->sendEmail($to, $subject, $body, $cc, $bcc);
        } catch (Exception $e) {
            error_log("Custom email error: {$e->getMessage()}");
            return false;
        }
    }
}

/**
 * Verify SMTP connection
 * @return array
 */
if (!function_exists('verifyEmailConnection')) {
    function verifyEmailConnection(): array {
        try {
            if (!class_exists('EmailService')) {
                return [
                    'success' => false,
                    'message' => 'EmailService class not found',
                ];
            }

            $emailService = new EmailService();
            return $emailService->verifyConnection();
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Verification failed: {$e->getMessage()}",
            ];
        }
    }
}