<?php
/**
 * modules/Services/PushService.php
 *
 * Web Push delivery (Push API + VAPID) for the live chat system.
 * Visitors subscribe their browser to a chat session; when an admin replies,
 * ChatController asks this service to push a notification to that session's
 * subscriptions (delivered even when the visitor's tab is closed).
 */

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class PushService
{
    private ChatModel $chatModel;
    private ?string $subject = null;

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
     * Whether a usable VAPID key pair is already stored (no generation).
     */
    public function isConfigured(): bool
    {
        return (string)($this->chatModel->getChatSetting('chat_push_vapid_public_key') ?? '') !== ''
            && (string)($this->chatModel->getChatSetting('chat_push_vapid_private_key') ?? '') !== '';
    }

    /**
     * Ensure VAPID keys exist; generate and persist them on first use.
     *
     * @return bool true when a usable key pair is present
     */
    public function ensureKeys(): bool
    {
        if ($this->isConfigured()) {
            return true;
        }

        // Some Windows/XAMPP PHP builds cannot generate EC keys unless an
        // explicit OpenSSL config file is provided before the extension loads.
        if (getenv('OPENSSL_CONF') === false || getenv('OPENSSL_CONF') === '') {
            foreach (['C:/xampp/apache/conf/openssl.cnf', 'D:/xampp/apache/conf/openssl.cnf'] as $cnf) {
                if (is_file($cnf)) {
                    putenv('OPENSSL_CONF=' . $cnf);
                    break;
                }
            }
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            error_log('[Push] VAPID key generation failed: ' . $e->getMessage());
            return false;
        }

        $this->chatModel->saveChatSetting('chat_push_vapid_public_key', $keys['publicKey']);
        $this->chatModel->saveChatSetting('chat_push_vapid_private_key', $keys['privateKey']);
        return true;
    }

    /**
     * Current VAPID public key ('' when not configured).
     */
    public function getVapidPublicKey(): string
    {
        return (string)($this->chatModel->getChatSetting('chat_push_vapid_public_key') ?? '');
    }

    /**
     * VAPID subject (mailto:). Persists a sensible default on first use.
     */
    public function getSubject(): string
    {
        if ($this->subject !== null) {
            return $this->subject;
        }
        $stored = (string)($this->chatModel->getChatSetting('chat_push_subject') ?? '');
        if ($stored !== '') {
            $this->subject = $stored;
            return $stored;
        }
        $host = 'localhost';
        if (defined('SITE_URL') && SITE_URL !== '') {
            $host = (string)(parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost');
        }
        $subject = 'mailto:no-reply@' . $host;
        $this->chatModel->saveChatSetting('chat_push_subject', $subject);
        $this->subject = $subject;
        return $subject;
    }

    /**
     * Validate and persist a browser push subscription for a session.
     */
    public function subscribe(string $sessionId, string $endpoint, string $p256dh, string $auth): bool
    {
        $endpoint = trim($endpoint);
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $endpoint)) {
            return false;
        }
        $p256 = base64_decode(strtr($p256dh, '-_', '+/'), true);
        $authRaw = base64_decode(strtr($auth, '-_', '+/'), true);
        if ($p256 === false || strlen($p256) !== 65 || $authRaw === false || strlen($authRaw) !== 16) {
            return false;
        }
        $this->chatModel->savePushSubscription($sessionId, $endpoint, $p256dh, $auth);
        return true;
    }

    /**
     * Remove a push subscription.
     */
    public function unsubscribe(string $endpoint): void
    {
        $this->chatModel->deletePushSubscription($endpoint);
    }

    /**
     * Send a push notification to every subscription of a session.
     * Best-effort: never throws; failures are logged and expired
     * (410 Gone) subscriptions are pruned.
     */
    public function sendToSession(string $sessionId, string $title, string $body): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if (!$this->ensureKeys()) {
            return;
        }
        $subscriptions = $this->chatModel->getPushSubscriptions($sessionId);
        if (empty($subscriptions)) {
            return;
        }

        $publicKey  = (string)($this->chatModel->getChatSetting('chat_push_vapid_public_key') ?? '');
        $privateKey = (string)($this->chatModel->getChatSetting('chat_push_vapid_private_key') ?? '');
        if ($publicKey === '' || $privateKey === '') {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => $this->getSubject(),
                    'publicKey'  => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ], ['TTL' => 86400]);

            $payload = json_encode([
                'title' => $title,
                'body'  => $body,
                'url'   => defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/' : '/',
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $sub) {
                try {
                    $report = $webPush->sendOneNotification(
                        Subscription::create([
                            'endpoint' => $sub['endpoint'],
                            'keys'     => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth']],
                        ]),
                        $payload
                    );
                    if (!$report->isSuccess()) {
                        if ($report->isSubscriptionExpired()) {
                            $this->chatModel->deletePushSubscription($sub['endpoint']);
                        }
                        error_log('[Push] send failed for ' . substr($sub['endpoint'], 0, 60) . ': ' . $report->getReason());
                    }
                } catch (\Throwable $e) {
                    error_log('[Push] send error for ' . substr($sub['endpoint'], 0, 60) . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('[Push] push delivery error: ' . $e->getMessage());
        }
    }
}
