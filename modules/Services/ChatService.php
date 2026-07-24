<?php
/**
 * modules/Services/ChatService.php
 *
 * Service layer for the Custom Live Chat Support System.
 * Contains all helper functions previously defined in ChatController.php.
 * Provides a clean, reusable API for chat operations.
 */

class ChatService
{
    private const RATE_LIMIT_SALT_PREFIX = 'lgdhaka_chat_';
    private const SESSION_SECRET_PREFIX = 'chat_sesh_';

    // Visitor session inactivity timeout: 5 minutes (in seconds)
    public const SESSION_TIMEOUT = 300;

    private ChatModel $chatModel;
    private string $rateLimitSalt;
    private string $sessionSecret;

    public function __construct(ChatModel $chatModel)
    {
        $this->chatModel = $chatModel;
        $configuredSecret = $_ENV['CHAT_SESSION_SECRET'] ?? getenv('CHAT_SESSION_SECRET') ?: '';
        if (!is_string($configuredSecret) || strlen($configuredSecret) < 32) {
            error_log('[Chat] CHAT_SESSION_SECRET is missing or too short; using a temporary deployment fallback. Configure a 32+ character secret before production.');
            $configuredSecret = hash('sha256', __FILE__ . '|' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }
        $this->rateLimitSalt = self::RATE_LIMIT_SALT_PREFIX . hash('sha256', $configuredSecret);
        $this->sessionSecret = self::SESSION_SECRET_PREFIX . $configuredSecret;
    }

    // ================================================================
    // DATABASE MIGRATION
    // ================================================================

    /**
     * Auto-migration: create chat tables if they don't exist.
     */
    public function autoMigrate(): void
    {
        $this->chatModel->createTables();
    }

    /**
     * Run incremental column migration (add new columns to existing tables).
     */
    public function incrementalMigrate(): void
    {
        $this->chatModel->addMissingColumns();
    }

    /**
     * Seed default canned responses if table is empty (first run only).
     */
    public function seedCannedResponses(): void
    {
        $this->chatModel->seedCannedResponses();
    }

    // ================================================================
    // SESSION MANAGEMENT
    // ================================================================

    /**
     * Get or create a chat session. New sessions get an HMAC signature automatically.
     */
    public function getOrCreateSession(string $sessionId, ?string $visitorName = null): array
    {
        $session = $this->chatModel->getSession($sessionId);

        if ($session) {
            if ($visitorName && empty($session['visitor_name'])) {
                $this->chatModel->updateVisitorName($sessionId, $visitorName);
                $session['visitor_name'] = $visitorName;
            }
            return $session;
        }

        $visitorName = $visitorName ?? '';
        $insertId = $this->chatModel->createSession($sessionId, $visitorName);

        // Two browser requests can initialize the same session concurrently.
        // Reuse the winner instead of returning an unusable session response.
        if ($insertId <= 0) {
            $existing = $this->chatModel->getSession($sessionId);
            if ($existing !== null) {
                return $existing;
            }
            throw new \RuntimeException('Unable to create chat session');
        }

        $sig = $this->signSession($sessionId);
        $this->chatModel->setSessionSig($insertId, $sig);

        return [
            'id' => $insertId,
            'session_id' => $sessionId,
            'session_sig' => $sig,
            'visitor_name' => $visitorName,
            'status' => 'active',
        ];
    }

    // ================================================================
    // JSON RESPONSE
    // ================================================================

    /**
     * Send a JSON response and exit.
     */
    public static function jsonResponse($data, int $statusCode = 200, ?string $cacheControl = null): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        if ($cacheControl !== null) {
            header('Cache-Control: ' . $cacheControl);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ================================================================
    // MESSAGES QUERY
    // ================================================================

    /**
     * Get messages for a session with optional cursor-based pagination.
     */
    public function getMessagesQuery(string $sessionId, ?string $after = null, int $offset = 0, int $limit = 50, int $afterId = 0): array
    {
        return $this->chatModel->getMessages($sessionId, $after, $offset, $limit, $afterId);
    }

    // ================================================================
    // RATE LIMITING
    // ================================================================

    /**
     * Simple IP-based rate limiter.
     * Returns array with 'allowed' boolean and optional 'retry_after' seconds.
     */
    public function checkRateLimit(string $endpoint, int $maxRequests = 30, int $windowSeconds = 60): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $windowSeconds = max(1, $windowSeconds);
        $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
        $window = (string)$windowStart;

        $ipHash = md5($ip . $this->rateLimitSalt);
        $currentCount = $this->chatModel->getRateLimitCount($ipHash, $endpoint, $window);

        if ($currentCount !== null) {
            $newCount = $currentCount + 1;
            if ($newCount > $maxRequests) {
                return ['allowed' => false, 'retry_after' => max(1, $windowSeconds - (time() - $windowStart))];
            }
            $this->chatModel->incrementRateLimit($ipHash, $endpoint, $window, $newCount);
        } else {
            $this->chatModel->insertRateLimit($ipHash, $endpoint, $window);
        }

        // Periodic cleanup
        if ($currentCount === null && mt_rand(1, 20) === 1) {
            $this->chatModel->cleanRateLimits();
        }

        return ['allowed' => true];
    }

    // ================================================================
    // HMAC SESSION SIGNING
    // ================================================================

    /**
     * Sign a session ID with HMAC-SHA256.
     */
    public function signSession(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, $this->sessionSecret);
    }

