<?php
/**
 * config/fcm.php
 * 
 * Firebase Cloud Messaging helper functions.
 * Uses kreait/firebase-php for sending push notifications.
 * Leverages config/firebase.php for configuration.
 */

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;

require_once __DIR__ . '/firebase.php';

// Prevent double initialization
if (!function_exists('getFirebaseMessaging')) {
    /**
     * Get the Firebase Messaging instance (singleton).
     */
    function getFirebaseMessaging() {
        static $messaging = null;
        
        if ($messaging !== null) {
            return $messaging;
        }
        
        $config = getFirebaseConfig();
        $saPath = $config['service_account_path'] ?? '';
        
        if (empty($saPath) || !file_exists($saPath)) {
            error_log('[FCM] Service account file not found: ' . ($saPath ?: '(not configured)'));
            return null;
        }
        
        try {
            $factory = (new Factory())
                ->withServiceAccount($saPath)
                ->withProjectId($config['project_id'] ?? 'digi-union-lgdhaka');
            
            $messaging = $factory->createMessaging();
            return $messaging;
        } catch (\Throwable $e) {
            error_log('[FCM] Failed to initialize Firebase: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Send FCM push notification to a single device token.
     * 
     * @param string $token FCM device token
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Optional data payload
     * @return bool Success status
     */
    function sendFcmNotification($token, $title, $body, $data = []) {
        $messaging = getFirebaseMessaging();
        if (!$messaging || empty($token)) {
            return false;
        }
        
        try {
            $message = CloudMessage::withTarget('token', $token)
                ->withNotification(Notification::create($title, $body))
                ->withData($data)
                ->withHighestPossiblePriority();
            
            $messaging->send($message);
            return true;
        } catch (InvalidMessage $e) {
            error_log('[FCM] Invalid message: ' . $e->getMessage());
            return false;
        } catch (NotFound $e) {
            error_log('[FCM] Token not found (expired): ' . substr($token, 0, 20) . '...');
            return false;
        } catch (\Throwable $e) {
            error_log('[FCM] Send error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send FCM push notification to multiple device tokens (multicast).
     * 
     * @param array $tokens Array of FCM device tokens
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Optional data payload
     * @return array ['success' => int, 'failure' => int, 'invalid_tokens' => array]
     */
    function sendFcmMulticast($tokens, $title, $body, $data = []) {
        $messaging = getFirebaseMessaging();
        if (!$messaging || empty($tokens)) {
            return ['success' => 0, 'failure' => count($tokens), 'invalid_tokens' => []];
        }
        
        $invalidTokens = [];
        $successCount = 0;
        $failureCount = 0;
        
        try {
            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData($data)
                ->withHighestPossiblePriority();
            
            // Firebase supports multicast to up to 500 tokens
            $chunks = array_chunk($tokens, 500);
            
            foreach ($chunks as $chunk) {
                try {
                    $report = $messaging->sendMulticast($message, $chunk);
                    $successCount += $report->successes()->count();
                    $failureCount += $report->failures()->count();
                    
                    // Collect invalid tokens for cleanup
                    foreach ($report->failures() as $failure) {
                        $error = $failure->error();
                        if ($error instanceof NotFound || $error instanceof InvalidMessage) {
                            $invalidTokens[] = $failure->target()->value();
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[FCM] Multicast chunk error: ' . $e->getMessage());
                    $failureCount += count($chunk);
                }
            }
        } catch (\Throwable $e) {
            error_log('[FCM] Multicast error: ' . $e->getMessage());
            $failureCount = count($tokens);
        }
        
        return [
            'success' => $successCount,
            'failure' => $failureCount,
            'invalid_tokens' => $invalidTokens,
        ];
    }
}
