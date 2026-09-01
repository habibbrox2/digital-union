<?php
/**
 * modules/Services/EmailQueueService.php
 *
 * Email Queue Service — asynchronous email delivery.
 * Emails are queued in the database and processed by a worker script.
 * Replaces synchronous email sending for reliability and non-blocking behavior.
 */

class EmailQueueService
{
    private \mysqli $mysqli;
    private int $maxRetries;

    public function __construct(\mysqli $mysqli = null)
    {
        global $mysqli;
        $this->mysqli = $mysqli ?? $mysqli;
        $this->maxRetries = (int)($_ENV['EMAIL_MAX_RETRIES'] ?? 3);
    }

    /**
     * Create the email_queue table if it doesn't exist.
     */
    public function ensureTable(): void
    {
        $this->mysqli->query("
            CREATE TABLE IF NOT EXISTS `chat_email_queue` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `recipient_email` VARCHAR(255) NOT NULL,
                `recipient_name` VARCHAR(150) NOT NULL DEFAULT '',
                `subject` VARCHAR(500) NOT NULL,
                `body` TEXT NOT NULL,
                `template` VARCHAR(100) DEFAULT NULL,
                `template_data` JSON DEFAULT NULL,
                `status` ENUM('queued','processing','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
                `last_error` TEXT DEFAULT NULL,
                `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `sent_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_status_scheduled` (`status`, `scheduled_at`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Queue an email for later delivery.
     *
     * @param string $to      Recipient email
     * @param string $subject Email subject
     * @param string $body    Email body (HTML)
     * @param array  $options Optional: recipient_name, template, template_data, scheduled_at
     * @return int Queue entry ID
     */
    public function queue(string $to, string $subject, string $body, array $options = []): int
    {
        $this->ensureTable();

        $recipientName = trim($options['recipient_name'] ?? '');
        $template = $options['template'] ?? null;
        $templateData = !empty($options['template_data']) ? json_encode($options['template_data'], JSON_UNESCAPED_UNICODE) : null;
        $scheduledAt = $options['scheduled_at'] ?? date('Y-m-d H:i:s');

        $stmt = $this->mysqli->prepare("
            INSERT INTO chat_email_queue (recipient_email, recipient_name, subject, body, template, template_data, status, scheduled_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'queued', ?, NOW())
        ");
        $stmt->bind_param("sssssss", $to, $recipientName, $subject, $body, $template, $templateData, $scheduledAt);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Queue a templated email for later delivery.
     *
     * @param string $to      Recipient email
     * @param string $subject Email subject
     * @param string $template Twig template name (without .twig)
     * @param array  $data    Template data
     * @param array  $options Optional: recipient_name, scheduled_at
     * @return int Queue entry ID
     */
    public function queueTemplate(string $to, string $subject, string $template, array $data = [], array $options = []): int
    {
        // Render the template now so the body is stored ready to send
        try {
            $emailService = new EmailService($this->mysqli);
            // Temporarily render the template body
            $body = $this->renderTemplate($template, $data);
        } catch (\Throwable $e) {
            error_log('[EmailQueue] Template render failed: ' . $e->getMessage());
            $body = '<p>' . htmlspecialchars($subject) . '</p>';
        }

        return $this->queue($to, $subject, $body, array_merge($options, [
            'template' => $template,
            'template_data' => $data,
        ]));
    }

    /**
     * Render a Twig template to HTML.
     */
    private function renderTemplate(string $template, array $data): string
    {
        $twigFile = __DIR__ . '/../../templates/emails/' . $template . '.twig';
        if (!file_exists($twigFile)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        if (!class_exists('TwigManager')) {
            throw new \RuntimeException("TwigManager not available");
        }

        global $mysqli;
        $twigManager = new TwigManager($mysqli);
        $twigData = array_merge($data, [
            'mail_from_name' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'lgDhaka',
            'site_url' => defined('SITE_URL') ? SITE_URL : '',
        ]);

        return $twigManager->render('emails/' . $template . '.twig', $twigData);
    }

    /**
     * Process queued emails — called by the worker script.
     * Picks up to $batchSize queued emails, sends them, and updates status.
     *
     * @param int $batchSize Number of emails to process per run
     * @return array ['sent' => int, 'failed' => int]
     */
    public function processQueue(int $batchSize = 10): array
    {
        $this->ensureTable();

        $sent = 0;
        $failed = 0;

        // Pick queued emails that are ready to send
        $stmt = $this->mysqli->prepare("
            SELECT id, recipient_email, recipient_name, subject, body, template, template_data, attempts, max_attempts
            FROM chat_email_queue
            WHERE status = 'queued' AND scheduled_at <= NOW()
            ORDER BY created_at ASC
            LIMIT ?
        ");
        $stmt->bind_param("i", $batchSize);
        $stmt->execute();
        $result = $stmt->get_result();

        $emails = [];
        while ($row = $result->fetch_assoc()) {
            $emails[] = $row;
        }
        $stmt->close();

        if (empty($emails)) {
            return ['sent' => 0, 'failed' => 0];
        }

        foreach ($emails as $email) {
            $id = (int)$email['id'];

            // Mark as processing
            $this->updateStatus($id, 'processing');

            try {
                $emailService = new EmailService($this->mysqli);
                $success = $emailService->sendEmail(
                    $email['recipient_email'],
                    $email['subject'],
                    $email['body']
                );

                if ($success) {
                    $this->updateStatus($id, 'sent');
                    $sent++;
                } else {
                    $this->handleFailure($id, $email);
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->handleFailure($id, $email, $e->getMessage());
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Handle a failed email: increment attempts, mark failed or re-queue.
     */
    private function handleFailure(int $id, array $email, string $error = ''): void
    {
        $attempts = (int)$email['attempts'] + 1;
        $maxAttempts = (int)$email['max_attempts'];

        if ($attempts >= $maxAttempts) {
            $this->updateStatus($id, 'failed', $error, $attempts);
        } else {
            // Re-queue with exponential backoff
            $delay = min(300, 30 * pow(2, $attempts - 1)); // 30s, 60s, 120s, 300s max
            $nextRun = date('Y-m-d H:i:s', time() + $delay);
            $stmt = $this->mysqli->prepare("
                UPDATE chat_email_queue
                SET status = 'queued', attempts = ?, last_error = ?, scheduled_at = ?
                WHERE id = ?
            ");
            $stmt->bind_param("issi", $attempts, $error, $nextRun, $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Update the status of a queue entry.
     */
    private function updateStatus(int $id, string $status, string $error = '', int $attempts = -1): void
    {
        if ($status === 'sent') {
            $stmt = $this->mysqli->prepare("
                UPDATE chat_email_queue SET status = 'sent', sent_at = NOW(), updated_at = NOW() WHERE id = ?
            ");
            $stmt->bind_param("i", $id);
        } elseif ($status === 'failed') {
            $stmt = $this->mysqli->prepare("
                UPDATE chat_email_queue SET status = 'failed', last_error = ?, attempts = ?, updated_at = NOW() WHERE id = ?
            ");
            $stmt->bind_param("ssi", $error, $attempts, $id);
        } else {
            $stmt = $this->mysqli->prepare("
                UPDATE chat_email_queue SET status = ?, updated_at = NOW() WHERE id = ?
            ");
            $stmt->bind_param("si", $status, $id);
        }

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Cancel a queued email.
     */
    public function cancel(int $id): void
    {
        $this->updateStatus($id, 'cancelled');
    }

    /**
     * Get queue statistics.
     */
    public function getStats(): array
    {
        $result = $this->mysqli->query("
            SELECT status, COUNT(*) as count
            FROM chat_email_queue
            GROUP BY status
        ");
        $stats = ['queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats[$row['status']] = (int)$row['count'];
            }
            $result->free();
        }
        return $stats;
    }

    /**
     * Clean old queue entries (older than specified days).
     */
    public function cleanup(int $days = 30): int
    {
        $stmt = $this->mysqli->prepare("
            DELETE FROM chat_email_queue
            WHERE status IN ('sent', 'cancelled', 'failed')
            AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }
}