    /**
     * Check if a visitor session has timed out due to inactivity.
     */
    public function isSessionTimedOut(string $sessionId): bool
    {
        $lastVisitorActivity = $this->chatModel->getLastVisitorActivityAt($sessionId);
        if ($lastVisitorActivity === null) return false;
        return (time() - strtotime($lastVisitorActivity)) >= self::SESSION_TIMEOUT;
    }

    public function getLastVisitorActivityAt(string $sessionId): ?string
    {
        return $this->chatModel->getLastVisitorActivityAt($sessionId);
    }

    /**
     * Verify a session signature against the stored signature in DB.
     * For backward compatibility: if session has no stored signature, it is treated as valid.
     */
    public function verifySessionSig(string $sessionId, string $providedSig): bool
    {
        if (empty($sessionId)) return false;

        $storedSig = $this->chatModel->getSessionSig($sessionId);

        // Session doesn't exist in database
        if ($storedSig === null) return false;

        // Legacy session — no signature stored, allow it without a provided signature
        if (empty($storedSig)) return true;

        // Session has a stored signature — provided signature is required
        if (empty($providedSig)) return false;

        // Constant-time comparison
        return hash_equals($storedSig, $providedSig);
    }

    // ================================================================
    // XSS SANITIZATION
    // ================================================================

    /**
     * Sanitize chat message — strips all HTML tags for XSS protection.
     * Uses HTMLPurifier if available, falls back to strip_tags().
     */
    public static function sanitizeMessage(string $text): string
    {
        if (empty($text)) return '';
        $text = trim($text);

        if (class_exists('HTMLPurifier_Config') && class_exists('HTMLPurifier')) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', '');
            $config->set('Cache.SerializerPath', null);
            $config->set('Core.Encoding', 'UTF-8');
            $purifier = new HTMLPurifier($config);
            $clean = $purifier->purify($text);
            return html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
        }

