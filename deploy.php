<?php

/**
 * Digital Union - Webhook Deploy Handler
 * 
 * Receives git webhook events and triggers automatic deployment
 * Can be called manually via URL or by GitHub/GitLab webhooks
 * 
 * Usage:
 *   http://yoursite.com/deploy.php?token=YOUR_SECRET_TOKEN
 *   http://yoursite.com/deploy.php?token=YOUR_SECRET_TOKEN&env=staging
 * 
 * GitHub Webhook:
 *   1. Go to Repository Settings > Webhooks
 *   2. Add webhook: http://yoursite.com/deploy.php
 *   3. Content type: application/json
 *   4. Secret: YOUR_SECRET_TOKEN
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

// Load .env file
$env_file = __DIR__ . '/.env';
$env_vars = [];
if (file_exists($env_file)) {
    $lines = file($env_file);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $env_vars[trim($key)] = trim($value);
        }
    }
}

// Get deployment token from .env or environment
define(
    'DEPLOY_TOKEN',
    $env_vars['DEPLOY_TOKEN'] ??
        getenv('DEPLOY_TOKEN') ?:
        'your-super-secret-token-here'
);

// Allow deployments from these IPs (GitHub, GitLab, etc)
define('ALLOWED_IPS', [
    '127.0.0.1',
    '::1',
    // GitHub webhooks
    '140.82.112.0/20',
    '143.55.64.0/20',
    '192.30.252.0/22',
    '185.199.108.0/22',
]);

// Deployment configuration
// Note: If deploy.php is in public/, dirname(__DIR__) will point to project root
define('PROJECT_ROOT', dirname(__DIR__));

define('DEPLOY_ENVIRONMENTS', [
    'production' => [
        'branch' => 'main',
        'path' => PROJECT_ROOT,
        'script' => './deploy.sh',
        'timeout' => 300, // 5 minutes
    ],
    'staging' => [
        'branch' => 'develop',
        'path' => PROJECT_ROOT,
        'script' => './deploy.sh',
        'timeout' => 300,
    ],
    'development' => [
        'branch' => 'development',
        'path' => PROJECT_ROOT,
        'script' => './deploy.sh',
        'timeout' => 300,
    ],
]);

// Log directory (in project root, not public)
define('LOG_DIR', PROJECT_ROOT . '/storage/logs');
define('WEBHOOK_LOG', LOG_DIR . '/webhooks.log');

// ============================================================================
// FUNCTIONS
// ============================================================================

/**
 * Send JSON response
 */
