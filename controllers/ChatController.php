<?php
/**
 * controllers/ChatController.php
 * 
 * Custom Live Chat Support System
 * Handles visitor chat and admin chat management.
 * 
 * Uses ChatService (modules/Services/ChatService.php) for all helper logic.
 */

// Ensure PHP uses UTF-8 internally
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

global $router, $twig, $mysqli;

$chatModel = new ChatModel($mysqli);
$authService = new AuthService($mysqli);
$chatService = new ChatService($chatModel);
$pushService = new PushService($chatModel);

// ================================================================
// AUTO DATABASE MIGRATION — creates tables if they don't exist
// ================================================================
$chatService->autoMigrate();
$chatService->incrementalMigrate();
$chatService->seedCannedResponses();


/**
 * POST /api/chat/send
 * Send a message as a visitor
 */
$router->post('/api/chat/send', function () use ($chatService, $chatModel, $pushService) {
    $rateCheck = $chatService->checkRateLimit( 'send');
    if (!$rateCheck['allowed']) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে। দয়া করে ' . $rateCheck['retry_after'] . ' সেকেন্ড পর আবার চেষ্টা করুন।', 'retry_after' => $rateCheck['retry_after']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';
    $message = ChatService::sanitizeMessage($input['message'] ?? '');
    $visitorName = trim($input['visitor_name'] ?? '');
    $visitorUnionName = trim($input['visitor_union_name'] ?? '');
    $unionId = (int)($input['union_id'] ?? 0);

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session ID'], 400);
    }
    if (empty($message)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Message is required'], 400);
    }
    if ($unionId <= 0 || !$chatModel->getUnion($unionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Valid union selection is required'], 400);
    }
    if (mb_strlen($message) > 500) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Message too long (max 500 characters)'], 400);
    }

    $preExisting = $chatModel->sessionExists($sessionId);
    if (!$preExisting && $visitorName === '') {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Visitor name is required to start a new conversation'], 400);
    }
    if (mb_strlen($visitorName) > 100 || mb_strlen($visitorUnionName) > 150) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Visitor information is too long'], 400);
    }

    // Existing sessions must prove ownership. New sessions are signed on creation.
    if ($preExisting && !$chatService->verifySessionSig($sessionId, (string)($input['session_sig'] ?? ''))) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    // Get or create session (new sessions get HMAC signature automatically)
    $session = $chatService->getOrCreateSession( $sessionId, $visitorName, $unionId);
    if (!empty($session['union_id']) && (int)$session['union_id'] !== $unionId) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Union cannot be changed for an existing conversation'], 409);
    }
    $sessionSig = $session['session_sig'] ?? '';
    $chatModel->updateVisitorMetadata($sessionId, ChatService::getVisitorMetadata());

    // If session was created by an older version without a signature, sign it now
    if (empty($sessionSig)) {
        $sessionSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $sessionSig);
    }

    // Do not append new messages to an expired/closed session. The client will
    // reset its local session and can retry against a fresh conversation.
    if ($session !== null && (($session['status'] ?? 'active') !== 'active' || $chatService->isSessionTimedOut($sessionId))) {
        $chatModel->expireSession($sessionId);
        ChatService::jsonResponse([
            'status' => 'error',
            'message' => 'Session expired',
            'session_expired' => true,
        ], 410);
    }

    // Update union name if provided
    if (!empty($visitorUnionName)) {
        $chatModel->updateUnionName($sessionId, $visitorUnionName);
    }
    $chatModel->setSessionUnion($sessionId, $unionId, $visitorUnionName);

    $messageId = $chatModel->insertMessage($sessionId, $message, 'visitor');

    $chatModel->touchSession($sessionId);

    // Attempt auto-reply from bot
    $autoReply = $chatService->autoReply( $sessionId, $message);
    $autoReplyData = null;
    if ($autoReply['matched']) {
        $autoReplyId = $chatModel->insertMessage($sessionId, $autoReply['message'], 'admin', null, 1);
        $chatModel->touchSession($sessionId);

        $autoReplyData = [
            'id' => $autoReplyId,
            'message' => $autoReply['message'],
            'sender_type' => 'admin',
            'auto_reply' => 1,
        ];
    }

    $response = [
        'status' => 'success',
        'message' => 'Message sent',
        'data' => [
            'id' => $messageId,
            'session_id' => $sessionId,
            'session_sig' => $sessionSig,
            'sender_type' => 'visitor',
        ]
    ];

    if ($autoReplyData) {
        $response['auto_reply'] = $autoReplyData;
    }

    // Send FCM notification to admins about new visitor message
    $visitorDisplayName = $visitorName ?: 'দর্শক';
    $pushService->sendToAdmins(
        'নতুন চ্যাট বার্তা',
        $visitorDisplayName . ': ' . mb_substr($message, 0, 100),
        ['session_id' => $sessionId, 'url' => '/chat/admin?session=' . $sessionId]
    );
    $pushService->sendToUnion($unionId, 'নতুন ইউনিয়ন চ্যাট মেসেজ', ['session_id' => $sessionId, 'url' => '/chat/admin?session_id=' . $sessionId]);
    $chatModel->logNotification($messageId, $sessionId, null, 'push', 'queued');

    // Send email notification to admins when no admin is online
    $chatService->sendOfflineEmailNotification($visitorDisplayName, $message, $sessionId);
    $chatModel->logNotification($messageId, $sessionId, null, 'email', 'queued');

    ChatService::jsonResponse($response);
});

/**
 * POST /api/chat/upload
 * Upload a file as a visitor
 */
$router->post('/api/chat/upload', function () use ($chatService, $chatModel, $pushService) {
    $rateCheck = $chatService->checkRateLimit( 'upload', 10, 60);
    if (!$rateCheck['allowed']) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে। দয়া পরে চেষ্টা করুন।', 'retry_after' => $rateCheck['retry_after']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sessionId = $_POST['session_id'] ?? '';
    $providedSig = $_POST['session_sig'] ?? '';
    $visitorName = trim($_POST['visitor_name'] ?? '');
    $visitorUnionName = trim($_POST['visitor_union_name'] ?? '');
    $unionId = (int)($_POST['union_id'] ?? 0);

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if ($unionId <= 0 || !$chatModel->getUnion($unionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Valid union selection is required'], 400);
    }

    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'No file uploaded'], 400);
    }
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session ID'], 400);
    }

    $preExisting = $chatModel->sessionExists($sessionId);

    if (!$preExisting && $visitorName === '') {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Visitor name is required to start a new conversation'], 400);
    }
    if (mb_strlen($visitorName) > 100 || mb_strlen($visitorUnionName) > 150) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Visitor information is too long'], 400);
    }

    // Create or get session (new sessions get an HMAC signature automatically)
    $session = $chatService->getOrCreateSession( $sessionId, $visitorName, $unionId);
    if (!empty($session['union_id']) && (int)$session['union_id'] !== $unionId) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Union cannot be changed for an existing conversation'], 409);
    }

    // Existing sessions must prove ownership; never rotate a signature on bad input.
    if ($preExisting && !$chatService->verifySessionSig( $sessionId, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $chatModel->updateVisitorMetadata($sessionId, ChatService::getVisitorMetadata());

    if (($session['status'] ?? 'active') !== 'active' || $chatService->isSessionTimedOut($sessionId)) {
        $chatModel->expireSession($sessionId);
        ChatService::jsonResponse([
            'status' => 'error',
            'message' => 'Session expired',
            'session_expired' => true,
        ], 410);
    }

    // Ensure sig exists for the response
    $sessionSig = $session['session_sig'] ?? '';
    if (empty($sessionSig)) {
        $sessionSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $sessionSig);
    }

    $uploadResult = ChatService::handleFileUpload($_FILES['file']);
    if ($uploadResult['status'] !== 'success') {
        ChatService::jsonResponse($uploadResult, 400);
    }

    // Update union name if provided
    if (!empty($visitorUnionName)) {
        $chatModel->updateUnionName($sessionId, $visitorUnionName);
    }
    $chatModel->setSessionUnion($sessionId, $unionId, $visitorUnionName);
    $fileData = $uploadResult['data'];
    $messageText = '[ফাইল] ' . ChatService::sanitizeMessage($fileData['file_name']);

    $messageId = $chatModel->insertFileMessage(
        $sessionId, $messageText, $fileData['message_type'],
        $fileData['file_url'], $fileData['file_name'], $fileData['file_size'],
        $fileData['file_type'], 'visitor'
    );

    $chatModel->touchSession($sessionId);

    // Send FCM notification to admins about visitor file upload
    $visitorDisplayName = $visitorName ?: 'দর্শক';
    $pushService->sendToAdmins(
        'নতুন চ্যাট ফাইল',
        $visitorDisplayName . ' একটি ফাইল পাঠিয়েছে',
        ['session_id' => $sessionId, 'url' => '/chat/admin?session=' . $sessionId]
    );
    $pushService->sendToUnion($unionId, 'নতুন ইউনিয়ন চ্যাট ফাইল', ['session_id' => $sessionId, 'url' => '/chat/admin?session_id=' . $sessionId]);
    $chatModel->logNotification($messageId, $sessionId, null, 'push', 'queued');

    // Send email notification to admins when no admin is online
    $chatService->sendOfflineEmailNotification($visitorDisplayName, $messageText, $sessionId);
    $chatModel->logNotification($messageId, $sessionId, null, 'email', 'queued');

    ChatService::jsonResponse([
        'status' => 'success',
        'message' => 'File uploaded',
        'data' => [
            'id' => $messageId,
            'session_id' => $sessionId,
            'session_sig' => $sessionSig,
            'sender_type' => 'visitor',
            'file_url' => $fileData['file_url'],
            'file_name' => $fileData['file_name'],
        ]
    ]);
});

