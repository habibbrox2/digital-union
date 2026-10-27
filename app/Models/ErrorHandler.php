<?php

/**
 * ErrorHandler Class
 * Handles all application errors with isolated error handling per route/template
 * - Logs errors to separate files based on type (route, twig, fatal, 404, HTTP, PHP)
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
 *
 * Log Files:
 *   error.log          → PHP runtime errors (warnings, notices, deprecations)
 *   route-error.log    → ROUTE_ERROR (500 route callback failures)
 *   twig-error.log     → TWIG_ERROR (template rendering failures)
 *   fatal-error.log    → FATAL_* and UNCAUGHT EXCEPTION
 *   route-404.log      → RouteNotFoundException (404 pages)
 *   http-error.log     → renderError() manual 400/403/404/500 calls
 */

class ErrorHandler
{
    /** @var string Main PHP runtime error log */
    private static $logFile;
    
    /** @var string Route callback errors (500) */
    private static $routeErrorLog;
    
    /** @var string Twig template rendering errors */
    private static $twigErrorLog;
    
    /** @var string Fatal errors & uncaught exceptions */
    private static $fatalErrorLog;
    
    /** @var string 404 route not found */
    private static $route404Log;
    
    /** @var string HTTP error pages (renderError) */
    private static $httpErrorLog;
    
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
     *
     * @param string|null $logFilePath    Main PHP runtime error log path
     * @param bool        $showErrors     Whether to show detailed errors
     * @param object|null $twig           Twig environment instance
     * @param array       $logFiles       Associative array of custom log file paths:
     *                                    ['route' => ..., 'twig' => ..., 'fatal' => ..., '404' => ..., 'http' => ...]
     */
    public static function init($logFilePath = null, $showErrors = false, $twig = null, $logFiles = [])
    {
        self::$logFile = $logFilePath ?? ini_get('error_log');
        self::$showErrors = $showErrors;
        self::$twig = $twig;
        
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
        
        // Set up separate log file paths (fallback to main log if not specified)
        $logNames = [
            'route' => 'route-error.log',
            'twig'  => 'twig-error.log',
            'fatal' => 'fatal-error.log',
            '404'   => 'route-404.log',
            'http'  => 'http-error.log',
        ];
        
        $map = [
            'route' => &self::$routeErrorLog,
            'twig'  => &self::$twigErrorLog,
            'fatal' => &self::$fatalErrorLog,
            '404'   => &self::$route404Log,
            'http'  => &self::$httpErrorLog,
        ];
        
        foreach ($map as $key => &$target) {
            $target = isset($logFiles[$key])
                ? $logFiles[$key]
                : $logDir . '/' . $logNames[$key];
            
            // Ensure the file exists (and the directory)
            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!file_exists($target)) {
                @touch($target);
            }
        }
        unset($target);
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
     *
     * @param string $type            Error type identifier (e.g. ROUTE_ERROR, TWIG_ERROR)
     * @param string $message         Error description
     * @param string $file            Source file where the error occurred
     * @param int    $line            Line number in source file
     * @param array  $context         Additional context data
     * @param string|null $logFile    Optional custom log file. If null, auto-selects based on type.
     */
    public static function logError($type, $message, $file, $line, $context = [], $logFile = null)
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
        
        // Determine which log file to use
        $targetLog = $logFile ?? self::resolveLogFile($type);
        