function json_response($status, $message, $data = [])
{
    header('Content-Type: application/json');

    $response = [
        'status' => $status,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $data,
    ];

    http_response_code($status === 'success' ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Log webhook event
 */
function log_webhook($message, $data = [])
{
    @mkdir(dirname(WEBHOOK_LOG), 0755, true);
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $log_message = "[$timestamp] [$ip] $message";

    if (!empty($data)) {
        $log_message .= " " . json_encode($data);
    }

    file_put_contents(WEBHOOK_LOG, $log_message . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Verify client IP
 */
function verify_client_ip()
{
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? null;

    if (!$client_ip) {
        return false;
    }

    // Check exact IP matches
    if (in_array($client_ip, ALLOWED_IPS)) {
        return true;
    }

    // Check CIDR ranges
    foreach (ALLOWED_IPS as $allowed) {
        if (strpos($allowed, '/') !== false) {
            if (ip_in_range($client_ip, $allowed)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check if IP is in CIDR range
 */
function ip_in_range($ip, $range)
{
    if (strpos($range, '/') === false) {
        return $ip === $range;
    }

    list($subnet, $bits) = explode('/', $range);
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    $subnet &= $mask;
    return ($ip & $mask) === $subnet;
}

/**
 * Verify webhook signature (GitHub)
 */
function verify_github_signature()
{
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;

    if (!$signature) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $payload, DEPLOY_TOKEN);
    return hash_equals($expected, $signature);
}

/**
 * Verify webhook signature (GitLab)
 */
function verify_gitlab_signature()
{
    $token = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? null;
    return $token === DEPLOY_TOKEN;
}

/**
 * Parse webhook payload
 */
function parse_webhook_payload()
{
    $payload = json_decode(file_get_contents('php://input'), true);

    $data = [
        'event' => 'webhook',
        'source' => 'unknown',
        'branch' => null,
        'commit' => null,
        'pusher' => null,
    ];

    // GitHub webhook
    if (isset($_SERVER['HTTP_X_GITHUB_EVENT'])) {
        $data['source'] = 'GitHub';
        $data['event'] = $_SERVER['HTTP_X_GITHUB_EVENT'];
        $data['branch'] = $payload['ref'] ?? null;
        $data['commit'] = $payload['head_commit']['id'] ?? null;
        $data['pusher'] = $payload['pusher']['name'] ?? 'unknown';
    }

    // GitLab webhook
    elseif (isset($_SERVER['HTTP_X_GITLAB_EVENT'])) {
        $data['source'] = 'GitLab';
        $data['event'] = $_SERVER['HTTP_X_GITLAB_EVENT'];
        $data['branch'] = str_replace('refs/heads/', '', $payload['ref'] ?? '');
        $data['commit'] = $payload['checkout_sha'] ?? null;
        $data['pusher'] = $payload['user_name'] ?? 'unknown';
    }

    return $data;
}

/**
 * Execute deployment
 */
function execute_deployment($environment, $webhook_data = null)
{
    if (!isset(DEPLOY_ENVIRONMENTS[$environment])) {
        return [
            'status' => 'error',
            'message' => "Unknown environment: $environment",
        ];
    }

    $config = DEPLOY_ENVIRONMENTS[$environment];
    $script = $config['path'] . '/' . $config['script'];

    if (!file_exists($script)) {
        return [
            'status' => 'error',
            'message' => "Deploy script not found: $script",
        ];
    }

    // Build command
    $command = "cd " . escapeshellarg($config['path']) . " && ";
    $command .= "bash " . escapeshellarg($script) . " ";
    $command .= escapeshellarg($environment) . " ";
    $command .= "--webhook 2>&1";

    log_webhook("Executing deployment", [
        'environment' => $environment,
        'command' => $command,
        'webhook' => $webhook_data,
    ]);

    // Execute with timeout
    $output = shell_exec("timeout {$config['timeout']} $command");

    // Parse output JSON
    if ($output) {
        $lines = array_reverse(explode("\n", trim($output)));
        foreach ($lines as $line) {
            if (trim($line) && $line[0] === '{') {
                $result = json_decode($line, true);
                if ($result) {
                    return $result;
                }
            }
        }
    }

    return [
        'status' => 'error',
        'message' => 'Deployment execution failed',
        'output' => $output,
    ];
}

// ============================================================================
// MAIN
// ============================================================================

// Verify deployment token
$token = $_GET['token'] ?? $_POST['token'] ?? null;
$environment = $_GET['env'] ?? $_POST['env'] ?? 'production';

// Log all requests
log_webhook("Request received", [
    'method' => $_SERVER['REQUEST_METHOD'],
    'token_provided' => !empty($token),
    'environment' => $environment,
]);

// Check if this is a webhook event
$is_webhook = !empty($_SERVER['HTTP_X_GITHUB_EVENT']) || !empty($_SERVER['HTTP_X_GITLAB_EVENT']);

if ($is_webhook) {
    // Webhook authentication

    // Allow localhost always
    if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
        // Verify IP
        if (!verify_client_ip()) {
            log_webhook("IP verification failed", ['ip' => $_SERVER['REMOTE_ADDR']]);
            json_response('error', 'IP not whitelisted', ['ip' => $_SERVER['REMOTE_ADDR']]);
        }

        // Verify signature
        $github_valid = isset($_SERVER['HTTP_X_GITHUB_EVENT']) && verify_github_signature();
        $gitlab_valid = isset($_SERVER['HTTP_X_GITLAB_EVENT']) && verify_gitlab_signature();

        if (!$github_valid && !$gitlab_valid) {
            log_webhook("Signature verification failed");
            json_response('error', 'Webhook signature invalid');
        }
    }

    // Parse webhook payload
    $webhook_data = parse_webhook_payload();

    // Determine environment from branch
    foreach (DEPLOY_ENVIRONMENTS as $env => $config) {
        if (
            $webhook_data['branch'] === 'refs/heads/' . $config['branch'] ||
            $webhook_data['branch'] === $config['branch']
        ) {
            $environment = $env;
            break;
        }
    }

    log_webhook("Webhook verified", $webhook_data);
} else {
    // Manual deployment via token

    if (!$token || $token !== DEPLOY_TOKEN) {
        log_webhook("Invalid deployment token");
        json_response('error', 'Invalid or missing deployment token');
    }

    log_webhook("Valid token provided");
    $webhook_data = null;
}

// Execute deployment
$result = execute_deployment($environment, $webhook_data);

log_webhook("Deployment result", $result);

json_response($result['status'], $result['message'], $result);