/**
 * GET /api/chat/messages?session_id=xxx&after=timestamp&offset=0&limit=50
 * Get messages for a session (polling + history)
 */
$router->get('/api/chat/messages', function () use ($chatService, $chatModel) {
    // Generous limits: the widget polls messages + typing + unread in parallel
    // and many visitors share one proxy IP (X-Forwarded-For is used by the limiter).
    $rateCheck = $chatService->checkRateLimit( 'messages', 180, 60);
    if (!$rateCheck['allowed']) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।', 'retry_after' => $rateCheck['retry_after']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sessionId = $_GET['session_id'] ?? '';
    $providedSig = $_GET['session_sig'] ?? '';
    $after = $_GET['after'] ?? '';
    $afterId = max(0, (int)($_GET['after_id'] ?? 0));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = max(1, min((int)($_GET['limit'] ?? 50), 100));
    // Background notification probes request mark_read=0 so that checking for
    // a human reply does not wipe the visitor's unread badge while the widget
    // is closed. Active rendering (loadHistory / polling) defaults to true.
    $markRead = ($_GET['mark_read'] ?? '1') !== '0';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success', 'data' => [], 'has_more' => false]);
    }

    // Check if session has timed out due to inactivity
    if ($chatService->isSessionTimedOut($sessionId)) {
        $chatModel->expireSession($sessionId);
        ChatService::jsonResponse(['status' => 'success', 'data' => [], 'has_more' => false, 'session_expired' => true]);
    }

    // Read-only endpoints still require the session signature.
    // Auto-recover: if the session has a stored signature but the client
    // didn't provide one (stale localStorage, cleared state), re-sign and
    // return the signature so the client can recover on the next request.
    $storedSig = $chatModel->getSessionSig($sessionId);
    if ($storedSig === null) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    } elseif (!empty($storedSig) && empty($providedSig)) {
        $recoveredSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $recoveredSig);
        ChatService::jsonResponse([
            'status' => 'success',
            'data' => [],
            'has_more' => false,
            'session_sig' => $recoveredSig,
            'server_time' => gmdate('c'),
        ], 200, 'no-cache, private');
    } elseif (!empty($storedSig) && !hash_equals($storedSig, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }
    // else: legacy session with no stored sig — allow

    // A fetch means messages reached the receiving client. Mark admin
    // messages as delivered AND read, since the visitor is actively
    // rendering the conversation via polling. Background probes opt out.
    if ($markRead) {
        $chatModel->markMessagesDelivered($sessionId, 'admin');
        $chatModel->markAdminMessagesRead($sessionId);
    }
    $messages = $chatService->getMessagesQuery($sessionId, $after ?: null, $offset, $limit + 1, $afterId);
    $hasMore = count($messages) > $limit;
    if ($hasMore) {
        array_pop($messages);
    }

    $session = $chatModel->getSession($sessionId);
    ChatService::jsonResponse([
        'status' => 'success',
        'data' => $messages,
        'has_more' => $hasMore,
        'session_sig' => $providedSig,
        'server_time' => gmdate('c'),
        'last_visitor_activity_at' => $chatService->getLastVisitorActivityAt($sessionId),
        'timeout_seconds' => ChatService::SESSION_TIMEOUT,
        'session' => $session ? [
            'status' => $session['status'],
            'created_at' => $session['created_at'],
            'updated_at' => $session['updated_at'],
        ] : null,
    ], 200, 'no-cache, private');
});

/**
 * GET /api/chat/unread?session_id=xxx
 */
$router->get('/api/chat/unread', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'unread', 120, 60);
    if (!$rateCheck['allowed']) {
        http_response_code(429);
        echo '0';
        exit;
    }
    $sessionId = $_GET['session_id'] ?? '';
    $providedSig = $_GET['session_sig'] ?? '';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success', 'data' => ['count' => 0]]);
    }

    // Read-only endpoints still require the session signature.
    // Auto-recover missing signature for legacy/stale sessions.
    $storedSig = $chatModel->getSessionSig($sessionId);
    if ($storedSig === null) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    } elseif (!empty($storedSig) && empty($providedSig)) {
        $recoveredSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $recoveredSig);
        ChatService::jsonResponse(['status' => 'success', 'data' => ['count' => 0], 'session_sig' => $recoveredSig], 200, 'no-cache, private');
    } elseif (!empty($storedSig) && !hash_equals($storedSig, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $count = $chatModel->countUnreadAdminMessages($sessionId);

    ChatService::jsonResponse(['status' => 'success', 'data' => ['count' => $count], 'session_sig' => $providedSig], 200, 'no-cache, private');
});

/**
 * GET /api/chat/unread/count?session_id=xxx
 * Lightweight endpoint: returns just the raw count number (no JSON wrapper)
 */
$router->get('/api/chat/unread/count', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'unread_count', 120, 60);
    if (!$rateCheck['allowed']) {
        http_response_code(429);
        echo '0';
        exit;
    }
    $sessionId = $_GET['session_id'] ?? '';
    $providedSig = $_GET['session_sig'] ?? '';

    if (empty($sessionId)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo '0';
        exit;
    }

    if (!$chatModel->sessionExists($sessionId)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo '0';
        exit;
    }

    // Read-only endpoints still require the session signature.
    // Auto-recover missing signature for legacy/stale sessions.
    $storedSig = $chatModel->getSessionSig($sessionId);
    if ($storedSig === null) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    } elseif (!empty($storedSig) && empty($providedSig)) {
        $recoveredSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $recoveredSig);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache, private');
        header('X-Chat-Session-Sig: ' . $recoveredSig);
        echo '0';
        exit;
    } elseif (!empty($storedSig) && !hash_equals($storedSig, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $count = $chatModel->countUnreadAdminMessages($sessionId);

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, private');
    header('X-Chat-Session-Sig: ' . $providedSig);
    echo $count;
    exit;
});

/**
 * POST /api/chat/read
 * Mark messages as read
 */
$router->post('/api/chat/read', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'read', 60, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';
    $providedSig = $input['session_sig'] ?? '';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success', 'message' => 'No messages to mark as read']);
    }

    // Typing updates still require the session signature.
    // Auto-recover missing signature for legacy/stale sessions.
    $storedSig = $chatModel->getSessionSig($sessionId);
    if ($storedSig === null) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    } elseif (!empty($storedSig) && empty($providedSig)) {
        $recoveredSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $recoveredSig);
        $chatModel->markAdminMessagesRead($sessionId);
        ChatService::jsonResponse(['status' => 'success', 'message' => 'Messages marked as read', 'session_sig' => $recoveredSig]);
    } elseif (!empty($storedSig) && !hash_equals($storedSig, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $chatModel->markAdminMessagesRead($sessionId);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'Messages marked as read', 'session_sig' => $providedSig]);
});

