<?php
/**
 * Email Service
 * PHPMailer wrapper for sending emails
 * Author: Hr Habib
 * Last Updated: 2025-12-28
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class EmailService {
    private $mail;
    private $debug;
    private $logFile;
    private $maxRetries;
    private $retryDelay;

    /**
     * Initialize EmailService
     */
    public function __construct() {
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/../config/email.php';

        $this->mail = new PHPMailer(EMAIL_DEBUG);
        $this->debug = EMAIL_DEBUG;
        $this->logFile = EMAIL_LOG_FILE;
        $this->maxRetries = EMAIL_MAX_RETRIES;
        $this->retryDelay = EMAIL_RETRY_DELAY;

        $this->configureSMTP();
    }

    /**
     * Configure SMTP settings
     */
    private function configureSMTP(): void {
        try {
            // Use SMTP
            $this->mail->isSMTP();

            // SMTP settings
            $this->mail->Host = SMTP_HOST;
            $this->mail->Port = SMTP_PORT;
            $this->mail->SMTPAuth = true;
            $this->mail->Username = SMTP_USERNAME;
            $this->mail->Password = SMTP_PASSWORD;

            // Encryption
            if (SMTP_ENCRYPTION === 'tls') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif (SMTP_ENCRYPTION === 'ssl') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $this->mail->SMTPSecure = '';
            }

            // TLS / SSL options - enforce verification in production
            $this->mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ];

            // Set timeout
            $this->mail->Timeout = 20;
            $this->mail->SMTPKeepAlive = true;

            // Set From address
            $this->mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);

            // Debug settings
            if ($this->debug) {
                $this->mail->SMTPDebug = 2;
                $this->mail->Debugoutput = 'error_log';
            }

        } catch (PHPMailerException $e) {
            // Do not include credentials in logs
            $this->logError("SMTP Configuration Error: {$e->getMessage()}");
            throw new Exception("Email service configuration failed: {$e->getMessage()}");
        }
    }

    /**
     * Send basic email
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param array $cc Optional CC addresses
     * @param array $bcc Optional BCC addresses
     * @return bool
     */
    public function sendEmail(
        string $to,
        string $subject,
        string $body,
        array $cc = [],
        array $bcc = []
    ): bool {
        try {
            // Validate body is not empty
            if (empty($body)) {
                throw new Exception("Email body cannot be empty");
            }

            // Clear previous recipients
            $this->mail->clearAllRecipients();

            // Add recipient
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid recipient email: $to");
            }
            $this->mail->addAddress($to);

            // Add CC
            foreach ($cc as $ccEmail) {
                if (filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                    $this->mail->addCC($ccEmail);
                }
            }

            // Add BCC
            foreach ($bcc as $bccEmail) {
                if (filter_var($bccEmail, FILTER_VALIDATE_EMAIL)) {
                    $this->mail->addBCC($bccEmail);
                }
            }

            // Set subject and body
            $this->mail->Subject = $subject;
            $this->mail->Body = $body;
            $this->mail->AltBody = strip_tags($body);
            $this->mail->isHTML(true);
            $this->mail->CharSet = 'UTF-8';

            // Send with retry logic
            return $this->sendWithRetry();

        } catch (Exception $e) {
            $this->logError("Email sending error: {$e->getMessage()} | To: $to");
            return false;
        }
    }

    /**
     * Send email using template
     *
     * @param string $to Recipient email
     * @param string $template Template name (without .php)
     * @param array $data Data to pass to template
     * @param string $subject Email subject
     * @param array $cc Optional CC addresses
     * @param array $bcc Optional BCC addresses
     * @return bool
     */
    public function sendTemplate(
        string $to,
        string $template,
        array $data = [],
        string $subject = '',
        array $cc = [],
        array $bcc = []
    ): bool {
        try {
            global $mysqli;
            
            // Prepare twig data with global config values
            $twigData = array_merge($data, [
                'mail_from_name' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'লগ ধাকা',
                'site_url' => defined('SITE_URL') ? SITE_URL : '',
            ]);
            
            $body = null;
            
            // Try Twig template first with proper path
            $twigTemplate = 'emails/' . $template . '.twig';
            $twigFile = EMAIL_TEMPLATE_DIR . '/' . $template . '.twig';
            
            if (file_exists($twigFile)) {
                // Use Twig template
                if (!class_exists('TwigManager')) {
                    $this->logError("TwigManager class not found for template: $template");
                    throw new Exception("TwigManager class not found");
                }
                
                try {
                    $twigManager = new TwigManager($mysqli);
                    $body = $twigManager->render($twigTemplate, $twigData);
                    
                    if (empty($body)) {
                        throw new Exception("Twig template rendered empty content");
                    }
                } catch (Exception $e) {
                    $this->logError("Twig rendering error for $template: {$e->getMessage()}");
                    throw $e;
                }
            } else {
                // Fallback to PHP template
                $phpTemplate = $template . '.php';
                $phpFile = EMAIL_TEMPLATE_DIR . '/' . $phpTemplate;
                
                if (!file_exists($phpFile)) {
                    $this->logError("Email template not found: $twigFile or $phpFile");
                    throw new Exception("Email template not found: $twigFile or $phpFile");
                }

                try {
                    // Render PHP template
                    ob_start();
                    extract($twigData, EXTR_SKIP);
                    require $phpFile;
                    $body = ob_get_clean();
                    
                    if (empty($body)) {
                        throw new Exception("PHP template rendered empty content");
                    }
                } catch (Exception $e) {
                    ob_end_clean(); // Clean up output buffer on error
                    $this->logError("PHP template rendering error for $template: {$e->getMessage()}");
                    throw $e;
                }
            }

            // Final validation
            if (empty($body)) {
                throw new Exception("Template rendering resulted in empty body");
            }

            // Allow overrides from system settings: admin-editable message bodies
            try {
                if (function_exists('getSettings')) {
                    $settings = getSettings();
                    $settingKey = 'email_template_' . $template;
                    if (!empty($settings[$settingKey])) {
                        // Use stored template body (may contain twig placeholders)
                        $stored = $settings[$settingKey];
                        // Render stored template via Twig to resolve placeholders
                        if (class_exists('TwigManager')) {
                            $twigManager = new TwigManager($mysqli);
                            $twigEngine = $twigManager->getTwig();
                            try {
                                $body = $twigEngine->createTemplate($stored)->render($twigData);
                            } catch (\Throwable $e) {
                                // If rendering stored template fails, keep previously rendered body
                                $this->logError("Stored template render failed for $settingKey: " . $e->getMessage());
                            }
                        } else {
                            // If TwigManager not available, use raw stored body
                            $body = $stored;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Do not block sending if settings lookup fails
                $this->logError('Settings lookup/render error: ' . $e->getMessage());
            }

            // Use provided subject or extract from template
            if (empty($subject)) {
                $subject = $data['subject'] ?? 'No Subject';
            }

            return $this->sendEmail($to, $subject, $body, $cc, $bcc);

        } catch (Exception $e) {
            $this->logError("Template email error: {$e->getMessage()} | Template: $template | To: $to");
            return false;
        }
    }

    /**
     * Send email with retry logic
     *
     * @return bool
     */
    private function sendWithRetry(): bool {
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            try {
                // Attempt to send
                if ($this->mail->send()) {
                    $recipient = '';
                    $toAddrs = $this->mail->getToAddresses();
                    if (!empty($toAddrs) && isset($toAddrs[0][0])) {
                        $recipient = $toAddrs[0][0];
                    }
                    $this->logSuccess("Email sent successfully to: $recipient");
                    // Clear recipients/attachments for next use
                    $this->reset();
                    return true;
                }
            } catch (PHPMailerException $e) {
                $this->logError("Send attempt " . ($attempt + 1) . " failed: {$e->getMessage()}");

                $attempt++;

                if ($attempt < $this->maxRetries) {
                    // Wait before retry
                    sleep($this->retryDelay);
                    // Reset connection
                    $this->mail->smtpClose();
                    // Clear recipients/attachments before next attempt
                    $this->reset();
                }
            }
        }

        $this->logError("Email failed after {$this->maxRetries} attempts");
        return false;
    }

    /**
     * Log success message
     *
     * @param string $message
     */
    private function logSuccess(string $message): void {
        $this->log("[SUCCESS] $message");
    }

    /**
     * Log error message
     *
     * @param string $message
     */
    private function logError(string $message): void {
        $this->log("[ERROR] $message");
    }

    /**
     * Log message to file
     *
     * @param string $message
     */
    private function log(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";

        $logDir = dirname($this->logFile);

        // Ensure directory exists
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        // Attempt to write log (best-effort). On Windows permissions may differ.
        try {
            if (is_dir($logDir) && is_writable($logDir)) {
                file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
            } else {
                // Fallback to error_log if file path not writable
                error_log($logEntry);
            }
        } catch (Throwable $e) {
            // Ensure we don't throw from logger
            error_log("EmailService log write failed: " . $e->getMessage());
            error_log($logEntry);
        }

        // Also log to error_log for detailed debug when enabled
        if ($this->debug) {
            error_log($logEntry);
        }
    }

    /**
     * Reset mail object for next email
     */
    public function reset(): void {
        $this->mail->clearAllRecipients();
        $this->mail->clearAttachments();
        $this->mail->clearCustomHeaders();
    }

    /**
     * Verify SMTP connection
     *
     * @return array
     */
    public function verifyConnection(): array {
        try {
            $this->mail->smtpConnect();
            $this->mail->smtpClose();

            return [
                'success' => true,
                'message' => 'SMTP connection successful',
            ];
        } catch (PHPMailerException $e) {
            return [
                'success' => false,
                'message' => 'SMTP connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get email configuration
     *
     * @return array
     */
    public static function getConfig(): array {
        return [
            'driver' => MAIL_DRIVER,
            'host' => SMTP_HOST,
            'port' => SMTP_PORT,
            'encryption' => SMTP_ENCRYPTION,
            'from' => MAIL_FROM_ADDRESS,
            'from_name' => MAIL_FROM_NAME,
        ];
    }
}