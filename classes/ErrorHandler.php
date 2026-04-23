<?php

/**
 * ErrorHandler Class
 * Handles all application errors with isolated error handling per route/template
 * - Logs errors to error.log
 * - Shows error page only for that specific route/template
 * - Doesn't affect other routes/templates
 */

class ErrorHandler
{
    private static $logFile;
    private static $showErrors = false;
    private static $twig = null;
    private static $routeStartTime = null;
    private static $currentRoute = null;
    
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
        $route = self::$currentRoute ?? 'system';
        
        $logMessage = "[{$timestamp}] [{$type}] Route: {$route}\n";
        $logMessage .= "Message: {$message}\n";
        $logMessage .= "File: {$file} (Line: {$line})\n";
        
        if (!empty($context)) {
            $logMessage .= "Context: " . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        $logMessage .= "---\n";
        
        if (!@error_log($logMessage, 3, self::$logFile)) {
            $fallback = dirname(self::$logFile) . '/bdris_log.txt';
            @file_put_contents($fallback, $logMessage, FILE_APPEND);
        }
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
        self::logError(
            'FATAL_ERROR',
            $error['message'],
            $error['file'],
            $error['line'],
            [
                'route' => self::$currentRoute ?? 'system',
                'type' => self::getFatalErrorType($error['type'])
            ]
        );
        
        if (!headers_sent()) {
            http_response_code(500);
        }
        
        if (ob_get_length() === 0) {
            self::renderErrorPage(500, "সার্ভার ত্রুটি: একটি মারাত্মক সমস্যা হয়েছে।", [
                'exception' => self::$showErrors ? $error['message'] : null
            ]);
        }
    }
    
    /**
     * Get fatal error type name
     */
    private static function getFatalErrorType($type)
    {
        $types = [
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        ];
        return $types[$type] ?? 'UNKNOWN';
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
                'total_errors' => 0,
                'route_errors' => 0,
                'twig_errors' => 0,
                'fatal_errors' => 0,
            ];
        }
        
        $content = file_get_contents(self::$logFile);
        
        return [
            'total_errors' => substr_count($content, '['),
            'route_errors' => substr_count($content, '[ROUTE_ERROR]'),
            'twig_errors' => substr_count($content, '[TWIG_ERROR]'),
            'fatal_errors' => substr_count($content, '[FATAL_ERROR]'),
        ];
    }
}