/**
 * GET /api/chat/faq
 * Public-safe FAQ entries for the visitor offline/help view.
 */
$router->get('/api/chat/faq', function () use ($chatModel) {
    ChatService::jsonResponse([
        'status' => 'success',
        'data' => $chatModel->getPublicFaqs(8),
    ], 200, 'public, max-age=300');
});

// ================================================================
// WEB PUSH (PUSH API) ENDPOINTS
// ================================================================

/**
 * GET /api/chat/push/vapid-key
 * Public VAPID public key used by the visitor widget to subscribe.
 * Never cache: if the admin rotates keys, stale clients must pick the new
 * key immediately instead of subscribing with a dead key.
 */
$router->get('/api/chat/push/vapid-key', function () use ($chatService, $pushService) {
    $rateCheck = $chatService->checkRateLimit('push_vapid', 60, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }

    $enabled = $pushService->isEnabled();
    $configured = $enabled && $pushService->isConfigured();

    ChatService::jsonResponse([
        'status' => 'success',
        'data' => [
            'public_key' => $configured ? $pushService->getVapidPublicKey() : '',
            'enabled' => $enabled,
            'configured' => $configured,
        ],
    ], 200, 'no-cache');
});

/**
 * POST /api/chat/push/subscribe
 * Visitor stores a browser push subscription for their session.
 */
