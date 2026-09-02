<?php
/**
 * scripts/worker_email_queue.php
 *
 * Email Queue Worker — processes queued emails.
 * Run via cron every minute:
 *   * * * * * php /path/to/scripts/worker_email_queue.php >> /path/to/storage/logs/email_worker.log 2>&1
 *
 * Also handles:
 * - FCM token cleanup (expired > 30 days)
 * - Notification log retention (old logs > 90 days)
 * - Email queue cleanup (old entries > 30 days)
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Bootstrap the application
$basePath = dirname(__DIR__);
require_once $basePath . '/config/config.php';
require_once $basePath . '/config/autoload.php';
require_once $basePath . '/vendor/autoload.php';

$timestamp = date('Y-m-d H:i:s');
$logFile = $basePath . '/storage/logs/email_worker.log';

function logMessage(string $msg): void
{
    global $timestamp, $logFile;
    $entry = "[$timestamp] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    echo $entry;
}

logMessage("=== Email Worker Started ===");

try {
    global $mysqli;

    // 1. Process email queue
    $emailQueue = new EmailQueueService($mysqli);
    $result = $emailQueue->processQueue(20);
    logMessage("Email queue: sent={$result['sent']}, failed={$result['failed']}");

    $stats = $emailQueue->getStats();
    logMessage("Queue stats: " . json_encode($stats));

    // 2. Cleanup old email queue entries
    $cleaned = $emailQueue->cleanup(30);
    if ($cleaned > 0) {
        logMessage("Email queue cleanup: removed {$cleaned} old entries");
    }

    // 3. Cleanup expired FCM tokens (older than 30 days)
    $chatModel = new ChatModel($mysqli);
    $expiredTokens = $chatModel->cleanExpiredFcmTokens();
    if ($expiredTokens > 0) {
        logMessage("FCM token cleanup: removed {$expiredTokens} expired tokens");
    }

    // 4. Cleanup old notification logs (older than 90 days)
    $stmt = $mysqli->prepare("
        DELETE FROM chat_notification_log
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $stmt->execute();
    $notifCleaned = $stmt->affected_rows;
    $stmt->close();
    if ($notifCleaned > 0) {
        logMessage("Notification log cleanup: removed {$notifCleaned} old entries");
    }

    // 5. Cleanup old chat rate limits (older than 1 day)
    $chatModel->cleanRateLimits();

    // 6. Cleanup old closed sessions (older than 90 days)
    $cutoff90 = date('Y-m-d H:i:s', strtotime('-90 days'));
    $oldSessions = $chatModel->countOldClosedSessions($cutoff90);
    if ($oldSessions > 0) {
        $chatModel->deleteOldClosedSessionMessages($cutoff90);
        $deletedSessions = $chatModel->deleteOldClosedSessions($cutoff90);
        logMessage("Session cleanup: removed {$deletedSessions} old closed sessions");
    }

    // 7. Cleanup old rate limit entries (older than 1 hour)
    $chatModel->cleanRateLimits();

    logMessage("=== Email Worker Completed ===");

} catch (\Throwable $e) {
    logMessage("ERROR: " . $e->getMessage());
    logMessage("Trace: " . $e->getTraceAsString());
    exit(1);
}
