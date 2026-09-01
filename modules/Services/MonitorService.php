<?php
/**
 * modules/Services/MonitorService.php
 *
 * Production monitoring and alerting service.
 * Provides health checks, metrics, and alert capabilities.
 */

class MonitorService
{
    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli = null)
    {
        global $mysqli;
        $this->mysqli = $mysqli ?? $mysqli;
    }

    /**
     * Full system health check.
     *
     * @return array{status: string, checks: array, timestamp: string}
     */
    public function healthCheck(): array
    {
        $checks = [];
        $allHealthy = true;

        // Database connectivity
        $checks['database'] = $this->checkDatabase();
        if ($checks['database']['status'] !== 'ok') $allHealthy = false;

        // Disk space
        $checks['disk'] = $this->checkDiskSpace();
        if ($checks['disk']['status'] !== 'ok') $allHealthy = false;

        // Memory usage
        $checks['memory'] = $this->checkMemory();
        if ($checks['memory']['status'] !== 'ok') $allHealthy = false;

        // Email queue health
        $checks['email_queue'] = $this->checkEmailQueue();
        if ($checks['email_queue']['status'] !== 'ok') $allHealthy = false;

        // Chat system
        $checks['chat'] = $this->checkChatSystem();
        if ($checks['chat']['status'] !== 'ok') $allHealthy = false;

        // FCM configuration
        $checks['fcm'] = $this->checkFCM();
        if ($checks['fcm']['status'] !== 'ok') $allHealthy = false;

        return [
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => gmdate('c'),
        ];
    }

    /**
     * Check database connectivity and response time.
     */
    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            $result = $this->mysqli->query("SELECT 1 as ok");
            $latency = round((microtime(true) - $start) * 1000, 2);

            if (!$result) {
                return ['status' => 'error', 'message' => 'Database query failed', 'latency_ms' => $latency];
            }
            $row = $result->fetch_assoc();
            $result->free();

            return [
                'status' => $latency > 1000 ? 'warn' : 'ok',
                'latency_ms' => $latency,
                'message' => $latency > 1000 ? 'Slow database response' : 'OK',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check available disk space.
     */
    private function checkDiskSpace(): array
    {
        $free = @disk_free_space(BASE_PATH ?? __DIR__ . '/../../');
        $total = @disk_total_space(BASE_PATH ?? __DIR__ . '/../../');

        if ($free === false || $total === false) {
            return ['status' => 'warn', 'message' => 'Unable to check disk space'];
        }

        $usedPercent = round((1 - ($free / $total)) * 100, 1);
        $freeGB = round($free / (1024 * 1024 * 1024), 2);

        $status = 'ok';
        if ($usedPercent > 90) {
            $status = 'error';
        } elseif ($usedPercent > 80) {
            $status = 'warn';
        }

        return [
            'status' => $status,
            'free_gb' => $freeGB,
            'used_percent' => $usedPercent,
            'message' => $status === 'ok' ? 'OK' : "Disk usage at {$usedPercent}%",
        ];
    }

    /**
     * Check memory usage.
     */
    private function checkMemory(): array
    {
        $used = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = (int)ini_get('memory_limit');

        $usedMB = round($used / (1024 * 1024), 2);
        $peakMB = round($peak / (1024 * 1024), 2);

        $status = 'ok';
        if ($limit > 0 && $used > $limit * 0.9) {
            $status = 'error';
        } elseif ($limit > 0 && $used > $limit * 0.75) {
            $status = 'warn';
        }

        return [
            'status' => $status,
            'used_mb' => $usedMB,
            'peak_mb' => $peakMB,
            'message' => $status === 'ok' ? 'OK' : "Memory usage: {$usedMB}MB",
        ];
    }

    /**
     * Check email queue health.
     */
    private function checkEmailQueue(): array
    {
        try {
            $result = $this->mysqli->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    MAX(CASE WHEN status = 'queued' THEN created_at ELSE NULL END) as oldest_queued
                FROM chat_email_queue
            ");
            if (!$result) {
                return ['status' => 'ok', 'message' => 'Email queue table not found (not critical)'];
            }
            $row = $result->fetch_assoc();
            $result->free();

            $queued = (int)($row['queued'] ?? 0);
            $failed = (int)($row['failed'] ?? 0);

            $status = 'ok';
            $message = "Queued: {$queued}, Failed: {$failed}";

            if ($queued > 100) {
                $status = 'warn';
                $message = "High queue backlog: {$queued} pending";
            }

            if ($failed > 50) {
                $status = 'error';
                $message = "Many failed emails: {$failed}";
            }

            return ['status' => $status, 'queued' => $queued, 'failed' => $failed, 'message' => $message];
        } catch (\Throwable $e) {
            return ['status' => 'warn', 'message' => 'Email queue check failed: ' . $e->getMessage()];
        }
    }

    /**
     * Check chat system health.
     */
    private function checkChatSystem(): array
    {
        try {
            $result = $this->mysqli->query("
                SELECT
                    (SELECT COUNT(*) FROM chat_sessions WHERE status = 'active') as active_sessions,
                    (SELECT COUNT(*) FROM chat_messages WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) as recent_messages,
                    (SELECT COUNT(*) FROM fcm_tokens WHERE revoked_at IS NULL) as active_tokens
            ");
            if (!$result) {
                return ['status' => 'warn', 'message' => 'Chat tables not found'];
            }
            $row = $result->fetch_assoc();
            $result->free();

            return [
                'status' => 'ok',
                'active_sessions' => (int)($row['active_sessions'] ?? 0),
                'recent_messages_1h' => (int)($row['recent_messages'] ?? 0),
                'active_fcm_tokens' => (int)($row['active_tokens'] ?? 0),
                'message' => 'OK',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'warn', 'message' => 'Chat health check failed: ' . $e->getMessage()];
        }
    }

    /**
     * Check FCM configuration.
     */
    private function checkFCM(): array
    {
        if (!function_exists('getFirebaseConfig')) {
            require_once __DIR__ . '/../../config/firebase.php';
        }

        $config = getFirebaseConfig();

        if (!$config['enabled']) {
            return ['status' => 'warn', 'message' => 'FCM is disabled'];
        }

        if (empty($config['service_account_path']) || !file_exists($config['service_account_path'])) {
            return ['status' => 'error', 'message' => 'FCM service account not found'];
        }

        if (empty($config['project_id'])) {
            return ['status' => 'error', 'message' => 'FCM project_id not configured'];
        }

        return ['status' => 'ok', 'message' => 'FCM configured', 'project_id' => $config['project_id']];
    }

    /**
     * Get application metrics for monitoring dashboards.
     */
    public function getMetrics(): array
    {
        $metrics = [];

        // Chat metrics
        try {
            $result = $this->mysqli->query("
                SELECT
                    (SELECT COUNT(*) FROM chat_sessions WHERE status = 'active') as active_sessions_today,
                    (SELECT COUNT(*) FROM chat_sessions WHERE created_at >= CURDATE()) as sessions_today,
                    (SELECT COUNT(*) FROM chat_messages WHERE created_at >= CURDATE()) as messages_today,
                    (SELECT COUNT(*) FROM chat_messages WHERE sender_type = 'visitor' AND is_read = 0) as unread_messages,
                    (SELECT AVG(TIMESTAMPDIFF(SECOND, cm.created_at, cm2.created_at))
                     FROM chat_messages cm
                     JOIN chat_messages cm2 ON cm2.session_id = cm.session_id
                     AND cm2.sender_type = 'admin' AND cm2.created_at > cm.created_at
                     WHERE cm.sender_type = 'visitor' AND cm.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ) as avg_response_time_sec
            ");
            if ($result) {
                $row = $result->fetch_assoc();
                $result->free();
                $metrics['chat'] = [
                    'active_sessions' => (int)($row['active_sessions_today'] ?? 0),
                    'sessions_today' => (int)($row['sessions_today'] ?? 0),
                    'messages_today' => (int)($row['messages_today'] ?? 0),
                    'unread_messages' => (int)($row['unread_messages'] ?? 0),
                    'avg_response_time_sec' => $row['avg_response_time_sec'] ? round((float)$row['avg_response_time_sec'], 1) : null,
                ];
            }
        } catch (\Throwable $e) {
            $metrics['chat'] = ['error' => $e->getMessage()];
        }

        // Notification metrics
        try {
            $result = $this->mysqli->query("
                SELECT
                    channel,
                    status,
                    COUNT(*) as count
                FROM chat_notification_log
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY channel, status
            ");
            $notifMetrics = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $channel = $row['channel'];
                    if (!isset($notifMetrics[$channel])) {
                        $notifMetrics[$channel] = [];
                    }
                    $notifMetrics[$channel][$row['status']] = (int)$row['count'];
                }
                $result->free();
            }
            $metrics['notifications_24h'] = $notifMetrics;
        } catch (\Throwable $e) {
            $metrics['notifications_24h'] = ['error' => $e->getMessage()];
        }

        // Email queue metrics
        try {
            $emailQueue = new EmailQueueService($this->mysqli);
            $metrics['email_queue'] = $emailQueue->getStats();
        } catch (\Throwable $e) {
            $metrics['email_queue'] = ['error' => $e->getMessage()];
        }

        return $metrics;
    }
}
