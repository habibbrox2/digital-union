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
$router->post('/api/chat/send', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'send');
    if (!$rateCheck['allowed']) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে। দয়া করে ' . $rateCheck['retry_after'] . ' সেকেন্ড পর আবার চেষ্টা করুন।', 'retry_after' => $rateCheck['retry_after']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $sessionId = $input['session_id'] ?? '';
    $message = ChatService::sanitizeMessage($input['message'] ?? '');
    $visitorName = trim($input['visitor_name'] ?? '');
    $visitorUnionName = trim($input['visitor_union_name'] ?? '');

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if (empty($message)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Message is required'], 400);
    }
    if (mb_strlen($message) > 500) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Message too long (max 500 characters)'], 400);
    }

    // Get or create session (new sessions get HMAC signature automatically)
    $session = $chatService->getOrCreateSession( $sessionId, $visitorName);
    $sessionSig = $session['session_sig'] ?? '';

    // If session was created by an older version without a signature, sign it now
    if (empty($sessionSig)) {
        $sessionSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $sessionSig);
    }

    // Check if session has timed out due to inactivity
    $sessionExpired = false;
    if ($session !== null && $chatService->isSessionTimedOut($sessionId)) {
        $chatModel->closeSession($sessionId);
        $sessionExpired = true;
    }

    // Update union name if provided
    if (!empty($visitorUnionName)) {
        $chatModel->updateUnionName($sessionId, $visitorUnionName);
    }

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

    if ($sessionExpired) {
        $response['session_expired'] = true;
    }

    if ($autoReplyData) {
        $response['auto_reply'] = $autoReplyData;
    }

    ChatService::jsonResponse($response);
});

/**
 * POST /api/chat/upload
 * Upload a file as a visitor
 */
$router->post('/api/chat/upload', function () use ($chatService, $chatModel) {
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

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (empty($_FILES['file'])) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'No file uploaded'], 400);
    }

    $preExisting = $chatModel->sessionExists($sessionId);

    // Create or get session (new sessions get an HMAC signature automatically)
    $session = $chatService->getOrCreateSession( $sessionId, $visitorName);

    // Auto-recover missing sig for pre-existing sessions
    if ($preExisting && !$chatService->verifySessionSig( $sessionId, $providedSig)) {
        $newSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $newSig);
        $providedSig = $newSig;
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
    $fileData = $uploadResult['data'];
    $messageText = '[ফাইল] ' . ChatService::sanitizeMessage($fileData['file_name']);

    $messageId = $chatModel->insertFileMessage(
        $sessionId, $messageText, $fileData['message_type'],
        $fileData['file_url'], $fileData['file_name'], $fileData['file_size'],
        $fileData['file_type'], 'visitor'
    );

    $chatModel->touchSession($sessionId);

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
    $rateCheck = $chatService->checkRateLimit( 'messages', 60, 60);
    if (!$rateCheck['allowed']) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।', 'retry_after' => $rateCheck['retry_after']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sessionId = $_GET['session_id'] ?? '';
    $providedSig = $_GET['session_sig'] ?? '';
    $after = $_GET['after'] ?? '';
    $offset = (int)($_GET['offset'] ?? 0);
    $limit = min((int)($_GET['limit'] ?? 50), 100);

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success', 'data' => [], 'has_more' => false]);
    }

    // Check if session has timed out due to inactivity
    if ($chatService->isSessionTimedOut($sessionId)) {
        $chatModel->closeSession($sessionId);
        ChatService::jsonResponse(['status' => 'success', 'data' => [], 'has_more' => false, 'session_expired' => true]);
    }

    // Read-only: session UUID auth is sufficient; auto-recover missing sig
    if (!$chatService->verifySessionSig( $sessionId, $providedSig)) {
        // Generate a new signature for the client to recover
        $newSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $newSig);
        $providedSig = $newSig;
    }

    $messages = $chatService->getMessagesQuery( $sessionId, $after ?: null, $offset, $limit + 1);
    $hasMore = count($messages) > $limit;
    if ($hasMore) {
        array_pop($messages);
    }

    ChatService::jsonResponse(['status' => 'success', 'data' => $messages, 'has_more' => $hasMore, 'session_sig' => $providedSig], 200, 'no-cache, private');
});