        if (!@error_log($logMessage, 3, $targetLog)) {
            $fallback = dirname(self::$logFile) . '/bdris_log.txt';
            @file_put_contents($fallback, $logMessage, FILE_APPEND);
        }
    }
    
    /**
     * Resolve the appropriate log file path based on error type
     */
    private static function resolveLogFile($type)
    {
        $type = strtoupper($type);
        
        if (strpos($type, 'ROUTE_ERROR') === 0) {
            return self::$routeErrorLog ?? self::$logFile;
        }
        if (strpos($type, 'TWIG_ERROR') === 0) {
            return self::$twigErrorLog ?? self::$logFile;
        }
        if (strpos($type, 'FATAL_') === 0 || strpos($type, 'UNCAUGHT') === 0) {
            return self::$fatalErrorLog ?? self::$logFile;
        }
        if (strpos($type, 'ROUTE_404') === 0) {
            return self::$route404Log ?? self::$logFile;
        }
        if (strpos($type, 'HTTP_ERROR') === 0 || strpos($type, 'HTTP_') === 0) {
            return self::$httpErrorLog ?? self::$logFile;
        }
        
        // Default to main log for warnings, notices, deprecations, etc.
        return self::$logFile;
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
     * Logged to: route-error.log
     */
    public static function handleRouteError(\Throwable $exception, $method = null, $uri = null)
    {
        $route = ($method !== null && $uri !== null) ? "{$method} {$uri}" : (self::$currentRoute ?? 'unknown route');
        
        // Log the error to route-error.log (dedicated file for route failures)
        self::logError(
            'ROUTE_ERROR',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            [
                'route' => $route,
                'trace' => $exception->getTraceAsString()
            ],
            self::$routeErrorLog
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
     * Logged to: twig-error.log
     */
    public static function handleTwigError(\Throwable $exception, $templateName = null)
    {
        $template = $templateName ?? 'unknown template';
        
        // Log the error to twig-error.log
        self::logError(
            'TWIG_ERROR',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            [
                'template' => $template,
                'route' => self::$currentRoute ?? 'unknown',
                'trace' => $exception->getTraceAsString()
            ],
            self::$twigErrorLog
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
     * Logged to: fatal-error.log
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
            ],
            self::$fatalErrorLog
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
     * Logged to: fatal-error.log
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
            ],
            self::$fatalErrorLog
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
     * Log a 404 route-not-found error to route-404.log
     */
    public static function logRoute404($method, $uri)
    {
        $route = "{$method} {$uri}";
        $referer = $_SERVER['HTTP_REFERER'] ?? 'Direct access';
        
        self::logError(
            'ROUTE_404',
            "Route not found: {$route}",
            __FILE__,
            __LINE__,
            [
                'method'  => $method,
                'uri'     => $uri,
                'referer' => $referer,
                'query'   => $_SERVER['QUERY_STRING'] ?? '',
            ],
            self::$route404Log
        );
    }
    
    /**
     * Log HTTP error pages (renderError calls) to http-error.log
     */
    public static function logHttpError($code, $message)
    {
        $route = self::$currentRoute ?? self::detectRoute();
        
        self::logError(
            'HTTP_ERROR_' . $code,
            $message,
            __FILE__,
            __LINE__,
            [
                'http_code' => $code,
                'route'     => $route,
            ],
            self::$httpErrorLog
        );
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
     * Get all log file paths keyed by type
     */
    private static function getAllLogFiles(): array
    {
        return [
            'main'  => self::$logFile,
            'route' => self::$routeErrorLog,
            'twig'  => self::$twigErrorLog,
            'fatal' => self::$fatalErrorLog,
            '404'   => self::$route404Log,
            'http'  => self::$httpErrorLog,
        ];
    }
    
    /**
     * Get aggregated error statistics across all log files
     *
     * @return array Keys: total_errors, fatal_errors, warnings, notices,
     *               exceptions, route_errors, twig_errors, http_errors,
     *               route_404_errors, file_size_bytes
     */
    public static function getErrorStats(): array
    {
        $logFiles = self::getAllLogFiles();
        
        $stats = [
            'total_errors'     => 0,
            'fatal_errors'     => 0,
            'warnings'         => 0,
            'notices'          => 0,
            'exceptions'       => 0,
            'route_errors'     => 0,
            'twig_errors'      => 0,
            'http_errors'      => 0,
            'route_404_errors' => 0,
            'file_size_bytes'  => 0,
        ];
        
        foreach ($logFiles as $key => $logPath) {
            if (!$logPath || !file_exists($logPath)) {
                continue;
            }
            
            $size = @filesize($logPath);
            $stats['file_size_bytes'] += ($size !== false) ? $size : 0;
            
            $content = @file_get_contents($logPath);
            if ($content === false || $content === '') {
                continue;
            }
            
            // Count complete log entries — each entry ends with "---\n" (written by logError)
            $entryCount = substr_count($content, "---\n");
            $stats['total_errors'] += $entryCount;
            
            switch ($key) {
                case 'main':
                    $stats['warnings'] += substr_count($content, '[WARNING]');
                    $stats['notices']  += substr_count($content, '[NOTICE]');
                    break;
                case 'route':
                    $stats['route_errors'] += substr_count($content, '[ROUTE_ERROR]');
                    break;
                case 'twig':
                    $stats['twig_errors'] += substr_count($content, '[TWIG_ERROR]');
                    break;
                case 'fatal':
                    $stats['fatal_errors'] += substr_count($content, '[FATAL_');
                    $stats['exceptions']   += substr_count($content, '[UNCAUGHT EXCEPTION]');
                    break;
                case '404':
                    $stats['route_404_errors'] += substr_count($content, '[ROUTE_404]');
                    break;
                case 'http':
                    $stats['http_errors'] += substr_count($content, '[HTTP_ERROR');
                    break;
                default:
                    break;
            }
        }
        
        return $stats;
    }
    
    // ──────────────── স্তর ৫: Log Rotation ────────────────
    
    /**
     * Rotate a specific log file when it exceeds max size
     */
    private static function rotateSingleLog($logPath, $maxSize, $maxFiles)
    {
        if (!file_exists($logPath)) {
            return;
        }
        
        if (filesize($logPath) < $maxSize) {
            return;
        }
        
        $logDir  = dirname($logPath);
        $logName = basename($logPath);
        
        // Rotate existing backups (shift by 1)
        for ($i = $maxFiles - 1; $i >= 1; $i--) {
            $oldFile = $logDir . '/' . $logName . '.' . $i;
            $newFile = $logDir . '/' . $logName . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                @rename($oldFile, $newFile);
            }
        }
        
        // Rename current log → .1
        @rename($logPath, $logDir . '/' . $logName . '.1');
        
        // Create fresh empty log
        @touch($logPath);
    }
    
    /**
     * Rotate all log files when they exceed max size
     * Call this periodically or at the start of each request
     */
    public static function rotateLog($maxSize = null, $maxFiles = null)
    {
        $maxSize  = $maxSize  ?? self::$maxLogSize;
        $maxFiles = $maxFiles ?? self::$maxLogFiles;
        
        $logFiles = [
            self::$logFile,
            self::$routeErrorLog,
            self::$twigErrorLog,
            self::$fatalErrorLog,
            self::$route404Log,
            self::$httpErrorLog,
        ];
        
        foreach ($logFiles as $logPath) {
            if ($logPath && file_exists($logPath)) {
                self::rotateSingleLog($logPath, $maxSize, $maxFiles);
            }
        }
    }
}