$router->post('/api/chat/push/subscribe', function () use ($chatService, $chatModel, $pushService) {
    $rateCheck = $chatService->checkRateLimit('push_subscribe', 10, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';
    $providedSig = $input['session_sig'] ?? '';
    $subscription = is_array($input['subscription'] ?? null) ? $input['subscription'] : [];

    if (empty($sessionId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success', 'message' => 'No active session']);
    }
    if (!$chatService->verifySessionSig($sessionId, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $endpoint = $subscription['endpoint'] ?? '';
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $p256dh = $keys['p256dh'] ?? '';
    $auth = $keys['auth'] ?? '';

    // Legacy VAPID subscription — store endpoint as FCM token for backward compatibility
    $saved = $pushService->subscribeFcm($sessionId, (string)$endpoint, $providedSig, 'visitor');
    if (!$saved) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid subscription'], 400);
    }
    ChatService::jsonResponse(['status' => 'success', 'message' => 'Subscribed']);
});

/**
 * POST /api/chat/push/unsubscribe
 * Visitor removes their push subscription.
 */
$router->post('/api/chat/push/unsubscribe', function () use ($chatService, $chatModel, $pushService) {
    $rateCheck = $chatService->checkRateLimit('push_unsubscribe', 10, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';
    $providedSig = $input['session_sig'] ?? '';
    $endpoint = trim((string)($input['endpoint'] ?? ''));

    if (empty($sessionId) || empty($endpoint)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID and endpoint are required'], 400);
    }
    if ($chatModel->sessionExists($sessionId) && !$chatService->verifySessionSig($sessionId, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    // Legacy VAPID unsubscribe — remove by endpoint/token
    $pushService->unsubscribeFcm($endpoint);
    ChatService::jsonResponse(['status' => 'success', 'message' => 'Unsubscribed']);
});

/**
 * POST /api/chat/push/fcm-subscribe
 * Visitor stores their FCM device token for a session.
 */
$router->post('/api/chat/push/fcm-subscribe', function () use ($chatService, $chatModel, $pushService) {
    $rateCheck = $chatService->checkRateLimit('push_fcm_subscribe', 10, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';
    $providedSig = $input['session_sig'] ?? '';
    $fcmToken = trim((string)($input['fcm_token'] ?? ''));

    if (empty($sessionId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if (empty($fcmToken) || strlen($fcmToken) < 10) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'FCM token is required'], 400);
    }
    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success', 'message' => 'No active session']);
    }
    if (!$chatService->verifySessionSig($sessionId, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $saved = $pushService->subscribeFcm($sessionId, $fcmToken, $providedSig, 'visitor');
    if (!$saved) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid FCM token'], 400);
    }
    ChatService::jsonResponse(['status' => 'success', 'message' => 'FCM subscribed']);
});

/**
 * POST /api/chat/push/fcm-unsubscribe
 * Visitor removes their FCM token.
 */
$router->post('/api/chat/push/fcm-unsubscribe', function () use ($chatService, $chatModel, $pushService) {
    $rateCheck = $chatService->checkRateLimit('push_fcm_unsubscribe', 10, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';
    $providedSig = $input['session_sig'] ?? '';
    $fcmToken = trim((string)($input['fcm_token'] ?? ''));

    if (empty($sessionId) || empty($fcmToken)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID and FCM token are required'], 400);
    }
    if ($chatModel->sessionExists($sessionId) && !$chatService->verifySessionSig($sessionId, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $pushService->unsubscribeFcm($fcmToken);
    ChatService::jsonResponse(['status' => 'success', 'message' => 'FCM unsubscribed']);
});

/**
 * POST /api/chat/push/fcm-admin-subscribe
 * Admin registers their FCM token for receiving visitor message notifications.
 */
$router->post('/api/chat/push/fcm-admin-subscribe', function () use ($authService, $pushService, $chatModel) {
    $authService->requireLogin();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $fcmToken = trim((string)($input['fcm_token'] ?? ''));
    $deviceInfo = json_encode($input['device_info'] ?? [
        'browser' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown browser',
        'platform' => $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? 'Unknown platform',
    ], JSON_UNESCAPED_UNICODE);
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if (empty($fcmToken) || strlen($fcmToken) < 10) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'FCM token is required'], 400);
    }

    // Use a stable session ID for admin token management
    $adminSessionId = 'admin_' . $adminId;
    $chatModel->saveDeviceToken($adminSessionId, $fcmToken, $deviceInfo, $adminId);
    ChatService::jsonResponse(['status' => 'success', 'message' => 'Admin FCM subscribed']);
});

$router->get('/api/chat/unions', function () use ($chatModel) {
    ChatService::jsonResponse(['status' => 'success', 'data' => $chatModel->getPublicUnions()], 200, 'public, max-age=300');
});

$router->get('/api/chat/devices', function () use ($authService, $chatModel) {
    $authService->requireLogin();
    $userId = (int)$authService->getCurrentUserId();
    ChatService::jsonResponse(['status' => 'success', 'data' => $chatModel->getUserDevices($userId)]);
});

$router->post('/api/chat/devices/revoke', function () use ($authService, $chatModel) {
    $authService->requireLogin(); $input=json_decode(file_get_contents('php://input'),true)??[];
    $deviceId=(int)($input['device_id']??0); if($deviceId<=0) ChatService::jsonResponse(['status'=>'error','message'=>'Device ID is required'],400);
    $chatModel->revokeDevice((int)$authService->getCurrentUserId(),$deviceId); ChatService::jsonResponse(['status'=>'success']);
});

$router->post('/api/chat/devices/revoke-all', function () use ($authService, $chatModel) {
    $authService->requireLogin(); $chatModel->revokeAllDevices((int)$authService->getCurrentUserId()); ChatService::jsonResponse(['status'=>'success']);
});

/**
 * POST /api/chat/settings/vapid
 * Generate VAPID keys (admin only).
 * The private key is never sent back to the browser — only the public key
 * and a boolean so the admin knows the key pair is set.
 */
$router->post('/api/chat/settings/vapid', function () use ($chatModel, $authService, $pushService) {
    $authService->ensureCan('manage_settings');

    // Only admin/superadmin can manage VAPID settings
    $currentUser = $authService->getUserData(false);
    if (!isset($currentUser['role_id']) || $currentUser['role_id'] > 2) {
        $userId = $currentUser['user_id'] ?? 0;
        $roleId = $currentUser['role_id'] ?? 0;
        error_log('[Security] Push settings access denied: user_id=' . $userId . ' role_id=' . $roleId . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' endpoint=/api/chat/settings/vapid');
        ChatService::jsonResponse(['status' => 'error', 'message' => 'শুধুমাত্র অ্যাডমিনিস্ট্রেটর এই সেটিংস পরিবর্তন করতে পারবেন।'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // FCM mode: return current push configuration status
    ChatService::jsonResponse([
        'status' => 'success',
        'data' => [
            'public_key' => $pushService->getVapidPublicKey(),
            'private_key_set' => $pushService->isConfigured(),
            'subject' => 'Firebase Cloud Messaging',
            'mode' => 'fcm',
        ],
    ]);
});

// ================================================================
// ADMIN API ENDPOINTS
// ================================================================

/**
 * GET /api/chat/admin/conversations
 */
$router->get('/api/chat/admin/conversations', function () use ($chatService, $chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = max(1, min((int)($_GET['limit'] ?? 50), 100));
    $scope = $chatModel->getUserScope((int)$authService->getCurrentUserId());
    $unionId = ($scope && (int)$scope['role_id'] !== 1) ? (int)$scope['union_id'] : null;
    $result = $chatModel->getAdminConversations($offset, $limit, $unionId);

    ChatService::jsonResponse(['status' => 'success', 'data' => $result['data'], 'has_more' => $result['has_more']], 200, 'no-cache, private');
});

/**
 * GET /api/chat/admin/conversations/{session_id}
 */
$router->get('/api/chat/admin/conversations/{session_id}', function ($sessionId) use ($chatService, $chatModel, $authService) {
    $authService->ensureCan('manage_chat');
    if (!$chatModel->canUserAccessSession((int)$authService->getCurrentUserId(), $sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Conversation access denied'], 403);
    }

    $chatModel->markMessagesDelivered($sessionId, 'visitor');
    // Opening the admin conversation is the admin's seen/read action.
    $chatModel->markVisitorMessagesRead($sessionId);
    $messages = $chatService->getMessagesQuery( $sessionId, null, 0, 200);

    ChatService::jsonResponse(['status' => 'success', 'data' => $messages, 'session' => $chatModel->getSession($sessionId)], 200, 'no-cache, private');
});

/**
 * POST /api/chat/admin/reply
 */
$router->post('/api/chat/admin/reply', function () use ($chatService, $mysqli, $authService, $chatModel, $pushService) {
    $authService->ensureCan('manage_chat');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';
    $message = ChatService::sanitizeMessage($input['message'] ?? '');

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if (empty($message)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Message is required'], 400);
    }
    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Conversation not found'], 404);
    }
    if (!$chatModel->canUserAccessSession((int)$authService->getCurrentUserId(), $sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Conversation access denied'], 403);
    }
    if (mb_strlen($message) > 1000) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Message too long (max 1000 characters)'], 400);
    }

    // Check if session is closed or timed out
    $session = $chatModel->getSession($sessionId);
    if ($session !== null && (($session['status'] ?? 'active') !== 'active' || $chatService->isSessionTimedOut($sessionId))) {
        $chatModel->expireSession($sessionId);
        ChatService::jsonResponse([
            'status' => 'error',
            'message' => 'এই কথোপকথনটি বন্ধ বা মেয়াদোত্তীর্ণ। দয়া করে দর্শককে একটি নতুন চ্যাট শুরু করতে বলুন।',
        ], 410);
    }

    $adminId = $authService->getCurrentUserId();

    $messageId = $chatModel->insertMessage($sessionId, $message, 'admin', $adminId);
    $chatModel->touchSession($sessionId);

    // Send a Web Push notification to the visitor's subscribed browser(s).
    $pushService->sendToSession($sessionId, 'লাইভ চ্যাট উত্তর', $message);

    ChatService::jsonResponse([
        'status' => 'success', 
        'message' => 'Reply sent',
        'data' => ['id' => $messageId]
    ]);
});

/**
 * POST /api/chat/admin/upload
 * Admin uploads a file
 */
$router->post('/api/chat/admin/upload', function () use ($chatService, $authService, $chatModel, $pushService) {
    $authService->ensureCan('manage_chat');

    $sessionId = $_POST['session_id'] ?? '';
    $message = trim($_POST['message'] ?? '');

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'No file uploaded'], 400);
    }
    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Conversation not found'], 404);
    }
    if (!$chatModel->canUserAccessSession((int)$authService->getCurrentUserId(), $sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Conversation access denied'], 403);
    }

    // A visitor-inactive session cannot receive a late admin reply. Expire it
    // and remove the old conversation before returning a fresh-session signal.
    $session = $chatModel->getSession($sessionId);
    if ($session !== null && (($session['status'] ?? 'active') !== 'active' || $chatService->isSessionTimedOut($sessionId))) {
        $chatModel->expireSession($sessionId);
        ChatService::jsonResponse([
            'status' => 'error',
            'message' => 'Conversation expired',
            'session_expired' => true,
        ], 410);
    }

    $uploadResult = ChatService::handleFileUpload($_FILES['file']);
    if ($uploadResult['status'] !== 'success') {
        ChatService::jsonResponse($uploadResult, 400);
    }

    $adminId = $authService->getCurrentUserId();
    $fileData = $uploadResult['data'];
    $messageText = $message ?: '[ফাইল] ' . ChatService::sanitizeMessage($fileData['file_name']);

    $messageId = $chatModel->insertFileMessage(
        $sessionId, $messageText, $fileData['message_type'],
        $fileData['file_url'], $fileData['file_name'], $fileData['file_size'],
        $fileData['file_type'], 'admin', $adminId
    );

    $chatModel->touchSession($sessionId);

    // Notify the visitor's subscribed browser(s) about the new attachment.
    $pushBody = $message !== '' ? $message : 'একটি ফাইল পাঠানো হয়েছে';
    $pushService->sendToSession($sessionId, 'লাইভ চ্যাট উত্তর', $pushBody);

    ChatService::jsonResponse([
        'status' => 'success', 
        'message' => 'File sent',
        'data' => ['id' => $messageId, 'file_url' => $fileData['file_url'], 'file_name' => $fileData['file_name']]
    ]);
});

/**
 * POST /api/chat/admin/close/{session_id}
 */
$router->post('/api/chat/admin/close/{session_id}', function ($sessionId) use ($chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $chatModel->closeSession($sessionId);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'Conversation closed']);
});

/**
 * POST /api/chat/admin/read-all
 * Mark all unread visitor messages as read across all conversations
 */
$router->post('/api/chat/admin/read-all', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $count = $chatModel->markAllVisitorMessagesRead();

    ChatService::jsonResponse(['status' => 'success', 'message' => 'সব বার্তা পঠিত হিসাবে চিহ্নিত করা হয়েছে', 'data' => ['marked_read' => $count]]);
});

/**
 * POST /api/chat/admin/close-all
 * Close all active conversations
 */
$router->post('/api/chat/admin/close-all', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $count = $chatModel->closeAllActiveSessions();

    ChatService::jsonResponse(['status' => 'success', 'message' => 'সব সক্রিয় কথোপকথন বন্ধ করা হয়েছে', 'data' => ['closed' => $count]]);
});

// ================================================================
// TYPING INDICATOR ENDPOINTS
// ================================================================

/**
 * POST /api/chat/typing
 * Visitor is typing notification
 */
$router->post('/api/chat/typing', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'typing_send', 60, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';
    $providedSig = $input['session_sig'] ?? '';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success']);
    }

    // Typing updates still require the session signature.
    // Auto-recover missing signature for legacy/stale sessions.
    $storedSig = $chatModel->getSessionSig($sessionId);
    if ($storedSig === null) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    } elseif (!empty($storedSig) && empty($providedSig)) {
        // Allow typing without recovery — not critical
    } elseif (!empty($storedSig) && !hash_equals($storedSig, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $chatModel->setVisitorTyping($sessionId);

    ChatService::jsonResponse(['status' => 'success']);
});

/**
 * GET /api/chat/typing?session_id=xxx
 * Check if admin is typing (for visitor widget)
 */
$router->get('/api/chat/typing', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'typing_check', 120, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $sessionId = $_GET['session_id'] ?? '';
    $providedSig = $_GET['session_sig'] ?? '';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success', 'data' => ['is_typing' => false]]);
    }

    // Read-only endpoint still requires the session signature.
    // Auto-recover missing signature for legacy/stale sessions.
    $storedSig = $chatModel->getSessionSig($sessionId);
    if ($storedSig === null) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    } elseif (!empty($storedSig) && empty($providedSig)) {
        $recoveredSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $recoveredSig);
        ChatService::jsonResponse(['status' => 'success', 'data' => ['is_typing' => false], 'session_sig' => $recoveredSig], 200, 'no-cache, private');
    } elseif (!empty($storedSig) && !hash_equals($storedSig, $providedSig)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid session signature'], 403);
    }

    $isTyping = $chatModel->isAdminTyping($sessionId);

    ChatService::jsonResponse(['status' => 'success', 'data' => ['is_typing' => $isTyping], 'session_sig' => $providedSig], 200, 'no-cache, private');
});

