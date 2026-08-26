<?php
/**
 * public/firebase-config.js.php
 *
 * Outputs the Firebase web SDK configuration as a global JS object.
 * The browser loads this via <script src="/firebase-config.js.php">.
 *
 * Firebase web config values come from .env (FCM_* variables).
 * The service-account.json (server-side only) is never exposed.
 */

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: private, no-cache, no-store, must-revalidate');

// Load dotenv if available (for standalone testing)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        try { $dotenv->load(); } catch (\Throwable $e) {}
    }
}
?>
// Firebase Web SDK Configuration
// Auto-generated from .env — do not edit manually.
window.FIREBASE_CONFIG = {
  apiKey:            "<?php echo htmlspecialchars($_ENV['FCM_API_KEY'] ?? ''); ?>",
  authDomain:        "<?php echo htmlspecialchars($_ENV['FCM_AUTH_DOMAIN'] ?? ''); ?>",
  projectId:         "<?php echo htmlspecialchars($_ENV['FCM_PROJECT_ID'] ?? ''); ?>",
  storageBucket:     "<?php echo htmlspecialchars($_ENV['FCM_STORAGE_BUCKET'] ?? ''); ?>",
  messagingSenderId: "<?php echo htmlspecialchars($_ENV['FCM_MESSAGING_SENDER_ID'] ?? ''); ?>",
  appId:             "<?php echo htmlspecialchars($_ENV['FCM_APP_ID'] ?? ''); ?>",
  measurementId:     "<?php echo htmlspecialchars($_ENV['FCM_MEASUREMENT_ID'] ?? ''); ?>",
  vapidKey:          "<?php echo htmlspecialchars($_ENV['FCM_VAPID_KEY'] ?? ''); ?>"
};