/**
 * GET /api/chat/unread?session_id=xxx
 */
$router->get('/api/chat/unread', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'unread', 60, 60);
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

    // Read-only: session UUID auth is sufficient; auto-recover missing sig
    if (!$chatService->verifySessionSig( $sessionId, $providedSig)) {
        $newSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $newSig);
        $providedSig = $newSig;
    }

    $count = $chatModel->countUnreadAdminMessages($sessionId);

    ChatService::jsonResponse(['status' => 'success', 'data' => ['count' => $count], 'session_sig' => $providedSig], 200, 'no-cache, private');
});

/**
 * GET /api/chat/unread/count?session_id=xxx
 * Lightweight endpoint: returns just the raw count number (no JSON wrapper)
 */
$router->get('/api/chat/unread/count', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'unread_count', 30, 60);
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

    // Read-only: auto-recover missing sig
    if (!$chatService->verifySessionSig( $sessionId, $providedSig)) {
        $newSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $newSig);
        $providedSig = $newSig;
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
    $rateCheck = $chatService->checkRateLimit( 'read', 20, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $sessionId = $input['session_id'] ?? '';
    $providedSig = $input['session_sig'] ?? '';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success', 'message' => 'No messages to mark as read']);
    }

    // Auto-recover missing sig
    if (!$chatService->verifySessionSig( $sessionId, $providedSig)) {
        $newSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $newSig);
        $providedSig = $newSig;
    }

    $chatModel->markAdminMessagesRead($sessionId);

    ChatService::jsonResponse(['status' => 'success', 'message' => 'Messages marked as read', 'session_sig' => $providedSig]);
});

// ================================================================
// ADMIN API ENDPOINTS
// ================================================================

/**
 * GET /api/chat/admin/conversations
 */
$router->get('/api/chat/admin/conversations', function () use ($chatService, $chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $offset = (int)($_GET['offset'] ?? 0);
    $limit = min((int)($_GET['limit'] ?? 50), 100);

    $result = $chatModel->getAdminConversations($offset, $limit);

    ChatService::jsonResponse(['status' => 'success', 'data' => $result['data'], 'has_more' => $result['has_more']], 200, 'no-cache, private');
});

/**
 * GET /api/chat/admin/conversations/{session_id}
 */
$router->get('/api/chat/admin/conversations/{session_id}', function ($sessionId) use ($chatService, $chatModel, $authService) {
    $authService->ensureCan('manage_chat');

    $messages = $chatService->getMessagesQuery( $sessionId, null, 0, 200);

    // Mark unread visitor messages as read
    $chatModel->markVisitorMessagesRead($sessionId);

    ChatService::jsonResponse(['status' => 'success', 'data' => $messages], 200, 'no-cache, private');
});

/**
 * POST /api/chat/admin/reply
 */
$router->post('/api/chat/admin/reply', function () use ($chatService, $mysqli, $authService, $chatModel) {
    $authService->ensureCan('manage_chat');

    $input = json_decode(file_get_contents('php://input'), true);
    $sessionId = $input['session_id'] ?? '';
    $message = ChatService::sanitizeMessage($input['message'] ?? '');

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if (empty($message)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Message is required'], 400);
    }
    if (mb_strlen($message) > 1000) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Message too long (max 1000 characters)'], 400);
    }

    $adminId = $authService->getCurrentUserId();

    $messageId = $chatModel->insertMessage($sessionId, $message, 'admin', $adminId);
    $chatModel->touchSession($sessionId);

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
$router->post('/api/chat/admin/upload', function () use ($chatService, $authService, $chatModel) {
    $authService->ensureCan('manage_chat');

    $sessionId = $_POST['session_id'] ?? '';
    $message = trim($_POST['message'] ?? '');

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }
    if (empty($_FILES['file'])) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'No file uploaded'], 400);
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

// ================================================================
// TYPING INDICATOR ENDPOINTS
// ================================================================

/**
 * POST /api/chat/typing
 * Visitor is typing notification
 */