/**
 * POST /api/chat/admin/typing
 * Admin is typing notification
 */
$router->post('/api/chat/admin/typing', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = $input['session_id'] ?? '';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    $chatModel->setAdminTyping($sessionId);

    ChatService::jsonResponse(['status' => 'success']);
});

/**
 * GET /api/chat/admin/typing?session_id=xxx
 * Check if visitor is typing (for admin panel)
 */
$router->get('/api/chat/admin/typing', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $sessionId = $_GET['session_id'] ?? '';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    $isTyping = $chatModel->isVisitorTyping($sessionId);

    ChatService::jsonResponse(['status' => 'success', 'data' => ['is_typing' => $isTyping]], 200, 'no-cache, private');
});

/**
 * GET /api/chat/admin/unread/total
 * Get total count of unread visitor messages across all sessions.
 * Lightweight endpoint for global notification polling.
 */
$router->get('/api/chat/admin/unread/total', function () use ($chatModel, $authService, $chatService) {
    try {
        $authService->ensureCan('manage_chat');

    $scope = $chatModel->getUserScope((int)$authService->getCurrentUserId());
    $unionId = ($scope && (int)$scope['role_id'] !== 1) ? (int)$scope['union_id'] : null;
    // Live chat unread count, limited to the officer's union.
    $liveCount = $chatModel->countAllUnreadVisitorMessages($unionId);

    // Offline message unread count
    $offlineCount = $chatService->countOfflineMessages(true);

    // Combined total
    $total = $liveCount + $offlineCount;

    // Get latest unread live chat message info for the notification preview
    $latestMsg = $chatModel->getLatestUnreadVisitorMessage($unionId);

    // Get latest unread offline message info for the notification preview
    $latestOfflineMsg = $chatService->getLatestOfflineMessage();

    // Get admin notification preference settings
    $allSettings = $chatModel->getChatSettings();
    $adminNotifySettings = [
        'sound' => isset($allSettings['chat_admin_notify_sound']) ? $allSettings['chat_admin_notify_sound'] : '1',
        'desktop' => isset($allSettings['chat_admin_notify_desktop']) ? $allSettings['chat_admin_notify_desktop'] : '1',
        'toast' => isset($allSettings['chat_admin_notify_toast']) ? $allSettings['chat_admin_notify_toast'] : '1',
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, private');
    echo json_encode([
        'status' => 'success',
        'data' => [
            'total' => $total,
            'live_unread' => $liveCount,
            'offline_unread' => $offlineCount,
            'latest' => $latestMsg ? [
                'type' => 'live',
                'id' => (int)($latestMsg['id'] ?? 0),
                'visitor_name' => $latestMsg['visitor_name'] ?? 'অজ্ঞাত',
                'message' => $latestMsg['message'] ?? '',
                'message_type' => $latestMsg['message_type'] ?? 'text',
                'session_id' => $latestMsg['session_id'] ?? '',
                'created_at' => $latestMsg['created_at'] ?? '',
            ] : null,
            'offline_latest' => $latestOfflineMsg ? [
                'type' => 'offline',
                'visitor_name' => $latestOfflineMsg['visitor_name'] ?? 'অজ্ঞাত',
                'visitor_phone' => $latestOfflineMsg['visitor_phone'] ?? '',
                'visitor_email' => $latestOfflineMsg['visitor_email'] ?? '',
                'message' => $latestOfflineMsg['message'] ?? '',
                'id' => $latestOfflineMsg['id'] ?? 0,
                'created_at' => $latestOfflineMsg['created_at'] ?? '',
            ] : null,
            'notify_settings' => $adminNotifySettings,
        ]    ], JSON_UNESCAPED_UNICODE);
    exit;
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'সার্ভার ত্রুটি: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});


// ================================================================
// SETTINGS API
// ================================================================

/**
 * GET /api/chat/settings
 */
$router->get('/api/chat/settings', function () use ($chatModel) {
    $settings = $chatModel->getChatSettings();
    // Cache settings for 1 hour — they rarely change
    ChatService::jsonResponse(['status' => 'success', 'data' => $settings], 200, 'public, max-age=3600');
});

/**
 * POST /api/chat/settings/save
 */
$router->post('/api/chat/settings/save', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_settings');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $settings = $input['settings'] ?? [];

    if (empty($settings)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'No settings provided'], 400);
    }

    $allowedKeys = [
        'chat_enabled', 'chat_title', 'chat_subtitle',
        'chat_welcome_message', 'chat_welcome_title',
        'chat_agent_name', 'chat_primary_color',
        'chat_offline_enabled', 'chat_offline_start', 'chat_offline_end',
        'chat_offline_message', 'chat_offline_form_title', 'chat_offline_form_subtitle',
        'chat_offline_success_message', 'chat_placeholder', 'chat_name_placeholder',
        'chat_sound_enabled',
        'chat_admin_notify_sound',
        'chat_admin_notify_desktop',
        'chat_admin_notify_toast',
        'chat_typing_sound_enabled',
        'chat_visitor_push_enabled',
        'chat_push_vapid_public_key',
        'chat_push_vapid_private_key',
        'chat_push_subject'
    ];

    // Push notification settings are admin/superadmin only
    $currentUser = $authService->getUserData(false);
    $isPushAdmin = isset($currentUser['role_id']) && $currentUser['role_id'] <= 2;
    $pushOnlyKeys = ['chat_visitor_push_enabled', 'chat_push_vapid_public_key', 'chat_push_vapid_private_key', 'chat_push_subject'];
    if (!$isPushAdmin) {
        $userId = $currentUser['user_id'] ?? 0;
        $roleId = $currentUser['role_id'] ?? 0;
        $attemptedKeys = array_intersect_key($settings, array_flip($pushOnlyKeys));
        error_log('[Security] Push settings save blocked: user_id=' . $userId . ' role_id=' . $roleId . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' keys=' . implode(',', array_keys($attemptedKeys)));
        foreach ($pushOnlyKeys as $k) {
            unset($settings[$k]);
        }
    }

    // Chat enable/disable and offline mode are admin/superadmin only
    $adminOnlyKeys = ['chat_enabled', 'chat_offline_enabled', 'chat_offline_start', 'chat_offline_end', 'chat_offline_message'];
    if (!$isPushAdmin) {
        $attemptedKeys = array_intersect_key($settings, array_flip($adminOnlyKeys));
        if (!empty($attemptedKeys)) {
            $userId = $currentUser['user_id'] ?? 0;
            $roleId = $currentUser['role_id'] ?? 0;
            error_log('[Security] Critical chat settings blocked: user_id=' . $userId . ' role_id=' . $roleId . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' keys=' . implode(',', array_keys($attemptedKeys)));
        }
        foreach ($adminOnlyKeys as $k) {
            unset($settings[$k]);
        }
    }

    try {
        $chatModel->beginTransaction();

        // Master switches (chat_enabled / chat_offline_enabled) are only
        // updated when explicitly provided — a partial save (e.g. an API
        // caller changing a single field) must never silently ENABLE or
        // DISABLE the whole widget. The settings form always sends them
        // ('0' or '1'), so UI toggles still work exactly as before.
        //
        // Notification toggles default to ENABLED so that saving unrelated
        // settings can never silently disable admin/visitor notifications.
        if (!isset($settings['chat_sound_enabled'])) $settings['chat_sound_enabled'] = '0';
        if (!isset($settings['chat_admin_notify_sound'])) $settings['chat_admin_notify_sound'] = '1';
        if (!isset($settings['chat_admin_notify_desktop'])) $settings['chat_admin_notify_desktop'] = '1';
        if (!isset($settings['chat_admin_notify_toast'])) $settings['chat_admin_notify_toast'] = '1';
        if (!isset($settings['chat_typing_sound_enabled'])) $settings['chat_typing_sound_enabled'] = '0';
        if (!isset($settings['chat_visitor_push_enabled'])) $settings['chat_visitor_push_enabled'] = '1';

        foreach ($allowedKeys as $key) {
            if (!isset($settings[$key])) continue;
            $value = trim((string)$settings[$key]);
            if (in_array($key, ['chat_enabled', 'chat_offline_enabled', 'chat_sound_enabled', 'chat_admin_notify_sound', 'chat_admin_notify_desktop', 'chat_admin_notify_toast', 'chat_typing_sound_enabled', 'chat_visitor_push_enabled'], true)) {
                $value = $value === '1' ? '1' : '0';
            } elseif ($key === 'chat_primary_color') {
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) $value = '#008B8B';
            } elseif (in_array($key, ['chat_offline_start', 'chat_offline_end'], true)) {
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) $value = $key === 'chat_offline_start' ? '17:00' : '09:00';
            } elseif (in_array($key, ['chat_push_vapid_public_key', 'chat_push_vapid_private_key'], true)) {
                // Never wipe an existing key with a blank field, and only
                // accept plausible base64url key material.
                if ($value === '') continue;
                if (!preg_match('/^[A-Za-z0-9_\-]{20,}$/', $value)) continue;
                $value = sanitize_input($value);
            } elseif ($key === 'chat_push_subject') {
                $value = sanitize_input($value);
                if ($value !== '' && !preg_match('/^(mailto:|https?:\/\/)/i', $value)) continue;
            } else {
                $value = sanitize_input($value);
            }
            if (mb_strlen($value) > 500) $value = mb_substr($value, 0, 500);
            $chatModel->saveChatSetting($key, $value);
        }

        $chatModel->commit();
        ChatService::jsonResponse(['status' => 'success', 'message' => 'চ্যাট সেটিংস সংরক্ষণ করা হয়েছে']);
    } catch (\Exception $e) {
        $chatModel->rollback();
        ChatService::jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
});

