<?php

/**
 * ════════════════════════════════════════════════════════
 * Comprehensive Error Handling Configuration
 * ════════════════════════════════════════════════════════
 *
 * Global PHP error/exception/shutdown handlers — স্তর ১-৩
 * - set_error_handler()     → রানটাইম errors (warnings, notices, deprecated)
 * - set_exception_handler() → uncaught exceptions
 * - register_shutdown_function() → fatal errors (parse, core, compile)
 *
 * renderError() & docError() → Manual HTTP error pages
 * ════════════════════════════════════════════════════════
 */

// ──────────── Global Error Handlers (স্তর ১-৩) ────────────

if (class_exists('ErrorHandler')) {
    // স্তর ১: সব ধরনের PHP errors ধরবে
    set_error_handler(['ErrorHandler', 'handleError']);
    
    // স্তর ২: Uncaught exceptions ধরবে
    set_exception_handler(['ErrorHandler', 'handleException']);
    
    // স্তর ৩: Fatal errors (যা normal handlers মিস করে) ধরবে
    register_shutdown_function(['ErrorHandler', 'handleShutdown']);
    
    // স্তর ৫: Log rotation (50MB হলে rotate করবে, 5 generations রাখবে)
    ErrorHandler::rotateLog();
}

// ──────────── Manual HTTP Error Page Renderer ────────────

/**
 * Global error renderer
 * Twig না থাকলেও safe fallback দেবে
 */
function renderError(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');

    // Log detailed error information
    $logPath = __DIR__ . '/../storage/logs/error.log';
    $fallbackLogPath = __DIR__ . '/../storage/logs/bdris_log.txt';
    if (!is_dir(dirname($logPath))) {
        mkdir(dirname($logPath), 0755, true);
    }
    if (!file_exists($logPath)) {
        @touch($logPath);
    }
    if (!is_writable($logPath)) {
        $logPath = $fallbackLogPath;
        if (!file_exists($logPath)) {
            @touch($logPath);
        }
    }
    
    // বিস্তারিত error information সংগ্রহ
    $timestamp = date('d-M-Y H:i:s e');
    $currentUrl = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $logEntry = <<<LOG
================================================================================
[$timestamp] ERROR CODE: $code
MESSAGE: $message
URL: $currentUrl
CLIENT IP: $clientIp
USER AGENT: $userAgent
================================================================================

LOG;
    
    if (!@error_log($logEntry, 3, $logPath)) {
        @file_put_contents($fallbackLogPath, $logEntry, FILE_APPEND);
    }

    // Check both Twig and SafeTwig wrapper
    if (isset($GLOBALS['twig']) && (
        $GLOBALS['twig'] instanceof \Twig\Environment || 
        ($GLOBALS['twig'] instanceof SafeTwig)
    )) {
        try {
            $GLOBALS['twig']->display('errors/error.twig', [
                'title'         => 'ত্রুটি ' . $code,
                'header_title'  => 'ত্রুটি',
                'error_code'    => $code,
                'error_message' => $message
            ]);
        } catch (\Exception $e) {
            // Twig render fail হলে fallback
            echo "<h1>Error {$code}</h1><p>{$message}</p>";
        }
    } else {
        echo "<h1>Error {$code}</h1><p>{$message}</p>";
    }

    exit;
}


/**
 * Error handler controller
 * /error?code=404
 */
function docError()
{
    $code = isset($_GET['code']) ? (int) $_GET['code'] : 500;

    switch ($code) {
        case 403:
            renderError(403, 'Access forbidden.');
            break;

        case 404:
            renderError(404, 'The page you are looking for could not be found.');
            break;

        default:
            renderError(500, 'An unexpected server error occurred.');
            break;
    }
}