        return html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    }

    // ================================================================
    // AI AUTO-REPLY BOT
    // ================================================================

    /**
     * Match visitor message against canned responses using Bengali keyword matching.
     * Returns array with 'matched' boolean and optional 'message' and 'canned_id'.
     */
    public function autoReply(string $sessionId, string $visitorMessage): array
    {
        // Prevent bot loops: only reply if last auto-reply was > 30s ago
        $lastAutoReplyAt = $this->chatModel->getLastAutoReplyAt($sessionId);
        if ($lastAutoReplyAt !== null) {
            $lastReply = strtotime($lastAutoReplyAt);
            if (time() - $lastReply < 30) {
                return ['matched' => false];
            }
        }

        // Load all canned responses (cached per request via static local var)
        static $cannedCache = null;
        if ($cannedCache === null) {
            $cannedCache = $this->chatModel->getCannedResponseTitles();
        }

        if (empty($cannedCache)) {
            return ['matched' => false];
        }

        $messageLower = mb_strtolower($visitorMessage);

        // Build keyword definitions from canned response titles
        $keywordDefs = [];
        foreach ($cannedCache as $canned) {
            $title = mb_strtolower(trim($canned['title']));
            $words = preg_split('/[\\s,，、]+/u', $title);
            $keywords = [];
            foreach ($words as $w) {
                $w = trim($w);
                if (mb_strlen($w) >= 2) {
                    $keywords[] = $w;
                }
            }
            $keywordDefs[] = [
                'id' => $canned['id'],
                'message' => $canned['message'],
                'keywords' => $keywords,
            ];
        }

        // Extra keyword mappings for common visitor phrases
        $extraKeywords = [
            ['keywords' => ['হাই', 'হ্যালো', 'হ্যাল', 'hello', 'hi', 'কেমন', 'স্বাগত', 'সুপ্রভাত', 'শুভ', 'নমস্কার'], 'title_match' => 'অভ্যর্থনা'],
            ['keywords' => ['ধন্যবাদ', 'thanks', 'thank', 'থ্যাঙ্কস', 'জাজাকাল্লাহ'], 'title_match' => 'ধন্যবাদ'],
            ['keywords' => ['অপেক্ষা', 'wait', 'দয়া'], 'title_match' => 'অপেক্ষার'],
            ['keywords' => ['সময়', 'অফিস', 'খোলা', 'বন্ধ', 'শনি', 'রবি', 'ছুটি'], 'title_match' => 'অফিস সময়'],
            ['keywords' => ['ফোন', 'মোবাইল', 'ইমেইল', 'যোগাযোগ', 'ঠিকানা', 'নাম্বার'], 'title_match' => 'যোগাযোগ'],
        ];

        $bestScore = 0;
        $bestMatch = null;

        // Score from title keywords
        foreach ($keywordDefs as $def) {
            $score = 0;
            foreach ($def['keywords'] as $keyword) {
                if (mb_strpos($messageLower, $keyword) !== false) {
                    $score += mb_strlen($keyword);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $def;
            }
        }

        // Score from extra keywords
        foreach ($extraKeywords as $ek) {
            $score = 0;
            foreach ($ek['keywords'] as $keyword) {
                if (mb_strpos($messageLower, $keyword) !== false) {
                    $score += mb_strlen($keyword);
                }
            }
            if ($score > $bestScore) {
                foreach ($cannedCache as $c) {
                    if (mb_strpos(mb_strtolower($c['title']), $ek['title_match']) !== false) {
                        $bestScore = $score;
                        $bestMatch = ['id' => $c['id'], 'message' => $c['message'], 'keywords' => []];
                        break;
                    }
                }
            }
        }

        // Threshold: at least 4 characters total match
        if ($bestMatch && $bestScore >= 4) {
            $this->chatModel->setLastAutoReplyAt($sessionId);

            return [
                'matched' => true,
                'message' => $bestMatch['message'],
                'canned_id' => $bestMatch['id'],
            ];
        }

        return ['matched' => false];
    }

    // ================================================================
    // OFFLINE INQUIRY FORM
    // ================================================================

    /**
     * Save an offline inquiry message to the database.
     */
    public function saveOfflineMessage(string $name, string $message, ?string $phone = null, ?string $email = null): int
    {
        $name = trim($name);
        $message = trim($message);
        $phone = $phone ? trim($phone) : null;
        $email = $email ? trim($email) : null;
        return $this->chatModel->insertOfflineMessage($name, $phone, $email, $message);
    }

    // ================================================================
    // OFFLINE MESSAGES ADMIN
    // ================================================================

    /**
     * Get offline messages with pagination.
     */
    public function getOfflineMessages(int $offset, int $limit): array
    {
        return $this->chatModel->getOfflineMessages($offset, $limit);
    }

    /**
     * Count offline messages.
     */
    public function countOfflineMessages(bool $unreadOnly = false): int
    {
        return $this->chatModel->countOfflineMessages($unreadOnly);
    }

    /**
     * Mark an offline message as read.
     */
    public function markOfflineRead(int $id): void
    {
        $this->chatModel->markOfflineRead($id);
    }

    /**
     * Delete an offline message.
     */
    public function deleteOfflineMessage(int $id): void
    {
        $this->chatModel->deleteOfflineMessage($id);
    }

    public static function handleFileUpload(array $file): array
    {
        $uploadDir = __DIR__ . '/../../public/uploads/chat/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowedTypes = [
            // All image/ MIME types (detected dynamically below)
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain', 'text/csv',
            'application/zip', 'application/x-rar-compressed',
        ];

        $maxSize = 10 * 1024 * 1024; // 10MB

        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!isset($file['size'], $file['tmp_name'], $file['name']) || $uploadError !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'message' => 'Upload failed with error code: ' . $uploadError];
        }

        if ($file['size'] > $maxSize) {
            return ['status' => 'error', 'message' => 'File too large. Maximum 10MB.'];
        }

        if (!is_uploaded_file($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return ['status' => 'error', 'message' => 'Invalid upload.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!$mimeType) {
            return ['status' => 'error', 'message' => 'Could not determine file type.'];
        }

        // Allow all image/* types except SVG (XSS risk with embedded scripts)
        $isImage = strpos($mimeType, 'image/') === 0;
        if ($isImage && in_array($mimeType, ['image/svg+xml', 'image/svg'], true)) {
            return ['status' => 'error', 'message' => 'SVG files are not allowed for security reasons.'];
        }

        if (!$isImage && !in_array($mimeType, $allowedTypes)) {
            return ['status' => 'error', 'message' => 'File type not allowed.'];
        }

        $extensionMap = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'application/pdf' => 'pdf', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt', 'text/csv' => 'csv', 'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
        ];
        $ext = $extensionMap[$mimeType] ?? 'bin';
        $safeName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $filePath = $uploadDir . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['status' => 'error', 'message' => 'Failed to save file.'];
        }

        // $isImage already computed above
        return [
            'status' => 'success',
            'data' => [
                'file_url' => '/uploads/chat/' . $safeName,
                'file_name' => $file['name'],
                'file_size' => $file['size'],
                'file_type' => $mimeType,
                'message_type' => $isImage ? 'image' : 'file',
            ],
        ];
    }


    // ================================================================
    // ADMIN ONLINE STATUS
    // ================================================================

    /**
     * Check if any admin is currently online.
     * Delegates to ChatModel which uses three signals:
     * 1. Admin typing within last 5 minutes
     * 2. Admin messages within last 5 minutes
     * 3. Admin users with last_login within the last 30 minutes
     */
    public function checkAdminOnline(): bool
    {
        return $this->chatModel->isAdminOnline();
    }

    /**
     * Build coarse visitor metadata without collecting GPS or exposing raw IP.
     * Geo headers are used only when supplied by a trusted reverse proxy.
     */
    public static function getVisitorMetadata(): array
    {
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $location = trim((string)(
            $_SERVER['HTTP_X_GEO_COUNTRY'] ??
            $_SERVER['HTTP_CF_IPCOUNTRY'] ??
            $_SERVER['GEOIP_COUNTRY_CODE'] ??
            ''
        ));
        $location = $location !== '' ? strtoupper(substr($location, 0, 2)) : 'অজানা';

        if (preg_match('/ipad|tablet|playbook|silk|kindle/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/mobile|android|iphone|ipod|windows phone/i', $ua)) {
            $device = 'mobile';
        } else {
            $device = 'desktop';
        }

        $browser = 'অজানা';
        if (preg_match('/Edg\/([\d.]+)/i', $ua, $m)) $browser = 'Edge ' . $m[1];
        elseif (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) $browser = 'Opera ' . $m[1];
        elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) $browser = 'Chrome ' . $m[1];
        elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) $browser = 'Firefox ' . $m[1];
        elseif (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) $browser = 'Safari ' . $m[1];

        $os = 'অজানা';
        if (preg_match('/Windows NT/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Android/i', $ua)) $os = 'Android';
        elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) $os = 'iOS';
        elseif (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';

        return [
            'location' => $location,
            'device' => $device,
            'browser' => $browser,
            'os' => $os,
            // Keep only a bounded summary; never store IP or GPS.
            'user_agent' => substr($ua, 0, 255),
        ];
    }
}