// ================================================================
// SETTINGS PAGE
// ================================================================

/**
 * GET /chat/settings
 */
$router->get('/chat/settings', function () use ($chatModel, $twig, $authService) {
    $authService->ensureCan('manage_settings');

    $settings = $chatModel->getChatSettings();

    echo $twig->render('chat/settings.twig', [
        'title' => 'চ্যাট সেটিংস',
        'header_title' => '💬 চ্যাট উইজেট সেটিংস',
        'settings' => $settings,
        'devices' => $chatModel->getUserDevices((int)$authService->getCurrentUserId()),
    ]);
});

// ================================================================
// OFFLINE INQUIRY API
// ================================================================

/**
 * POST /api/chat/offline
 * Submit an offline inquiry form
 */
$router->post('/api/chat/offline', function () use ($chatService) {
    $rateCheck = $chatService->checkRateLimit('offline', 5, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে। দয়া করে ' . $rateCheck['retry_after'] . ' সেকেন্ড পর আবার চেষ্টা করুন।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($input['name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $message = trim($input['message'] ?? '');

    if (empty($name)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'নাম প্রয়োজন'], 400);
    }
    if (empty($message)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'বার্তা প্রয়োজন'], 400);
    }
    if (mb_strlen($name) > 100) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'নাম ১০০ অক্ষরের বেশি হতে পারবে না'], 400);
    }
    if (mb_strlen($message) > 1000) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'বার্তা ১০০০ অক্ষরের বেশি হতে পারবে না'], 400);
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'বৈধ ইমেইল ঠিকানা দিন'], 400);
    }

    // Sanitize inputs
    $name = ChatService::sanitizeMessage($name);
    $phone = ChatService::sanitizeMessage($phone);
    $email = ChatService::sanitizeMessage($email);
    $message = ChatService::sanitizeMessage($message);

    $insertId = $chatService->saveOfflineMessage($name, $message, $phone ?: null, $email ?: null);

    ChatService::jsonResponse([
        'status' => 'success',
        'message' => 'আপনার বার্তা পাঠানো হয়েছে। আমরা অফিস সময়ে আপনার সাথে যোগাযোগ করব।',
        'data' => ['id' => $insertId],
    ]);
});

// ================================================================
// CANNED RESPONSES API
// ================================================================

/**
 * GET /api/chat/admin/canned
 * List all canned responses grouped by category.
 * Reading quick replies must work for any chat admin (the page itself only
 * requires manage_chat); only create/update/delete need manage_settings.
 */
$router->get('/api/chat/admin/canned', function () use ($chatService, $chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $responses = $chatModel->getAllCannedResponses();

    ChatService::jsonResponse(['status' => 'success', 'data' => $responses], 200, 'no-cache, private');
});

/**
 * POST /api/chat/admin/canned
 * Create a new canned response
 */
$router->post('/api/chat/admin/canned', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_settings');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $title = trim($input['title'] ?? '');
    $message = trim($input['message'] ?? '');
    $category = trim($input['category'] ?? '');
    $sortOrder = (int)($input['sort_order'] ?? 0);

    if (empty($title)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'শিরোনাম প্রয়োজন'], 400);
    }
    if (empty($message)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'বার্তা প্রয়োজন'], 400);
    }
    if (mb_strlen($title) > 150) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'শিরোনাম ১৫০ অক্ষরের বেশি হতে পারবে না'], 400);
    }

    $insertId = $chatModel->insertCannedResponse($title, $message, $category, $sortOrder);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'কুইক রিপ্লাই যোগ করা হয়েছে', 'data' => ['id' => $insertId]]);
});

/**
 * PUT /api/chat/admin/canned/{id}
 * Update an existing canned response
 */
$router->put('/api/chat/admin/canned/{id}', function ($id) use ($chatModel, $authService) {
    $authService->ensureCan('manage_settings');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $title = trim($input['title'] ?? '');
    $message = trim($input['message'] ?? '');
    $category = trim($input['category'] ?? '');
    $sortOrder = (int)($input['sort_order'] ?? 0);

    if (empty($title)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'শিরোনাম প্রয়োজন'], 400);
    }
    if (empty($message)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'বার্তা প্রয়োজন'], 400);
    }

    $updated = $chatModel->updateCannedResponse($id, $title, $message, $category, $sortOrder);

    if (!$updated) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'কুইক রিপ্লাই পাওয়া যায়নি'], 404);
    }

    ChatService::jsonResponse(['status' => 'success', 'message' => 'কুইক রিপ্লাই আপডেট করা হয়েছে']);
});

/**
 * DELETE /api/chat/admin/canned/{id}
 * Delete a canned response
 */
$router->delete('/api/chat/admin/canned/{id}', function ($id) use ($chatModel, $authService) {
    $authService->ensureCan('manage_settings');

    $deleted = $chatModel->deleteCannedResponse($id);

    if (!$deleted) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'কুইক রিপ্লাই পাওয়া যায়নি'], 404);
    }

    ChatService::jsonResponse(['status' => 'success', 'message' => 'কুইক রিপ্লাই মুছে ফেলা হয়েছে']);
});

// ================================================================
// ADMIN PAGE
// ================================================================

/**
 * GET /chat/admin
 */
$router->get('/chat/admin', function () use ($chatService, $twig, $authService) {
    $authService->ensureCan('manage_chat');

    header('Content-Type: text/html; charset=utf-8');

    echo $twig->render('chat/admin.twig', [
        'title' => 'চ্যাট সহায়তা',
        'header_title' => '💬 লাইভ চ্যাট পরিচালনা',
    ]);
});

