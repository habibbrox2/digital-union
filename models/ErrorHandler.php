<?php

/**
 * ErrorHandler Class
 * Handles all application errors with isolated error handling per route/template
 * - Logs errors to error.log (structured format)
 * - Shows error page only for that specific route/template
 * - Doesn't affect other routes/templates
 *
 * ════════════════════════════════════════════════════════
 * ৫-স্তরের Error Logging System
 * ════════════════════════════════════════════════════════
 * স্তর ১: PHP Error Handler    → handleError()
 * স্তর ২: Exception Handler    → handleException()
 * স্তর ৩: Shutdown Handler     → handleShutdown() / handleFatalError()
 * স্তর ৪: Error Logging        → logError()
 * স্তর ৫: Log Rotation         → rotateLog()
 * ════════════════════════════════════════════════════════
 */

class ErrorHandler
{
    private static $logFile;
    private static $showErrors = false;
    private static $twig = null;
    private static $routeStartTime = null;
    private static $currentRoute = null;
    
    /** @var int Max log file size before rotation (default 50MB) */
    private static $maxLogSize = 52428800;
    
    /** @var int Number of rotated log files to keep */
    private static $maxLogFiles = 5;
    
    /**
     * Initialize Error Handler
     */
    public static function init($logFilePath = null, $showErrors = false, $twig = null)
    {
        self::$logFile = $logFilePath ?? ini_get('error_log');
        self::$showErrors = $showErrors;
        self::$twig = $twig;
        
        // Ensure log directory exists
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Add .htaccess to protect logs
        $htaccessPath = $logDir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Deny from all\n");
        }