$router->post('/api/chat/typing', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'typing_send', 30, 60);
    if (!$rateCheck['allowed']) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে।'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $sessionId = $input['session_id'] ?? '';
    $providedSig = $input['session_sig'] ?? '';

    if (empty($sessionId)) {
        ChatService::jsonResponse(['status' => 'error', 'message' => 'Session ID is required'], 400);
    }

    if (!$chatModel->sessionExists($sessionId)) {
        ChatService::jsonResponse(['status' => 'success']);
    }

    // Auto-recover missing sig
    if (!$chatService->verifySessionSig( $sessionId, $providedSig)) {
        $newSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $newSig);
    }

    $chatModel->setVisitorTyping($sessionId);

    ChatService::jsonResponse(['status' => 'success']);
});

/**
 * GET /api/chat/typing?session_id=xxx
 * Check if admin is typing (for visitor widget)
 */
$router->get('/api/chat/typing', function () use ($chatService, $chatModel) {
    $rateCheck = $chatService->checkRateLimit( 'typing_check', 60, 60);
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

    // Read-only: auto-recover missing sig
    if (!$chatService->verifySessionSig( $sessionId, $providedSig)) {
        $newSig = $chatService->signSession($sessionId);
        $chatModel->setSessionSigBySessionId($sessionId, $newSig);
        $providedSig = $newSig;
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

    $input = json_decode(file_get_contents('php://input'), true);
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

    $input = json_decode(file_get_contents('php://input'), true);
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
        'chat_sound_enabled'
    ];

    try {
        $chatModel->beginTransaction();

        if (!isset($settings['chat_enabled'])) $settings['chat_enabled'] = '0';
        if (!isset($settings['chat_offline_enabled'])) $settings['chat_offline_enabled'] = '0';
        if (!isset($settings['chat_sound_enabled'])) $settings['chat_sound_enabled'] = '0';

        foreach ($allowedKeys as $key) {
            if (!isset($settings[$key])) continue;
            $value = sanitize_input((string)$settings[$key]);
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
 * GET /settings/chat
 */
$router->get('/settings/chat', function () use ($chatModel, $twig, $authService) {
    $authService->ensureCan('manage_settings');

    $settings = $chatModel->getChatSettings();

    echo $twig->render('settings/chat.twig', [
        'title' => 'চ্যাট সেটিংস',
        'header_title' => '💬 চ্যাট উইজেট সেটিংস',
        'settings' => $settings,
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
        ChatService::jsonResponse(['status' => 'error', 'message' => 'অনুরোধের সীমা অতিক্রম করেছে। দয়া করে ' . $rateCheck['retry_after'] . ' সেকেন্ড পর আবার চেষ্টা করুন။'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true);
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
 * List all canned responses grouped by category
 */
$router->get('/api/chat/admin/canned', function () use ($chatService, $chatModel, $authService) {
    $authService->ensureCan('manage_settings');

    $responses = $chatModel->getAllCannedResponses();

    ChatService::jsonResponse(['status' => 'success', 'data' => $responses], 200, 'no-cache, private');
});

/**
 * POST /api/chat/admin/canned
 * Create a new canned response
 */
$router->post('/api/chat/admin/canned', function () use ($chatModel, $authService) {
    $authService->ensureCan('manage_settings');

    $input = json_decode(file_get_contents('php://input'), true);
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

    $input = json_decode(file_get_contents('php://input'), true);
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
 * GET /settings/chat/canned
 * Canned responses management page
 */
$router->get('/settings/chat/canned', function () use ($chatService, $twig, $authService) {
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

    $offset = (int)($_GET['offset'] ?? 0);
    $limit = min((int)($_GET['limit'] ?? 50), 100);

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
 * Admin page to view offline inquiry messages
 */
$router->get('/chat/admin/offline', function () use ($chatService, $twig, $authService) {
    $authService->ensureCan('manage_chat');

    header('Content-Type: text/html; charset=utf-8');

    echo $twig->render('chat/offline.twig', [
        'title' => 'অফলাইন বার্তা',
        'header_title' => '📩 অফলাইন ইনকোয়ারি বার্তা',
    ]);
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
    $rateCheck = $chatService->checkRateLimit('admin_status', 30, 60);
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

// Auto-trigger cleanup on admin page load (5% chance to spread load)
if (mt_rand(1, 100) <= 5) {
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $count = $chatModel->countOldClosedSessions($cutoff);
    if ($count > 0) {
        $chatModel->deleteOldClosedSessionMessages($cutoff);
        $chatModel->deleteOldClosedSessions($cutoff);
    }
}
