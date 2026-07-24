<?php

/**
 * models/ChatModel.php
 *
 * Data access layer for the Custom Live Chat Support System.
 * Encapsulates all chat-related database operations.
 * Follows the existing model pattern (UserModel, UnionModel, etc.).
 */

class ChatModel
{
    private $mysqli;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Check if any admin is currently online based on recent activity.
     * Uses three signals: admin typing (5 min), admin messages (5 min), last_login (30 min).
     */
    public function isAdminOnline(): bool
    {
        $result = $this->mysqli->query("
            SELECT
                EXISTS(
                    SELECT 1 FROM chat_sessions
                    WHERE admin_typing_at IS NOT NULL
                    AND admin_typing_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ) OR
                EXISTS(
                    SELECT 1 FROM chat_messages
                    WHERE (sender_type = 'admin' OR admin_id IS NOT NULL OR auto_reply = 1)
                    AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ) OR
                EXISTS(
                    SELECT 1 FROM users
                    WHERE last_login IS NOT NULL
                    AND last_login > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                )
                as is_online
        ");
        if (!$result) return false;
        $row = $result->fetch_assoc();
        $result->free();
        return !empty($row) && (int)$row['is_online'] > 0;
    }

    // ================================================================
    // MIGRATION
    // ================================================================

    /**
     * Create all chat tables if they don't exist.
     */
    public function createTables(): void
    {
        $this->mysqli->query("
            CREATE TABLE IF NOT EXISTS `chat_sessions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `session_id` VARCHAR(64) NOT NULL,
                `session_sig` VARCHAR(72) DEFAULT NULL,
                `visitor_name` VARCHAR(100) DEFAULT NULL,
                `visitor_union_name` VARCHAR(150) NOT NULL DEFAULT '',
                `visitor_location` VARCHAR(120) NOT NULL DEFAULT '',
                `visitor_device` VARCHAR(40) NOT NULL DEFAULT '',
                `visitor_browser` VARCHAR(100) NOT NULL DEFAULT '',
                `visitor_os` VARCHAR(100) NOT NULL DEFAULT '',
                `visitor_user_agent` VARCHAR(255) NOT NULL DEFAULT '',
                `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
                `visitor_typing_at` DATETIME DEFAULT NULL,
                `admin_typing_at` DATETIME DEFAULT NULL,
                `last_auto_reply_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_session_id` (`session_id`),
                KEY `idx_status` (`status`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->mysqli->query("
            CREATE TABLE IF NOT EXISTS `chat_messages` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `session_id` VARCHAR(64) NOT NULL,
                `message` TEXT NOT NULL,
                `message_type` VARCHAR(20) NOT NULL DEFAULT 'text',
                `file_url` VARCHAR(500) DEFAULT NULL,
                `file_name` VARCHAR(255) DEFAULT NULL,
                `file_size` BIGINT UNSIGNED DEFAULT NULL,
                `file_type` VARCHAR(100) DEFAULT NULL,
                `sender_type` ENUM('visitor','admin') NOT NULL DEFAULT 'visitor',
                `admin_id` INT UNSIGNED DEFAULT NULL,
                `is_read` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `delivered_at` DATETIME DEFAULT NULL,
                `read_at` DATETIME DEFAULT NULL,
                `auto_reply` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_session` (`session_id`),
                KEY `idx_session_created_id` (`session_id`, `created_at`, `id`),
                KEY `idx_sender` (`sender_type`),
                KEY `idx_created` (`created_at`),
                KEY `idx_read` (`is_read`),
                KEY `idx_session_sender_read` (`session_id`, `sender_type`, `is_read`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->mysqli->query("
            CREATE TABLE IF NOT EXISTS `chat_canned_responses` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(150) NOT NULL,
                `message` TEXT NOT NULL,
                `category` VARCHAR(80) NOT NULL DEFAULT '',
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_category_sort` (`category`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->mysqli->query("
            CREATE TABLE IF NOT EXISTS `chat_offline_messages` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `visitor_name` VARCHAR(100) NOT NULL DEFAULT '',
                `visitor_phone` VARCHAR(30) DEFAULT NULL,
                `visitor_email` VARCHAR(100) DEFAULT NULL,
                `message` TEXT NOT NULL,
                `is_read` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_read` (`is_read`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->mysqli->query("
            CREATE TABLE IF NOT EXISTS `chat_rate_limits` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `ip_hash` VARCHAR(64) NOT NULL,
                `endpoint` VARCHAR(100) NOT NULL,
                `window` VARCHAR(20) NOT NULL,
                `count` INT UNSIGNED NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_ip_endpoint_window` (`ip_hash`, `endpoint`, `window`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Run incremental column migrations.
     */
    public function addMissingColumns(): void
    {
        $checks = [
            ['table' => 'chat_sessions', 'column' => 'visitor_union_name', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `visitor_union_name` VARCHAR(150) NOT NULL DEFAULT '' AFTER `visitor_name`"],
            ['table' => 'chat_sessions', 'column' => 'visitor_typing_at', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `visitor_typing_at` DATETIME DEFAULT NULL AFTER `status`"],
            ['table' => 'chat_sessions', 'column' => 'admin_typing_at', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `admin_typing_at` DATETIME DEFAULT NULL AFTER `visitor_typing_at`"],
            ['table' => 'chat_messages', 'column' => 'auto_reply', 'sql' => "ALTER TABLE chat_messages ADD COLUMN `auto_reply` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_read`"],
            ['table' => 'chat_messages', 'column' => 'file_url', 'sql' => "ALTER TABLE chat_messages ADD COLUMN `file_url` VARCHAR(500) DEFAULT NULL AFTER `message`"],
            ['table' => 'chat_messages', 'column' => 'file_name', 'sql' => "ALTER TABLE chat_messages ADD COLUMN `file_name` VARCHAR(255) DEFAULT NULL AFTER `file_url`"],
            ['table' => 'chat_messages', 'column' => 'file_size', 'sql' => "ALTER TABLE chat_messages ADD COLUMN `file_size` BIGINT UNSIGNED DEFAULT NULL AFTER `file_name`"],
            ['table' => 'chat_messages', 'column' => 'file_type', 'sql' => "ALTER TABLE chat_messages ADD COLUMN `file_type` VARCHAR(100) DEFAULT NULL AFTER `file_size`"],
            ['table' => 'chat_messages', 'column' => 'message_type', 'sql' => "ALTER TABLE chat_messages ADD COLUMN `message_type` VARCHAR(20) NOT NULL DEFAULT 'text' AFTER `file_type`"],
            ['table' => 'chat_sessions', 'column' => 'last_auto_reply_at', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `last_auto_reply_at` DATETIME DEFAULT NULL AFTER `admin_typing_at`"],
            ['table' => 'chat_sessions', 'column' => 'session_sig', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `session_sig` VARCHAR(72) DEFAULT NULL AFTER `session_id`"],
            ['table' => 'chat_sessions', 'column' => 'visitor_location', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `visitor_location` VARCHAR(120) NOT NULL DEFAULT '' AFTER `visitor_union_name`"],
            ['table' => 'chat_sessions', 'column' => 'visitor_device', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `visitor_device` VARCHAR(40) NOT NULL DEFAULT '' AFTER `visitor_location`"],
            ['table' => 'chat_sessions', 'column' => 'visitor_browser', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `visitor_browser` VARCHAR(100) NOT NULL DEFAULT '' AFTER `visitor_device`"],
            ['table' => 'chat_sessions', 'column' => 'visitor_os', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `visitor_os` VARCHAR(100) NOT NULL DEFAULT '' AFTER `visitor_browser`"],
            ['table' => 'chat_sessions', 'column' => 'visitor_user_agent', 'sql' => "ALTER TABLE chat_sessions ADD COLUMN `visitor_user_agent` VARCHAR(255) NOT NULL DEFAULT '' AFTER `visitor_os`"],
            ['table' => 'chat_messages', 'column' => 'delivered_at', 'sql' => "ALTER TABLE chat_messages ADD COLUMN `delivered_at` DATETIME DEFAULT NULL AFTER `is_read`"],
            ['table' => 'chat_messages', 'column' => 'read_at', 'sql' => "ALTER TABLE chat_messages ADD COLUMN `read_at` DATETIME DEFAULT NULL AFTER `delivered_at`"],
        ];
        foreach ($checks as $c) {
            $r = $this->mysqli->query("SHOW COLUMNS FROM {$c['table']} LIKE '{$c['column']}'");
            if ($r && $r->num_rows === 0) {
                $this->mysqli->query($c['sql']);
            }
            if ($r) $r->free();
        }

        $indexes = [
            ['table' => 'chat_messages', 'name' => 'idx_session_created_id', 'sql' => 'ALTER TABLE chat_messages ADD INDEX `idx_session_created_id` (`session_id`, `created_at`, `id`)'],
            ['table' => 'chat_messages', 'name' => 'idx_session_sender_read', 'sql' => 'ALTER TABLE chat_messages ADD INDEX `idx_session_sender_read` (`session_id`, `sender_type`, `is_read`)'],
        ];
        foreach ($indexes as $index) {
            $r = $this->mysqli->query("SHOW INDEX FROM {$index['table']} WHERE Key_name = '{$index['name']}'");
            $exists = $r && $r->num_rows > 0;
            if ($r) $r->free();
            if (!$exists) $this->mysqli->query($index['sql']);
        }

        // Older MySQL installations accepted an empty ENUM value when strict
        // mode was disabled. admin_id/auto_reply are authoritative, so repair
        // those rows once and keep all future reads consistent.
        $this->mysqli->query("UPDATE chat_messages SET sender_type = 'admin' WHERE (admin_id IS NOT NULL OR auto_reply = 1) AND sender_type <> 'admin'");
        $this->mysqli->query("UPDATE chat_messages SET sender_type = 'visitor' WHERE sender_type IS NULL OR sender_type = ''");
    }

    /**
     * Seed default canned responses if table is empty.
     */
    public function seedCannedResponses(): void
    {
        $r = $this->mysqli->query("SELECT COUNT(*) as cnt FROM chat_canned_responses");
        if (!$r) return;
        $row = $r->fetch_assoc();
        if ((int)$row['cnt'] === 0) {
            $this->mysqli->query(
                "INSERT INTO chat_canned_responses (title, message, category, sort_order) VALUES
                ('নাগরিকত্ব সনদ', 'নাগরিকত্ব সনদের জন্য আবেদন করতে পোর্টালে লগইন করে নাগরিক সনদ বিভাগে যান। প্রয়োজনীয় ডকুমেন্ট: জাতীয় পরিচয়পত্রের কপি, পিতার পরিচয়পত্রের কপি, এবং দুই কপি পাসপোর্টサイズের ছবি।', 'সেবা তথ্য', 1),
                ('বিবিধ সনদ', 'বিবিধ সনদের জন্য আবেদন করতে পোর্টালে গিয়ে প্রয়োজনীয় ফর্ম পূরণ করুন।', 'সেবা তথ্য', 2),
                ('অনলাইনে আবেদন', 'আপনি সরাসরি পোর্টালে লগইন করে আবেদন করতে পারেন। রেজিস্ট্রেশন না থাকলে প্রথমে রেজিস্টার করুন।', 'সেবা তথ্য', 3),
                ('অভ্যর্থনা', 'আপনাকে স্বাগতম! আমি সহায়ক, আপনার সেবা সহায়তার জন্য এখানে আছি। আপনার যে কোনো প্রশ্ন জানাতে পারেন।', 'সাধারণ', 1),
                ('ধন্যবাদ', 'আপনাকে ধন্যবাদ! আপনার কোনো অতিরিক্ত প্রশ্ন থাকলে জানাবেন। ভালো থাকবেন।', 'সাধারণ', 2),
                ('অপেক্ষার অনুরোধ', 'দয়া করে একটু অপেক্ষা করুন, আমি আপনার তথ্য যাচাই করছি। খুব শীঘ্রই উত্তর দেয়া হবে।', 'সাধারণ', 3),
                ('অফিস সময়', 'আমাদের অফিস সময়: শনিবার থেকে বৃহস্পতিবার সকাল ৯টা থেকে বিকেল ৫টা। শুক্রবার ও সরকারি ছুটির দিন বন্ধ।', 'অফিস তথ্য', 1),
                ('যোগাযোগ', 'আমাদের সাথে যোগাযোগ করতে পারেন: ফোন: ০১৭০০-০০০০০০, ইমেইল: info@lgdhaka.local', 'অফিস তথ্য', 2)"
            );
        }
        $r->free();
    }

    // ================================================================
    // SESSIONS
    // ================================================================

    /**
     * Get a session by session_id.
     */
    public function getSession(string $sessionId): ?array
    {
        $stmt = $this->mysqli->prepare("SELECT id, session_id, session_sig, visitor_name, visitor_union_name, visitor_location, visitor_device, visitor_browser, visitor_os, visitor_user_agent, status, visitor_typing_at, admin_typing_at, last_auto_reply_at, created_at, updated_at FROM chat_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Create a new session.
     */
    public function createSession(string $sessionId, string $visitorName): int
    {
        $stmt = $this->mysqli->prepare("INSERT INTO chat_sessions (session_id, visitor_name, status, created_at, updated_at) VALUES (?, ?, 'active', NOW(), NOW())");
        $stmt->bind_param("ss", $sessionId, $visitorName);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Update session signature.
     */
    public function setSessionSig(int $id, string $sig): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET session_sig = ? WHERE id = ?");
        $stmt->bind_param("si", $sig, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update session signature by session_id (fallback when no id).
     */
    public function setSessionSigBySessionId(string $sessionId, string $sig): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET session_sig = ? WHERE session_id = ?");
        $stmt->bind_param("ss", $sig, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update visitor name for a session (only if name is currently empty).
     */
    public function updateVisitorName(string $sessionId, string $name): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET visitor_name = ?, updated_at = NOW() WHERE session_id = ?");
        $stmt->bind_param("ss", $name, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update visitor union name for a session.
     */
    public function updateUnionName(string $sessionId, string $unionName): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET visitor_union_name = ?, updated_at = NOW() WHERE session_id = ?");
        $stmt->bind_param("ss", $unionName, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /** Store privacy-safe visitor metadata; raw IP/GPS is never persisted. */
    public function updateVisitorMetadata(string $sessionId, array $metadata): void
    {
        $location = substr((string)($metadata['location'] ?? ''), 0, 120);
        $device = substr((string)($metadata['device'] ?? ''), 0, 40);
        $browser = substr((string)($metadata['browser'] ?? ''), 0, 100);
        $os = substr((string)($metadata['os'] ?? ''), 0, 100);
        $agent = substr((string)($metadata['user_agent'] ?? ''), 0, 255);
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET visitor_location = ?, visitor_device = ?, visitor_browser = ?, visitor_os = ?, visitor_user_agent = ? WHERE session_id = ?");
        $stmt->bind_param("ssssss", $location, $device, $browser, $os, $agent, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update session updated_at timestamp.
     */
    public function touchSession(string $sessionId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET updated_at = NOW() WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Close a session.
     */
    public function closeSession(string $sessionId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET status = 'closed', updated_at = NOW() WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Expire a visitor session and remove its conversation messages.
     * Closed sessions are retained as a lightweight audit record, while their
     * message history is removed immediately as required by the chat policy.
     */
    public function expireSession(string $sessionId): void
    {
        $this->mysqli->begin_transaction();

        try {
            $delete = $this->mysqli->prepare("DELETE FROM chat_messages WHERE session_id = ?");
            $delete->bind_param("s", $sessionId);
            if (!$delete->execute()) {
                throw new \RuntimeException('Unable to remove expired chat messages');
            }
            $delete->close();

            $update = $this->mysqli->prepare("UPDATE chat_sessions SET status = 'closed', visitor_typing_at = NULL, admin_typing_at = NULL, last_auto_reply_at = NULL, updated_at = NOW() WHERE session_id = ?");
            $update->bind_param("s", $sessionId);
            if (!$update->execute()) {
                throw new \RuntimeException('Unable to expire chat session');
            }
            $update->close();

            $this->mysqli->commit();
        } catch (\Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Set visitor typing timestamp.
     */
    public function setVisitorTyping(string $sessionId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET visitor_typing_at = NOW() WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Set admin typing timestamp.
     */
    public function setAdminTyping(string $sessionId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET admin_typing_at = NOW() WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Check if admin is typing (within timeout seconds).
     */
    public function isAdminTyping(string $sessionId, int $timeout = 5): bool
    {
        $stmt = $this->mysqli->prepare("SELECT admin_typing_at IS NOT NULL AND admin_typing_at > NOW() - INTERVAL ? SECOND as is_typing FROM chat_sessions WHERE session_id = ?");
        $stmt->bind_param("is", $timeout, $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !empty($row) && (int)$row['is_typing'] > 0;
    }

    /**
     * Check if visitor is typing (within timeout seconds).
     */
    public function isVisitorTyping(string $sessionId, int $timeout = 5): bool
    {
        $stmt = $this->mysqli->prepare("SELECT visitor_typing_at IS NOT NULL AND visitor_typing_at > NOW() - INTERVAL ? SECOND as is_typing FROM chat_sessions WHERE session_id = ?");
        $stmt->bind_param("is", $timeout, $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !empty($row) && (int)$row['is_typing'] > 0;
    }

    /**
     * Set last_auto_reply_at timestamp.
     */
    public function setLastAutoReplyAt(string $sessionId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET last_auto_reply_at = NOW() WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get last_auto_reply_at timestamp.
     */
    public function getLastAutoReplyAt(string $sessionId): ?string
    {
        $stmt = $this->mysqli->prepare("SELECT last_auto_reply_at FROM chat_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['last_auto_reply_at'] ?? null;
    }

    /**
     * Check if a session exists by session_id.
     */
    public function sessionExists(string $sessionId): bool
    {
        $stmt = $this->mysqli->prepare("SELECT id FROM chat_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    /**
     * Get session's updated_at timestamp.
     */
    public function getSessionUpdatedAt(string $sessionId): ?string
    {
        $stmt = $this->mysqli->prepare("SELECT updated_at FROM chat_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['updated_at'] ?? null;
    }

    /**
     * Get the last visitor activity for timeout calculations. Admin replies,
     * typing indicators and admin-side session touches must not keep a visitor
     * session alive.
     */
    public function getLastVisitorActivityAt(string $sessionId): ?string
    {
        $stmt = $this->mysqli->prepare(
            "SELECT COALESCE(MAX(cm.created_at), cs.created_at) AS last_activity
             FROM chat_sessions cs
             LEFT JOIN chat_messages cm
               ON cm.session_id = cs.session_id
              AND cm.sender_type = 'visitor'
              AND cm.admin_id IS NULL
              AND cm.auto_reply = 0
             WHERE cs.session_id = ?
             GROUP BY cs.session_id, cs.created_at"
        );
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['last_activity'] ?? null;
    }

    /**
     * Get session signature for verification.
     */
    public function getSessionSig(string $sessionId): ?string
    {
        $stmt = $this->mysqli->prepare("SELECT session_sig FROM chat_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['session_sig'] ?? null;
    }

    // ================================================================
    // MESSAGES
    // ================================================================

    /**
     * Insert a text message.
     */
    public function insertMessage(string $sessionId, string $message, string $senderType, ?int $adminId = null, int $autoReply = 0): int
    {
        if ($adminId !== null) {
            $stmt = $this->mysqli->prepare("INSERT INTO chat_messages (session_id, message, message_type, sender_type, admin_id, auto_reply, is_read, delivered_at, read_at, created_at) VALUES (?, ?, 'text', ?, ?, ?, 0, NULL, NULL, NOW())");
            $stmt->bind_param("ssiii", $sessionId, $message, $senderType, $adminId, $autoReply);
        } else {
            $stmt = $this->mysqli->prepare("INSERT INTO chat_messages (session_id, message, message_type, sender_type, auto_reply, is_read, delivered_at, read_at, created_at) VALUES (?, ?, 'text', ?, ?, 0, NULL, NULL, NOW())");
            $stmt->bind_param("sssi", $sessionId, $message, $senderType, $autoReply);
        }
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Insert a file message.
     */
    public function insertFileMessage(string $sessionId, string $message, string $messageType, string $fileUrl, string $fileName, int $fileSize, string $fileType, string $senderType, ?int $adminId = null): int
    {
        if ($adminId !== null) {
            $stmt = $this->mysqli->prepare("INSERT INTO chat_messages (session_id, message, message_type, file_url, file_name, file_size, file_type, sender_type, admin_id, is_read, delivered_at, read_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, NOW())");
            $stmt->bind_param("sssssisii", $sessionId, $message, $messageType, $fileUrl, $fileName, $fileSize, $fileType, $senderType, $adminId);
        } else {
            $stmt = $this->mysqli->prepare("INSERT INTO chat_messages (session_id, message, message_type, file_url, file_name, file_size, file_type, sender_type, is_read, delivered_at, read_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, NOW())");
            $stmt->bind_param("sssssisi", $sessionId, $message, $messageType, $fileUrl, $fileName, $fileSize, $fileType, $senderType);
        }
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Get messages for a session with cursor/offset pagination.
     */
    public function getMessages(string $sessionId, ?string $after = null, int $offset = 0, int $limit = 50, int $afterId = 0): array
    {
        // Keep the conversation direction reliable even for legacy rows that were
        // created before sender_type was set consistently. An admin id or an
        // auto-reply flag is authoritative and must never render as visitor.
        $sql = "SELECT id, session_id, message, message_type, file_url, file_name, file_size, file_type,
                       CASE WHEN sender_type = 'admin' OR admin_id IS NOT NULL OR auto_reply = 1
                            THEN 'admin' ELSE 'visitor' END AS sender_type,
                       admin_id, is_read, delivered_at, read_at, auto_reply, created_at
                FROM chat_messages WHERE session_id = ?";
        $params = [$sessionId];
        $types = 's';

        if ($afterId > 0 && $after) {
            $sql .= " AND (created_at > ? OR (created_at = ? AND id > ?))";
            $params[] = $after;
            $params[] = $after;
            $params[] = $afterId;
            $types .= 'ssi';
        } elseif ($after) {
            $sql .= " AND created_at > ?";
            $params[] = $after;
            $types .= 's';
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt->close();

        return array_reverse($messages);
    }

    /**
     * Count unread admin messages for a session.
     */
    public function countUnreadAdminMessages(string $sessionId): int
    {
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as count FROM chat_messages WHERE session_id = ? AND (sender_type = 'admin' OR admin_id IS NOT NULL OR auto_reply = 1) AND is_read = 0");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['count'] ?? 0);
    }

    /**
     * Mark admin messages as read (visitor side).
     */
    public function markAdminMessagesRead(string $sessionId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_messages SET delivered_at = COALESCE(delivered_at, NOW()), read_at = NOW(), is_read = 1 WHERE session_id = ? AND (sender_type = 'admin' OR admin_id IS NOT NULL OR auto_reply = 1) AND is_read = 0");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Mark visitor messages as read (admin side).
     */
    public function markVisitorMessagesRead(string $sessionId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_messages SET delivered_at = COALESCE(delivered_at, NOW()), read_at = NOW(), is_read = 1 WHERE session_id = ? AND sender_type = 'visitor' AND admin_id IS NULL AND auto_reply = 0 AND is_read = 0");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /** Mark messages delivered when the receiving client fetches them. */
    public function markMessagesDelivered(string $sessionId, string $senderType): void
    {
        $senderType = $senderType === 'admin' ? 'admin' : 'visitor';
        $stmt = $this->mysqli->prepare("UPDATE chat_messages SET delivered_at = COALESCE(delivered_at, NOW()) WHERE session_id = ? AND sender_type = ? AND delivered_at IS NULL");
        $stmt->bind_param("ss", $sessionId, $senderType);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get admin conversation list with last message and unread counts.
     */
    public function getAdminConversations(int $offset, int $limit): array
    {
        $fetchLimit = $limit + 1;
        $sql = "
            SELECT
                cs.id, cs.session_id, cs.visitor_name, cs.visitor_union_name,
                cs.visitor_location, cs.visitor_device, cs.visitor_browser, cs.visitor_os,
                cs.status, cs.created_at, cs.updated_at,
                cm.message as last_message, cm.message_type as last_message_type,
                cm.created_at as last_message_time,
                COALESCE(cmv.unread_count, 0) as unread
            FROM chat_sessions cs
            LEFT JOIN (
                SELECT cm2.id, cm2.session_id, cm2.message, cm2.message_type, cm2.created_at
                FROM chat_messages cm2
                WHERE cm2.id = (SELECT MAX(cm3.id) FROM chat_messages cm3 WHERE cm3.session_id = cm2.session_id)
            ) cm ON cm.session_id = cs.session_id
            LEFT JOIN (
                SELECT cmv2.session_id, COUNT(*) as unread_count
                FROM chat_messages cmv2
                WHERE cmv2.sender_type = 'visitor'
                  AND cmv2.admin_id IS NULL
                  AND cmv2.auto_reply = 0
                  AND cmv2.is_read = 0
                GROUP BY cmv2.session_id
            ) cmv ON cmv.session_id = cs.session_id
            ORDER BY
                CASE WHEN cs.status = 'active' THEN 0 ELSE 1 END,
                COALESCE(cm.created_at, cs.created_at) DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("ii", $fetchLimit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $conversations = [];
        while ($row = $result->fetch_assoc()) {
            $conversations[] = $row;
        }
        $stmt->close();

        $hasMore = count($conversations) > $limit;
        if ($hasMore) {
            array_pop($conversations);
        }

        return ['data' => $conversations, 'has_more' => $hasMore];
    }

    // ================================================================
    // CHAT SETTINGS
    // ================================================================

    /**
     * Get all chat settings (system_settings with 'chat_' prefix).
     */
    public function getChatSettings(): array
    {
        $stmt = $this->mysqli->prepare("SELECT setting_name, setting_value FROM system_settings WHERE setting_name LIKE 'chat_%'");
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_name']] = $row['setting_value'];
        }
        $stmt->close();

        $defaults = [
            'chat_enabled' => '1',
            'chat_title' => 'লাইভ চ্যাট সহায়তা',
            'chat_subtitle' => 'স্মার্ট ইউনিয়ন পরিষদ',
            'chat_welcome_message' => 'আপনার প্রশ্ন লিখুন, আমরা সহায়তা করব।',
            'chat_welcome_title' => 'সাহায্য প্রয়োজন?',
            'chat_agent_name' => 'সহায়ক',
            'chat_primary_color' => '#008B8B',
            'chat_offline_enabled' => '0',
            'chat_offline_start' => '17:00',
            'chat_offline_end' => '09:00',
            'chat_offline_message' => 'আমরা বর্তমানে অফলাইনে আছি। আপনার বার্তা ছেড়ে দিন, আমরা পরে উত্তর দেব।',
            'chat_offline_form_title' => 'আমরা অফলাইনে আছি',
            'chat_offline_form_subtitle' => 'নিচের ফর্মটি পূরণ করুন, আমরা পরে উত্তর দেব।',
            'chat_offline_success_message' => 'আপনার বার্তা পাঠানো হয়েছে। আমরা অফিস সময়ে আপনার সাথে যোগাযোগ করব।',
            'chat_placeholder' => 'বার্তা লিখুন...',
            'chat_name_placeholder' => 'আপনার নাম (ঐচ্ছিক)',
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($settings[$key])) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /**
     * Save a single chat setting (upsert).
     */
    public function saveChatSetting(string $key, string $value): void
    {
        $stmt = $this->mysqli->prepare("
            INSERT INTO system_settings (setting_name, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
        ");
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Begin transaction.
     */
    public function beginTransaction(): void
    {
        $this->mysqli->begin_transaction();
    }

    /**
     * Commit transaction.
     */
    public function commit(): void
    {
        $this->mysqli->commit();
    }

    /**
     * Rollback transaction.
     */
    public function rollback(): void
    {
        $this->mysqli->rollback();
    }

    // ================================================================
    // CANNED RESPONSES
    // ================================================================

    /**
     * Get all canned responses.
     */
    public function getAllCannedResponses(): array
    {
        $result = $this->mysqli->query("SELECT id, title, message, category, sort_order, created_at FROM chat_canned_responses ORDER BY category ASC, sort_order ASC, id ASC");
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        return $items;
    }

    /**
     * Get all canned response titles and messages (for auto-reply bot).
     */
    public function getCannedResponseTitles(): array
    {
        $result = $this->mysqli->query("SELECT id, title, message FROM chat_canned_responses ORDER BY sort_order ASC, id ASC");
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        return $items;
    }

    /** Public-safe FAQ payload for the visitor widget. */
    public function getPublicFaqs(int $limit = 8): array
    {
        $limit = max(1, min($limit, 20));
        $stmt = $this->mysqli->prepare("SELECT title, message, category FROM chat_canned_responses WHERE title <> '' AND message <> '' ORDER BY sort_order ASC, id ASC LIMIT ?");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'title' => $row['title'],
                'message' => $row['message'],
                'category' => $row['category'],
            ];
        }
        $stmt->close();
        return $items;
    }

    /**
     * Count canned responses.
     */
    public function countCannedResponses(): int
    {
        $result = $this->mysqli->query("SELECT COUNT(*) as cnt FROM chat_canned_responses");
        $row = $result->fetch_assoc();
        $result->free();
        return (int)$row['cnt'];
    }

    /**
     * Insert a new canned response.
     */
    public function insertCannedResponse(string $title, string $message, string $category, int $sortOrder): int
    {
        $stmt = $this->mysqli->prepare("INSERT INTO chat_canned_responses (title, message, category, sort_order) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $title, $message, $category, $sortOrder);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Update an existing canned response. Returns true if a row was updated.
     */
    public function updateCannedResponse(int $id, string $title, string $message, string $category, int $sortOrder): bool
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_canned_responses SET title = ?, message = ?, category = ?, sort_order = ? WHERE id = ?");
        $stmt->bind_param("sssii", $title, $message, $category, $sortOrder, $id);
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        return $affected;
    }

    /**
     * Delete a canned response. Returns true if a row was deleted.
     */
    public function deleteCannedResponse(int $id): bool
    {
        $stmt = $this->mysqli->prepare("DELETE FROM chat_canned_responses WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        return $affected;
    }

    // ================================================================
    // RATE LIMITING
    // ================================================================

    /**
     * Get current rate limit count for an IP+endpoint+window.
     */
    public function getRateLimitCount(string $ipHash, string $endpoint, string $window): ?int
    {
        $stmt = $this->mysqli->prepare("SELECT count FROM chat_rate_limits WHERE ip_hash = ? AND endpoint = ? AND window = ?");
        $stmt->bind_param("sss", $ipHash, $endpoint, $window);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['count'] : null;
    }

    /**
     * Increment rate limit count.
     */
    public function incrementRateLimit(string $ipHash, string $endpoint, string $window, int $newCount): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_rate_limits SET count = ? WHERE ip_hash = ? AND endpoint = ? AND window = ?");
        $stmt->bind_param("isss", $newCount, $ipHash, $endpoint, $window);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Create a new rate limit entry.
     */
    public function insertRateLimit(string $ipHash, string $endpoint, string $window): void
    {
        $stmt = $this->mysqli->prepare("INSERT INTO chat_rate_limits (ip_hash, endpoint, window, count) VALUES (?, ?, ?, 1)");
        $stmt->bind_param("sss", $ipHash, $endpoint, $window);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Clean old rate limit entries.
     */
    public function cleanRateLimits(): void
    {
        $this->mysqli->query("DELETE FROM chat_rate_limits WHERE created_at < NOW() - INTERVAL 1 HOUR");
    }

    // ================================================================
    // OFFLINE MESSAGES
    // ================================================================

    /**
     * Save an offline inquiry message.
     */
    public function insertOfflineMessage(string $name, ?string $phone, ?string $email, string $message): int
    {
        $stmt = $this->mysqli->prepare("INSERT INTO chat_offline_messages (visitor_name, visitor_phone, visitor_email, message, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->bind_param("ssss", $name, $phone, $email, $message);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Get offline messages with pagination.
     */
    public function getOfflineMessages(int $offset, int $limit): array
    {
        $fetchLimit = $limit + 1;
        $stmt = $this->mysqli->prepare("SELECT id, visitor_name, visitor_phone, visitor_email, message, is_read, created_at FROM chat_offline_messages ORDER BY is_read ASC, created_at DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $fetchLimit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt->close();

        $hasMore = count($messages) > $limit;
        if ($hasMore) {
            array_pop($messages);
        }

        return ['data' => $messages, 'has_more' => $hasMore];
    }

    /**
     * Count offline messages (optionally unread only).
     */
    public function countOfflineMessages(bool $unreadOnly = false): int
    {
        if ($unreadOnly) {
            $result = $this->mysqli->query("SELECT COUNT(*) as cnt FROM chat_offline_messages WHERE is_read = 0");
        } else {
            $result = $this->mysqli->query("SELECT COUNT(*) as cnt FROM chat_offline_messages");
        }
        $row = $result->fetch_assoc();
        $result->free();
        return (int)$row['cnt'];
    }

    /**
     * Mark an offline message as read.
     */
    public function markOfflineRead(int $id): void
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_offline_messages SET is_read = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete an offline message.
     */
    public function deleteOfflineMessage(int $id): void
    {
        $stmt = $this->mysqli->prepare("DELETE FROM chat_offline_messages WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    // ================================================================
    // BULK ACTIONS
    // ================================================================

    /**
     * Count total unread visitor messages across all sessions.
     */
    public function countAllUnreadVisitorMessages(): int
    {
        $result = $this->mysqli->query("SELECT COUNT(*) as cnt FROM chat_messages WHERE sender_type = 'visitor' AND admin_id IS NULL AND auto_reply = 0 AND is_read = 0");
        if (!$result) return 0;
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Get the latest unread visitor message with visitor name.
     * Used by admin notification system.
     */
    public function getLatestUnreadVisitorMessage(): ?array
    {
        $result = $this->mysqli->query("
            SELECT cm.message, cm.message_type, cm.session_id, cm.created_at, cs.visitor_name
            FROM chat_messages cm
            LEFT JOIN chat_sessions cs ON cm.session_id = cs.session_id
            WHERE cm.sender_type = 'visitor'
              AND cm.admin_id IS NULL
              AND cm.auto_reply = 0
              AND cm.is_read = 0
            ORDER BY cm.created_at DESC
            LIMIT 1
        ");
        if (!$result) return null;
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: null;
    }

    /**
     * Mark all unread visitor messages as read across all sessions.
     */
    public function markAllVisitorMessagesRead(): int
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_messages SET is_read = 1 WHERE sender_type = 'visitor' AND admin_id IS NULL AND auto_reply = 0 AND is_read = 0");
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }

    /**
     * Close all active sessions.
     */
    public function closeAllActiveSessions(): int
    {
        $stmt = $this->mysqli->prepare("UPDATE chat_sessions SET status = 'closed', updated_at = NOW() WHERE status = 'active'");
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }

    // ================================================================
    // CLEANUP
    // ================================================================

    /**
     * Delete old messages from closed sessions.
     */
    public function deleteOldClosedSessionMessages(string $cutoff): int
    {
        $stmt = $this->mysqli->prepare("DELETE cm FROM chat_messages cm INNER JOIN chat_sessions cs ON cm.session_id = cs.session_id WHERE cs.status = 'closed' AND cs.updated_at < ?");
        $stmt->bind_param("s", $cutoff);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }

    /**
     * Delete old closed sessions.
     */
    public function deleteOldClosedSessions(string $cutoff): int
    {
        $stmt = $this->mysqli->prepare("DELETE FROM chat_sessions WHERE status = 'closed' AND updated_at < ?");
        $stmt->bind_param("s", $cutoff);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }

    /**
     * Count old closed sessions.
     */
    public function countOldClosedSessions(string $cutoff): int
    {
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as cnt FROM chat_sessions WHERE status = 'closed' AND updated_at < ?");
        $stmt->bind_param("s", $cutoff);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Check if a file URL is referenced in any message.
     */
    public function isFileReferenced(string $fileName): bool
    {
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as cnt FROM chat_messages WHERE file_url LIKE ?");
        $like = '%' . $fileName;
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['cnt'] ?? 0) > 0;
    }
}
