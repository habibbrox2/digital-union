<?php

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
    if (!is_dir(dirname($logPath))) {
        mkdir(dirname($logPath), 0755, true);
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
    
    error_log($logEntry, 3, $logPath);

    // Check both Twig and SafeTwig wrapper
    if (isset($GLOBALS['twig']) && (
        $GLOBALS['twig'] instanceof \Twig\Environment || 
        ($GLOBALS['twig'] instanceof SafeTwig)
    )) {
        try {
            $GLOBALS['twig']->display('errors/error.twig', [
                'title'         => 'Error ' . $code,
                'header_title'  => 'Error',
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