/**
 * GET /chat/settings/canned
 * Canned responses management page
 */
$router->get('/chat/settings/canned', function () use ($chatService, $twig, $authService) {
    $authService->ensureCan('manage_settings');

    header('Content-Type: text/html; charset=utf-8');

    echo $twig->render('chat/canned.twig', [
        'title' => 'কুইক রিপ্লাই ব্যবস্থাপনা',
        'header_title' => '⚡ কুইক রিপ্লাই ব্যবস্থাপনা',
    ]);
});

// ================================================================
// OFFLINE MESSAGES ADMIN API
// ================================================================

/**
 * GET /api/chat/admin/offline
 * List offline inquiry messages with pagination
 */
$router->get('/api/chat/admin/offline', function () use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = max(1, min((int)($_GET['limit'] ?? 50), 100));

    $result = $chatService->getOfflineMessages($offset, $limit);

    ChatService::jsonResponse(['status' => 'success', 'data' => $result['data'], 'has_more' => $result['has_more']], 200, 'no-cache, private');
});

/**
 * GET /api/chat/admin/offline/count
 * Count unread offline inquiry messages (returns raw integer)
 */
$router->get('/api/chat/admin/offline/count', function () use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $count = $chatService->countOfflineMessages(true);

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, private');
    echo $count;
    exit;
});

/**
 * POST /api/chat/admin/offline/read-all
 * Mark all offline messages as read
 * Placed BEFORE the {id} parameter routes to avoid greedy match.
 */
$router->post('/api/chat/admin/offline/read-all', function () use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $count = $chatService->markAllOfflineRead();

    ChatService::jsonResponse(['status' => 'success', 'message' => 'সব অফলাইন বার্তা পঠিত হিসাবে চিহ্নিত করা হয়েছে', 'data' => ['marked_read' => $count]]);
});

/**
 * POST /api/chat/admin/offline/unread-all
 * Mark all offline messages as unread (undo for mark-all-read)
 * Placed before {id} routes to avoid greedy match.
 */
$router->post('/api/chat/admin/offline/unread-all', function () use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $count = $chatService->markAllOfflineUnread();

    ChatService::jsonResponse(['status' => 'success', 'message' => 'সব অফলাইন বার্তা অপঠিত হিসাবে ফিরিয়ে আনা হয়েছে', 'data' => ['marked_unread' => $count]]);
});

/**
 * POST /api/chat/admin/offline/delete-all-read
 * Delete all read offline messages
 * Placed before {id} routes to avoid greedy match.
 */
$router->post('/api/chat/admin/offline/delete-all-read', function () use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $count = $chatService->deleteAllReadOfflineMessages();

    ChatService::jsonResponse(['status' => 'success', 'message' => 'সব পঠিত অফলাইন বার্তা মুছে ফেলা হয়েছে', 'data' => ['deleted' => $count]]);
});

/**
 * POST /api/chat/admin/offline/batch-read
 * Mark multiple offline messages as read by IDs
 */
$router->post('/api/chat/admin/offline/batch-read', function () use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = $input['ids'] ?? [];

    if (empty($ids) || !is_array($ids)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'বার্তা আইডি প্রয়োজন'], 400);
    }

    $ids = array_map('intval', $ids);
    $count = $chatService->batchMarkOfflineRead($ids);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'নির্বাচিত বার্তা পঠিত হিসাবে চিহ্নিত করা হয়েছে', 'data' => ['marked_read' => $count]]);
});

/**
 * POST /api/chat/admin/offline/batch-delete
 * Delete multiple offline messages by IDs
 */
$router->post('/api/chat/admin/offline/batch-delete', function () use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = $input['ids'] ?? [];

    if (empty($ids) || !is_array($ids)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'বার্তা আইডি প্রয়োজন'], 400);
    }

    $ids = array_map('intval', $ids);
    $count = $chatService->batchDeleteOfflineMessages($ids);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'নির্বাচিত বার্তা মুছে ফেলা হয়েছে', 'data' => ['deleted' => $count]]);
});

/**
 * POST /api/chat/admin/offline/{id}/read
 * Mark an offline message as read
 */
$router->post('/api/chat/admin/offline/{id}/read', function ($id) use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $id = (int)$id;
    if ($id <= 0) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid ID'], 400);
    }

    $chatService->markOfflineRead($id);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'Marked as read']);
});

/**
 * POST /api/chat/admin/offline/{id}/delete
 * Delete an offline message
 */
$router->post('/api/chat/admin/offline/{id}/delete', function ($id) use ($chatService, $authService) {
    $authService->ensureCan('manage_chat');

    $id = (int)$id;
    if ($id <= 0) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Invalid ID'], 400);
    }

    $chatService->deleteOfflineMessage($id);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'Message deleted']);
});

/**
 * GET /chat/admin/offline
 * Redirect to the merged /chat/admin page with offline tab active
 */
$router->get('/chat/admin/offline', function () {
    header('Location: /chat/admin?tab=offline', true, 301);
    exit;
});

// ================================================================
// ADMIN ONLINE STATUS API
// ================================================================

/**
 * GET /api/chat/admin/status
 * Check if any admin is currently online (for visitor widget)
 * Public endpoint — no auth required
 */
$router->get('/api/chat/admin/status', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit('admin_status', 120, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Rate limit exceeded'], 429);
    }

    $isOnline = $chatService->checkAdminOnline();

    ChatService::jsonResponse([
        'status' => 'success',
        'data' => [
            'online' => $isOnline,
        ]
    ], 200, 'no-cache, private');
});

// ================================================================
// AUTO-CLEANUP — Delete old closed sessions & orphaned uploads
// ================================================================

/**
 * Run cleanup for closed sessions older than 30 days
 * Call this via cron: GET /api/chat/cleanup (or via auto-trigger on admin page load)
 */
