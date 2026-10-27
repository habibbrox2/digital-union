<?php

/**
 * Safe Twig Render Helpers
 * Provides convenient functions to render templates with automatic error handling
 */

/**
 * Render a Twig template safely
 * Error is isolated - doesn't affect other routes/templates
 * 
 * @param string $template Template path (e.g., 'users/profile.twig')
 * @param array $data Template variables
 * @return void
 * 
 * @example
 *   safeTwigRender('users/list.twig', ['users' => $users]);
 */
function safeTwigRender(string $template, array $data = []): void
{
    global $twig;
    
    if (!isset($twig) || (!($twig instanceof \Twig\Environment) && !($twig instanceof SafeTwig))) {
        http_response_code(500);
        echo "<h1>500 Error</h1>";
        echo "<p>Twig environment not available</p>";
        return;
    }
    
    try {
        echo $twig->render($template, $data);
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::handleTwigError($e, $template);
        } else {
            http_response_code(500);
            echo "<h1>500 Template Error</h1>";
            echo "<p>Failed to render: {$template}</p>";
        }
    }
}

/**
 * Get rendered template as string (doesn't output)
 * Error is caught and returns null on failure
 * 
 * @param string $template Template path
 * @param array $data Template variables
 * @return string|null Rendered HTML or null on error
 * 
 * @example
 *   $html = getTwigRender('components/header.twig', ['title' => 'Home']);
 *   if ($html) echo $html;
 */
function getTwigRender(string $template, array $data = []): ?string
{
    global $twig;
    
    if (!isset($twig) || (!($twig instanceof \Twig\Environment) && !($twig instanceof SafeTwig))) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logError(
                'TWIG_ERROR',
                'Twig environment not available',
                __FILE__,
                __LINE__
            );
        }
        return null;
    }
    
    try {
        return $twig->render($template, $data);
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logError(
                'TWIG_ERROR',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                [
                    'template' => $template,
                    'route' => ErrorHandler::getCurrentRoute()
                ]
            );
        }
        return null;
    }
}

/**
 * Render multiple templates and concatenate
 * Each error is isolated from others
 * 
 * @param array $templates Array of ['template' => 'path.twig', 'data' => [...]]
 * @param bool $skipErrors If true, skip failed templates, else stop at first error
 * @return string Concatenated HTML
 * 
 * @example
 *   $html = multiTwigRender([
 *       ['template' => 'header.twig', 'data' => ['title' => 'Home']],
 *       ['template' => 'content.twig', 'data' => []],
 *       ['template' => 'footer.twig', 'data' => []],
 *   ]);
 */
function multiTwigRender(array $templates, bool $skipErrors = true): string
{
    $output = '';
    
    foreach ($templates as $item) {
        $template = $item['template'] ?? null;
        $data = $item['data'] ?? [];
        
        if (!$template) continue;
        
        $rendered = getTwigRender($template, $data);
        
        if ($rendered === null && !$skipErrors) {
            // Stop if error and skipErrors is false
            break;
        }
        
        $output .= $rendered ?? '';
    }
    
    return $output;
}

/**
 * Check if a template exists
 * 
 * @param string $template Template path
 * @return bool
 * 
 * @example
 *   if (templateExists('users/special.twig')) {
 *       safeTwigRender('users/special.twig', $data);
 *   }
 */
function templateExists(string $template): bool
{
    global $twig;
    
    if (!isset($twig) || (!($twig instanceof \Twig\Environment) && !($twig instanceof SafeTwig))) {
        return false;
    }
    
    try {
        $twig->getLoader()->getSourceContext($template);
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Render template or use fallback
 * If template fails, render fallback template instead
 * 
 * @param string $template Primary template
 * @param string $fallbackTemplate Fallback template
 * @param array $data Template variables
 * @return void
 * 
 * @example
 *   renderOrFallback(
 *       'users/profile.twig',
 *       'common/error.twig',
 *       ['user' => $user]
 *   );
 */
function renderOrFallback(string $template, string $fallbackTemplate, array $data = []): void
{
    global $twig;
    
    if (!isset($twig) || (!($twig instanceof \Twig\Environment) && !($twig instanceof SafeTwig))) {
        http_response_code(500);
        echo "<h1>500 Error</h1>";
        return;
    }
    
    try {
        echo $twig->render($template, $data);
    } catch (\Throwable $e) {
        // Log but don't exit - try fallback
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logError(
                'TWIG_ERROR_FALLBACK',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ['template' => $template]
            );
        }
        
        // Try fallback
        try {
            $data['fallback_reason'] = $e->getMessage();
            echo $twig->render($fallbackTemplate, $data);
        } catch (\Throwable $fallbackError) {
            // Both failed
            if (class_exists('ErrorHandler')) {
                ErrorHandler::handleTwigError($fallbackError, $fallbackTemplate);
            } else {
                http_response_code(500);
                echo "<h1>500 Error</h1>";
                echo "<p>Both primary and fallback templates failed</p>";
            }
        }
    }
}

/**
 * Render with conditional fallback
 * Try templates in order, use first that succeeds
 * 
 * @param array $templateOptions Array of templates to try
 * @param array $data Template variables
 * @return void
 * 
 * @example
 *   renderWithFallback([
 *       'users/profiles/admin.twig',
 *       'users/profiles/user.twig',
 *       'users/default.twig',
 *   ], $data);
 */
function renderWithFallback(array $templateOptions, array $data = []): void
{
    global $twig;
    
    if (!isset($twig) || (!($twig instanceof \Twig\Environment) && !($twig instanceof SafeTwig))) {
        http_response_code(500);
        echo "<h1>500 Error</h1>";
        return;
    }
    
    foreach ($templateOptions as $template) {
        try {
            echo $twig->render($template, $data);
            return; // Success!
        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler')) {
                ErrorHandler::logError(
                    'TWIG_ERROR_RETRY',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    ['template' => $template]
                );
            }
            // Try next template
            continue;
        }
    }
    
    // All templates failed
    http_response_code(500);
    if (function_exists('renderError')) {
        renderError(500, "কোনো টেমপ্লেট রেন্ডার করা সম্ভব হয়নি।");
    } else {
        echo "<h1>500 Error</h1>";
        echo "<p>Failed to render any template</p>";
    }
}

/**
 * Get current error information
 * 
 * @return array|null Error info if available
 * 
 * @example
 *   $errorInfo = getLastError();
 *   if ($errorInfo) {
 *       echo "Last error on route: " . $errorInfo['route'];
 *   }
 */
function getLastError(): ?array
{
    if (!class_exists('ErrorHandler')) {
        return null;
    }
    
    return [
        'route' => ErrorHandler::getCurrentRoute(),
        'stats' => ErrorHandler::getErrorStats(),
    ];
}
