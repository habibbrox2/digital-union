<?php
/**
 * modules/Services/PushService.php
 *
 * Push notification delivery for the live chat system using Firebase Cloud Messaging (FCM).
 * Visitors subscribe their browser to a chat session; when an admin replies,
 * ChatController asks this service to push a notification to that session's
 * subscriptions (delivered even when the visitor's tab is closed).
 */

class PushService
{
    private ChatModel $chatModel;

    public function __construct(ChatModel $chatModel)
    {
        $this->chatModel = $chatModel;
    }

    /**
     * Whether visitor web push is enabled by the admin (default: enabled).
     */
    public function isEnabled(): bool
    {
        return ($this->chatModel->getChatSetting('chat_visitor_push_enabled') ?? '1') === '1';
    }

    /**
     * Whether FCM is configured (Firebase Admin SDK available).
     */
    public function isConfigured(): bool
    {
        $serviceAccountPath = __DIR__ . '/../../config/digi-union-lgdhaka-firebase-adminsdk-fbsvc-39e5307dd4.json';
        return file_exists($serviceAccountPath);
    }

    /**
     * Get the Firebase client config for the frontend.
     * Returns an array with apiKey, projectId, etc.
     */
    public function getFirebaseClientConfig(): array
    {
        return [
            'apiKey' => 'AIzaSyBdNqFdh0DZ3Zz-iztHL2uGtoYZDLzhdyw',
            'authDomain' => 'digi-union-lgdhaka.firebaseapp.com',
            'projectId' => 'digi-union-lgdhaka',
            'storageBucket' => 'digi-union-lgdhaka.firebasestorage.app',
            'messagingSenderId' => '599628365980',
            'appId' => '1:599628365980:web:e90cefbce2c52ccf036d59',
        ];
    }

    /**
     * Get the VAPID public key for FCM web push (still needed for web push with FCM).
     */
    public function getVapidPublicKey(): string
    {
        // FCM uses its own VAPID key derived from the service account.
        // For web push, we return the key pair the user provided.
        return 'BEXZ3EoUslEvolRGpCkIN0BbDMPyEc0FsAej9yk9M_I9f-fIbI-yNS-r7IcnJ5MCzEjDZNVpymuX-3zmGzN8AHk';
    }

    /**
     * Save an FCM token for a session.
     */
    public function subscribeFcm(string $sessionId, string $fcmToken, string $sessionSig = '', string $userType = 'visitor', ?int $userId = null): bool
    {
        $fcmToken = trim($fcmToken);
        if (empty($fcmToken) || strlen($fcmToken) < 10) {
            return false;
        }
        $this->chatModel->saveFcmToken($sessionId, $fcmToken, $sessionSig, $userType, $userId);
        return true;
    }

    /**
     * Remove an FCM token.
     */
    public function unsubscribeFcm(string $fcmToken): void
    {
        $this->chatModel->deleteFcmToken($fcmToken);
    }

    /**
     * Send a push notification to every FCM token of a session (visitor notifications).
     * Best-effort: never throws; failures are logged and invalid tokens are pruned.
     */
    public function sendToSession(string $sessionId, string $title, string $body, array $data = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Require FCM config
        if (!function_exists('sendFcmMulticast')) {
            require_once __DIR__ . '/../../config/fcm.php';
        }

        if (!function_exists('sendFcmMulticast')) {
            error_log('[Push] FCM functions not available');
            return;
        }

        $tokens = $this->chatModel->getFcmTokensBySession($sessionId);
        if (empty($tokens)) {
            return;
        }

        $fcmTokens = array_column($tokens, 'fcm_token');
        $result = sendFcmMulticast($fcmTokens, $title, 'নতুন লাইভ চ্যাট মেসেজ এসেছে', $data);

        // Prune invalid tokens
        if (!empty($result['invalid_tokens'])) {
            $this->chatModel->deleteInvalidFcmTokens($result['invalid_tokens']);
        }

        if ($result['failure'] > 0) {
            error_log("[Push] Session $sessionId: {$result['success']} success, {$result['failure']} failed");
        }
    }

    /**
     * Send a push notification to all admin devices (for new visitor messages).
     */
    public function sendToAdmins(string $title, string $body, array $data = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!function_exists('sendFcmMulticast')) {
            require_once __DIR__ . '/../../config/fcm.php';
        }

        if (!function_exists('sendFcmMulticast')) {
            error_log('[Push] FCM functions not available');
            return;
        }

        $tokens = $this->chatModel->getAllAdminFcmTokens();
        if (empty($tokens)) {
            return;
        }

        $fcmTokens = array_column($tokens, 'fcm_token');
        $result = sendFcmMulticast($fcmTokens, $title, $body, $data);

        // Prune invalid tokens
        if (!empty($result['invalid_tokens'])) {
            $this->chatModel->deleteInvalidFcmTokens($result['invalid_tokens']);
        }

        if ($result['failure'] > 0) {
            error_log("[Push] Admin notification: {$result['success']} success, {$result['failure']} failed");
        }
    }

    /** Send a privacy-safe generic alert to union officials' devices. */
    public function sendToUnion(int $unionId, string $title, array $data = []): void
    {
        if (!$this->isEnabled()) return;
        if (!function_exists('sendFcmMulticast')) require_once __DIR__ . '/../../config/fcm.php';
        if (!function_exists('sendFcmMulticast')) return;
        $tokens = $this->chatModel->getFcmTokensForUnion($unionId);
        if (!$tokens) return;
        $result = sendFcmMulticast(array_column($tokens, 'fcm_token'), $title, 'নতুন লাইভ চ্যাট মেসেজ এসেছে', $data);
        if (!empty($result['invalid_tokens'])) $this->chatModel->deleteInvalidFcmTokens($result['invalid_tokens']);
        if ($result['failure'] > 0) error_log("[Push] Union {$unionId}: {$result['success']} success, {$result['failure']} failed");
    }

    /**
     * Send a data-only message (no notification) to trigger in-app update.
     */
    public function sendDataMessage(string $fcmToken, array $data): bool
    {
        if (!function_exists('sendFcmNotification')) {
            require_once __DIR__ . '/../../config/fcm.php';
        }

        if (!function_exists('sendFcmNotification')) {
            return false;
        }

        // Send as notification with silent data for FCM
        return sendFcmNotification($fcmToken, '', '', $data);
    }

    /**
     * Clean up expired tokens (older than 30 days).
     */
    public function cleanExpiredTokens(): int
    {
        return $this->chatModel->cleanExpiredFcmTokens();
    }
}