$router->get('/api/chat/cleanup', function () use ($chatService, $chatModel) {
    global $authService;
    // Only admins or local requests can trigger cleanup
    try {
        $authService->ensureCan('manage_chat');
    } catch (\Exception $e) {
        // Allow internal requests too
        $allowedIps = ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? ''];
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedIps)) {
            http_response_code(403);
            exit;
        }
    }

    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

    // Delete old messages from closed sessions
    $deletedMessages = $chatModel->deleteOldClosedSessionMessages($cutoff);

    // Delete old closed sessions
    $deletedSessions = $chatModel->deleteOldClosedSessions($cutoff);

    // Delete orphaned upload files (files in uploads dir not referenced in DB)
    $uploadDir = __DIR__ . '/../public/uploads/chat/';
    if (is_dir($uploadDir)) {
        $files = glob($uploadDir . '*');
        foreach ($files as $file) {
            if (basename($file) === '.htaccess') continue;
            $fileName = basename($file);
            if (!$chatModel->isFileReferenced($fileName)) {
                @unlink($file);
            }
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, private');
    echo json_encode([
        'status' => 'success',
        'message' => 'পরিষ্কার করা হয়েছে',
        'data' => [
            'deleted_messages' => $deletedMessages,
            'deleted_sessions' => $deletedSessions,
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Auto-trigger cleanup only on admin page load (5% chance to spread load)
// Only runs for admin-related routes, NOT on every visitor API request
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isAdminRoute = strpos($currentPath, '/chat/admin') === 0 || strpos($currentPath, '/chat/settings') === 0;
if ($isAdminRoute && mt_rand(1, 100) <= 5) {
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $count = $chatModel->countOldClosedSessions($cutoff);
    if ($count > 0) {
        $chatModel->deleteOldClosedSessionMessages($cutoff);
        $chatModel->deleteOldClosedSessions($cutoff);
    }
}

// ================================================================
// HEALTH CHECK & MONITORING
// ================================================================

/**
 * GET /api/chat/health
 * System health check — returns status of all subsystems.
 */
$router->get('/api/chat/health', function () use ($chatModel) {
    $monitorService = new MonitorService();
    $health = $monitorService->healthCheck();

    $statusCode = $health['status'] === 'healthy' ? 200 : 503;
    ChatService::jsonResponse($health, $statusCode, 'no-cache');
});

/**
 * GET /api/chat/metrics
 * Application metrics for monitoring dashboards (admin only).
 */
$router->get('/api/chat/metrics', function () use ($authService) {
    $authService->ensureCan('manage_chat');

    $monitorService = new MonitorService();
    $metrics = $monitorService->getMetrics();

    ChatService::jsonResponse(['status' => 'success', 'data' => $metrics], 200, 'no-cache, private');
});

// ================================================================
// FIREBASE CONFIG ENDPOINT (Secure — no hardcoded keys)
// ================================================================

/**
 * GET /api/chat/push/config
 * Returns Firebase client configuration from env/config.
 * Public endpoint — needed by visitor widget to initialize FCM.
 */
$router->get('/api/chat/push/config', function () use ($chatService) {
    $rateCheck = $chatService->checkRateLimit('push_config', 30, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Rate limit exceeded'], 429);
    }

    if (!function_exists('getFirebaseConfig')) {
        require_once __DIR__ . '/../config/firebase.php';
    }

    $config = getFirebaseConfig();

    ChatService::jsonResponse([
        'status' => 'success',
        'data' => [
            'enabled' => $config['enabled'],
            'config' => $config['web_config'],
            'vapid_key' => $config['vapid_key'] ?: (
                $chatModel->getChatSetting('chat_push_vapid_public_key') ?: ''
            ),
        ],
    ], 200, 'public, max-age=300');
});

// ================================================================
// NOTIFICATION TEST ENDPOINT
// ================================================================

/**
 * POST /api/chat/admin/push/test
 * Send a test push notification to the current admin.
 */
$router->post('/api/chat/admin/push/test', function () use ($chatService, $chatModel, $authService, $pushService) {
    $authService->ensureCan('manage_chat');

    $adminId = (int)$authService->getCurrentUserId();

    // Get the admin's FCM tokens
    $stmt = $chatModel->mysqli->prepare(
        "SELECT fcm_token FROM fcm_tokens WHERE user_type = 'admin' AND user_id = ? AND revoked_at IS NULL AND fcm_token != ''"
    );
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    $tokens = [];
    while ($row = $result->fetch_assoc()) {
        $tokens[] = $row['fcm_token'];
    }
    $stmt->close();

    if (empty($tokens)) {
        ChatService::jsonResponse([
            'status' => 'error',
            'message' => 'কোনো FCM টোকেন পাওয়া যায়নি। প্রথমে Push চালু করুন।',
        ], 400);
    }

    if (!function_exists('sendFcmMulticast')) {
        require_once __DIR__ . '/../config/fcm.php';
    }

    $result = sendFcmMulticast(
        $tokens,
        'টেস্ট নোটিফিকেশন ✅',
        'এটি একটি টেস্ট বার্তা। আপনার পুশ নোটিফিকেশন সঠিকভাবে কাজ করছে!',
        ['type' => 'test', 'url' => '/chat/admin']
    );

    // Log the test notification
    $chatModel->logNotification(null, 'test', $adminId, 'push', $result['success'] > 0 ? 'sent' : 'failed', json_encode($result));

    // Prune invalid tokens
    if (!empty($result['invalid_tokens'])) {
        $chatModel->deleteInvalidFcmTokens($result['invalid_tokens']);
    }

    if ($result['success'] > 0) {
        ChatService::jsonResponse(['status' => 'success', 'message' => 'টেস্ট নোটিফিকেশন পাঠানো হয়েছে ✅']);
    } else {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'টেস্ট নোটিফিকেশন পাঠাতে ব্যর্থ হয়েছে।'], 500);
    }
});

// ================================================================
// DEVICE MANAGEMENT (with confirmation support)
// ================================================================

/**
 * POST /api/chat/admin/devices/revoke-confirm
 * Revoke a device with confirmation token verification.
 */
$router->post('/api/chat/admin/devices/revoke-confirm', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceId = (int)($input['device_id'] ?? 0);
    $confirmationToken = $input['confirmation_token'] ?? '';

    if ($deviceId <= 0) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Device ID is required'], 400);
    }

    // Verify confirmation token (simple session-based check)
    if (empty($confirmationToken) || $confirmationToken !== ($_SESSION['device_revoke_token'] ?? '')) {
        // Generate a new token for the confirmation modal
        $token = bin2hex(random_bytes(16));
        $_SESSION['device_revoke_token'] = $token;
        ChatService::jsonResponse([
            'status' => 'pending_confirmation',
            'message' => 'ডিভাইস মুছে ফেলতে নিশ্চিত করুন',
            'confirmation_token' => $token,
        ]);
    }

    // Clear the confirmation token
    unset($_SESSION['device_revoke_token']);

    $userId = (int)$authService->getCurrentUserId();
    $chatModel->revokeDevice($userId, $deviceId);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'ডিভাইস মুছে ফেলা হয়েছে']);
});

// ================================================================
// NOTIFICATION LOG ENDPOINTS
// ================================================================

/**
 * GET /api/chat/admin/notifications/logs
 * Get notification delivery logs (admin only).
 */
$router->get('/api/chat/admin/notifications/logs', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = max(1, min((int)($_GET['limit'] ?? 50), 100));
    $channel = $_GET['channel'] ?? '';
    $status = $_GET['status'] ?? '';

    $sql = "SELECT id, message_id, session_id, recipient_user_id, recipient_email, channel, provider, status, provider_response, retry_count, created_at
            FROM chat_notification_log_v2 WHERE 1=1";
    $params = [];
    $types = '';

    if ($channel !== '' && in_array($channel, ['push', 'email', 'sms', 'in_app'])) {
        $sql .= ' AND channel = ?';
        $params[] = $channel;
        $types .= 's';
    }
    if ($status !== '' && in_array($status, ['queued', 'sent', 'delivered', 'failed', 'invalid_token', 'expired'])) {
        $sql .= ' AND status = ?';
        $params[] = $status;
        $types .= 's';
    }

    $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
    $params[] = $limit + 1;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $chatModel->mysqli->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();

    $hasMore = count($logs) > $limit;
    if ($hasMore) array_pop($logs);

    ChatService::jsonResponse([
        'status' => 'success',
        'data' => $logs,
        'has_more' => $hasMore,
    ], 200, 'no-cache, private');
});

// ================================================================
// EMAIL QUEUE ENDPOINTS (Admin only)
// ================================================================

/**
 * GET /api/chat/admin/email-queue/stats
 * Get email queue statistics.
 */
$router->get('/api/chat/admin/email-queue/stats', function () use ($authService) {
    $authService->ensureCan('manage_chat');

    $emailQueue = new EmailQueueService();
    $stats = $emailQueue->getStats();

    ChatService::jsonResponse(['status' => 'success', 'data' => $stats], 200, 'no-cache, private');
});

/**
 * POST /api/chat/admin/email-queue/process
 * Manually trigger email queue processing (admin only).
 */
$router->post('/api/chat/admin/email-queue/process', function () use ($authService) {
    $authService->ensureCan('manage_settings');

    $emailQueue = new EmailQueueService();
    $result = $emailQueue->processQueue(20);

    ChatService::jsonResponse([
        'status' => 'success',
        'message' => 'ইমেইল কিউ প্রসেস করা হয়েছে',
        'data' => $result,
    ]);
});

// ================================================================
// CONSISTENT JSON ERROR HANDLER
// ================================================================

/**
 * Register a global error handler for unhandled exceptions
 * to return consistent JSON responses for API routes.
 */
set_exception_handler(function (\Throwable $e) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isApi = strpos($requestUri, '/api/') === 0;

    error_log('[ChatController] Unhandled: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if ($isApi) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'সার্ভার ত্রুটি ঘটেছে। দয়া করে পরে আবার চেষ্টা করুন।',
            'error_id' => bin2hex(random_bytes(4)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Non-API: delegate to default handler
    if (function_exists('renderError')) {
        renderError(500, 'সার্ভার ত্রুটি');
    } else {
        http_response_code(500);
        echo '500 Internal Server Error';
    }
});
