<?php
/**
 * config/firebase.php
 *
 * Firebase Cloud Messaging configuration.
 * Reads FIREBASE_* env vars and loads the service-account JSON.
 *
 * Usage:
 *   require_once __DIR__ . '/config/firebase.php';
 *   $firebaseConfig = getFirebaseConfig();
 */

declare(strict_types=1);

if (!function_exists('getFirebaseConfig')) {
    /**
     * Get Firebase/FCM configuration from env + service account JSON.
     *
     * @return array{
     *     enabled: bool,
     *     project_id: string,
     *     vapid_key: string,
     *     service_account_path: string,
     *     service_account: array|null,
     *     client_email: string,
     *     private_key: string,
     * }
     */
    function getFirebaseConfig(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $enabled       = filter_var($_ENV['FCM_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $projectId     = trim((string)($_ENV['FIREBASE_PROJECT_ID'] ?? ''));
        $vapidKey      = trim((string)($_ENV['FCM_VAPID_KEY'] ?? ''));
        $saPath        = trim((string)($_ENV['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? ''));

        // Resolve relative path from project root
        if ($saPath !== '' && !preg_match('#^[A-Z]:\\\\|/#i', $saPath)) {
            $saPath = dirname(__DIR__) . '/' . ltrim($saPath, '/\\');
        }

        $serviceAccount = null;
        $clientEmail    = '';
        $privateKey     = '';

        if ($saPath !== '' && is_file($saPath)) {
            $raw = file_get_contents($saPath);
            if ($raw !== false) {
                $serviceAccount = json_decode($raw, true);
                if (is_array($serviceAccount)) {
                    $clientEmail = $serviceAccount['client_email'] ?? '';
                    $privateKey  = $serviceAccount['private_key'] ?? '';

                    // Auto-derive project ID if not set in env
                    if ($projectId === '') {
                        $projectId = $serviceAccount['project_id'] ?? '';
                    }
                }
            }
        }

        // Web SDK config (from .env)
        $webConfig = [
            'apiKey'            => trim((string)($_ENV['FCM_API_KEY'] ?? '')),
            'authDomain'        => trim((string)($_ENV['FCM_AUTH_DOMAIN'] ?? '')),
            'projectId'         => $projectId,
            'storageBucket'     => trim((string)($_ENV['FCM_STORAGE_BUCKET'] ?? '')),
            'messagingSenderId' => trim((string)($_ENV['FCM_MESSAGING_SENDER_ID'] ?? '')),
            'appId'             => trim((string)($_ENV['FCM_APP_ID'] ?? '')),
            'measurementId'     => trim((string)($_ENV['FCM_MEASUREMENT_ID'] ?? '')),
        ];

        $cached = [
            'enabled'              => $enabled,
            'project_id'           => $projectId,
            'vapid_key'            => $vapidKey,
            'service_account_path' => $saPath,
            'service_account'      => $serviceAccount,
            'client_email'         => $clientEmail,
            'private_key'          => $privateKey,
            'web_config'           => $webConfig,
        ];

        return $cached;
    }
}