        if (!file_exists(self::$logFile)) {
            @touch(self::$logFile);
        }
    }
    
    /**
     * Set current route for context
     */
    public static function setCurrentRoute($method, $uri)
    {
        self::$currentRoute = "{$method} {$uri}";
        self::$routeStartTime = microtime(true);
    }
    
    /**
     * Get current route
     */
    public static function getCurrentRoute()
    {
        return self::$currentRoute ?? 'unknown';
    }
    
    /**
     * Log error to file
     */
    public static function logError($type, $message, $file, $line, $context = [])
    {
        $timestamp = date('Y-m-d H:i:s');
        $route = self::$currentRoute ?? self::detectRoute();
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $logMessage = "[{$timestamp}] [{$type}]\n";
        $logMessage .= "Route: {$route}\n";
        $logMessage .= "Message: {$message}\n";
        $logMessage .= "File: {$file} (Line: {$line})\n";
        $logMessage .= "Client IP: {$clientIp}\n";
        
        if (!empty($context)) {
            // Remove large/recursive keys before JSON encode
            $safeContext = $context;
            if (isset($safeContext['backtrace'])) {
                $safeContext['backtrace'] = array_slice($safeContext['backtrace'], 0, 3);
            }
            $logMessage .= "Context: " . json_encode($safeContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        $logMessage .= "---\n";
        
        if (!@error_log($logMessage, 3, self::$logFile)) {
            $fallback = dirname(self::$logFile) . '/bdris_log.txt';
            @file_put_contents($fallback, $logMessage, FILE_APPEND);
        }
    }
    
    /**
     * Detect current route from server variables
     */
    private static function detectRoute()
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        return "{$method} {$uri}";
    }
    
    /**
     * Handle callback/route exceptions
     * Error is isolated to that route only
     */
    public static function handleRouteError(\Throwable $exception, $method = null, $uri = null)
    {
        $route = "{$method} {$uri}" ?? self::$currentRoute ?? 'unknown route';
        
        // Log the error
        self::logError(
            'ROUTE_ERROR',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            [
                'route' => $route,
                'trace' => $exception->getTraceAsString()
            ]
        );
        
        // Set response code if not already set
        if (!headers_sent()) {
            http_response_code(500);
        }
        
        // Render error page
        self::renderErrorPage(500, "এই পৃষ্ঠাটি লোড করতে সমস্যা হচ্ছে।", [
            'route' => $route,
            'exception' => self::$showErrors ? $exception->getMessage() : null
        ]);
    }
    
    /**
     * Handle Twig rendering errors
     * Error is isolated to that template only
     */
    public static function handleTwigError(\Throwable $exception, $templateName = null)
    {
        $template = $templateName ?? 'unknown template';
        
        // Log the error
        self::logError(
            'TWIG_ERROR',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            [
                'template' => $template,
                'route' => self::$currentRoute ?? 'unknown',
                'trace' => $exception->getTraceAsString()
            ]
        );
        
        // Set response code if not already set
        if (!headers_sent()) {
            http_response_code(500);
        }
        
        // Render error page
        self::renderErrorPage(500, "টেমপ্লেট রেন্ডার করতে সমস্যা হচ্ছে।", [
            'template' => $template,
            'exception' => self::$showErrors ? $exception->getMessage() : null
        ]);
    }
    
    /**
     * Handle fatal errors
     */
    public static function handleFatalError($error)
    {
        $typeName = self::getErrorTypeName($error['type']);
        
        self::logError(
            'FATAL_' . $typeName,
            $error['message'],
            $error['file'],
            $error['line'],
            [
                'route' => self::$currentRoute ?? 'system',
                'type'  => $typeName,
            ]
        );
        
        // 🔔 স্তর ৩-বি: Send email notification to admin
        if (function_exists('sendCriticalErrorAlert')) {
            sendCriticalErrorAlert(
                $typeName,
                $error['message'],
                $error['file'],
                $error['line'],
                [
                    'route'     => self::$currentRoute ?? self::detectRoute(),
                    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                ]
            );
        }
        
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        
        // Only render if no output has been sent yet
        if (ob_get_level() > 0 && ob_get_length() === 0) {
            self::renderErrorPage(500, "সার্ভার ত্রুটি: একটি মারাত্মক সমস্যা হয়েছে।", [
                'exception' => self::$showErrors ? $error['message'] : null,
            ]);
        }
    }
    
    /**
     * Get human-readable error type name for ALL PHP error types
     */
    public static function getErrorTypeName($type)
    {
        $types = [
            E_ERROR             => 'FATAL ERROR',
            E_WARNING           => 'WARNING',
            E_PARSE             => 'PARSE ERROR',
            E_NOTICE            => 'NOTICE',
            E_CORE_ERROR        => 'CORE ERROR',
            E_CORE_WARNING      => 'CORE WARNING',
            E_COMPILE_ERROR     => 'COMPILE ERROR',
            E_COMPILE_WARNING   => 'COMPILE WARNING',
            E_USER_ERROR        => 'USER ERROR',
            E_USER_WARNING      => 'USER WARNING',
            E_USER_NOTICE       => 'USER NOTICE',
            E_STRICT            => 'STRICT STANDARDS',
            E_RECOVERABLE_ERROR => 'RECOVERABLE ERROR',
            E_DEPRECATED        => 'DEPRECATED',
            E_USER_DEPRECATED   => 'USER DEPRECATED',
        ];
        return $types[$type] ?? 'UNKNOWN ERROR';
    }
    
    /**
     * Get severity level for an error type (for categorization)
     */
    public static function getErrorLevel($type)
    {
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        $warning = [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING];
        $notice = [E_NOTICE, E_USER_NOTICE, E_STRICT];
        $deprecated = [E_DEPRECATED, E_USER_DEPRECATED];
        
        if (in_array($type, $fatal, true))     return 'FATAL';
        if (in_array($type, $warning, true))   return 'WARNING';
        if (in_array($type, $notice, true))    return 'NOTICE';
        if (in_array($type, $deprecated, true)) return 'DEPRECATED';
        return 'UNKNOWN';
    }
    
    // ──────────────── স্তর ১: PHP Error Handler ────────────────
    
    /**
     * Handle PHP errors (warnings, notices, deprecations, etc.)
     * Use: set_error_handler(['ErrorHandler', 'handleError'])
     */
    public static function handleError($severity, $message, $file, $line, $errContext = [])
    {
        // Respect error_reporting level
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        $typeName = self::getErrorTypeName($severity);
        
        // Get backtrace (skip handleError + error handler internals)
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        array_shift($backtrace); // remove handleError() frame
        
        self::logError($typeName, $message, $file, $line, [
            'severity'     => $severity,
            'level'        => self::getErrorLevel($severity),
            'backtrace'    => $backtrace,
        ]);
        
        // For recoverable errors, let PHP's internal handler run too
        if ($severity === E_RECOVERABLE_ERROR) {
            return false;
        }
        
        return true;
    }
    
    // ──────────────── স্তর ২: Exception Handler ────────────────
    
    /**
     * Handle uncaught exceptions
     * Use: set_exception_handler(['ErrorHandler', 'handleException'])
     */
    public static function handleException(\Throwable $exception)
    {
        self::logError(
            'UNCAUGHT EXCEPTION',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            [
                'code'  => $exception->getCode(),
                'trace' => $exception->getTraceAsString(),
            ]
        );
        
        // 🔔 স্তর ২-বি: Send email notification to admin
        if (function_exists('sendCriticalErrorAlert')) {
            sendCriticalErrorAlert(
                'UNCAUGHT EXCEPTION',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                [
                    'route'     => self::getCurrentRoute(),
                    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                    'backtrace' => $exception->getTraceAsString(),
                ]
            );
        }
        
        if (!headers_sent()) {
            http_response_code(500);
        }
        
        self::renderErrorPage(500, 'সার্ভার ত্রুটি: একটি অপ্রত্যাশিত ব্যতিক্রম ঘটেছে।', [
            'exception' => self::$showErrors ? $exception->getMessage() : null,
        ]);
    }
    
    // ──────────────── স্তর ৩: Shutdown Handler ────────────────
    
    /**
     * Handle shutdown — catches fatal errors normal handlers miss
     * Use: register_shutdown_function(['ErrorHandler', 'handleShutdown'])
     */
    public static function handleShutdown()
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::handleFatalError($error);
        }
    }
    
    /**
     * Render error page using Twig or fallback HTML
     */
    public static function renderErrorPage($code, $message, $context = [])
    {
        try {
            // Try to render using Twig if available
            if (self::$twig instanceof \Twig\Environment) {
                echo self::$twig->render('errors/error.twig', [
                    'error_code' => $code,
                    'error_message' => $message,
                    'title' => "Error {$code}",
                    'header_title' => "Error {$code}",
                    'context' => $context
                ]);
            } else {
                // Fallback HTML
                self::renderFallbackErrorPage($code, $message, $context);
            }
        } catch (\Throwable $e) {
            // If Twig rendering also fails, use fallback
            self::renderFallbackErrorPage($code, $message, $context);
        }
        
        exit;
    }
    
    /**
     * Render fallback HTML error page
     */
    private static function renderFallbackErrorPage($code, $message, $context = [])
    {
        $html = "<!DOCTYPE html>
<html lang='bn'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Error {$code}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .error-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 600px;
            text-align: center;
        }
        .error-code {
            font-size: 72px;
            font-weight: bold;
            color: #dc3545;
            margin: 0;
        }
        .error-message {
            font-size: 18px;
            color: #666;
            margin: 20px 0;
        }
        .error-details {
            background: #f9f9f9;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            font-size: 13px;
            color: #555;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .back-link:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class='error-container'>
        <h1 class='error-code'>{$code}</h1>
        <p class='error-message'>{$message}</p>";
        
        if (self::$showErrors && !empty($context['exception'])) {
            $html .= "<div class='error-details'>
                <strong>Debug Info:</strong><br>
                " . htmlspecialchars($context['exception'], ENT_QUOTES, 'UTF-8') . "
            </div>";
        }
        
        if (!empty($context['route'])) {
            $html .= "<div class='error-details'>
                <strong>Route:</strong> " . htmlspecialchars($context['route'], ENT_QUOTES, 'UTF-8') . "<br>
            </div>";
        }
        
        $html .= "<a href='javascript:history.back()' class='back-link'>ফিরে যান</a>
    </div>
</body>
</html>";
        
        echo $html;
    }
    
    /**
     * Create safe Twig wrapper that catches render errors
     */
    public static function safeTwigRender($twig, $template, $context = [])
    {
        if (!($twig instanceof \Twig\Environment)) {
            throw new \RuntimeException('Twig environment not available');
        }
        
        try {
            return $twig->render($template, $context);
        } catch (\Throwable $e) {
            self::handleTwigError($e, $template);
        }
    }
    
    /**
     * Get error statistics
     */
    public static function getErrorStats()
    {
        if (!file_exists(self::$logFile)) {
            return [
                'total_errors'    => 0,
                'fatal_errors'    => 0,
                'warnings'        => 0,
                'notices'         => 0,
                'exceptions'      => 0,
                'route_errors'    => 0,
                'twig_errors'     => 0,
                'file_size_bytes' => 0,
            ];
        }
        
        $content = file_get_contents(self::$logFile);
        
        return [
            'total_errors'    => substr_count($content, '['),
            'fatal_errors'    => substr_count($content, '[FATAL '),
            'warnings'        => substr_count($content, '[WARNING]'),
            'notices'         => substr_count($content, '[NOTICE]'),
            'exceptions'      => substr_count($content, '[UNCAUGHT EXCEPTION]'),
            'route_errors'    => substr_count($content, '[ROUTE_ERROR]'),
            'twig_errors'     => substr_count($content, '[TWIG_ERROR]'),
            'file_size_bytes' => filesize(self::$logFile),
        ];
    }
    
    // ──────────────── স্তর ৫: Log Rotation ────────────────
    
    /**
     * Rotate log file when it exceeds max size
     * Call this periodically or at the start of each request
     */
    public static function rotateLog($maxSize = null, $maxFiles = null)
    {
        $maxSize  = $maxSize  ?? self::$maxLogSize;
        $maxFiles = $maxFiles ?? self::$maxLogFiles;
        
        if (!file_exists(self::$logFile)) {
            return;
        }
        
        if (filesize(self::$logFile) < $maxSize) {
            return;
        }
        
        $logDir  = dirname(self::$logFile);
        $logName = basename(self::$logFile);
        
        // Rotate existing backups (shift by 1)
        for ($i = $maxFiles - 1; $i >= 1; $i--) {
            $oldFile = $logDir . '/' . $logName . '.' . $i;
            $newFile = $logDir . '/' . $logName . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                @rename($oldFile, $newFile);
            }
        }
        
        // Rename current log → .1
        @rename(self::$logFile, $logDir . '/' . $logName . '.1');
        
        // Create fresh empty log
        @touch(self::$logFile);
        
        self::logError('LOG_ROTATION', "Log file rotated. Previous log saved as {$logName}.1", __FILE__, __LINE__);
    }
}
