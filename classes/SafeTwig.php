<?php

/**
 * SafeTwig Wrapper
 * 
 * Wraps Twig\Environment to automatically handle errors
 * without modifying existing code
 * 
 * Drop-in replacement for $twig variable
 * Works with existing $twig->render() calls
 */

class SafeTwig
{
    private $twig;
    private $currentTemplate = null;
    
    public function __construct(\Twig\Environment $twigEnv)
    {
        $this->twig = $twigEnv;
    }
    
    /**
     * Override render() to add automatic error handling
     */
    public function render($name, array $context = []): string
    {
        try {
            $this->currentTemplate = $name;
            return $this->twig->render($name, $context);
        } catch (\Throwable $e) {
            // Log error
            if (class_exists('ErrorHandler')) {
                ErrorHandler::handleTwigError($e, $name);
            } else {
                error_log("Twig Error ({$name}): " . $e->getMessage());
                http_response_code(500);
                echo "Template Error: {$name}";
            }
            exit;
        }
    }
    
    /**
     * Override display() to add automatic error handling
     */
    public function display($name, array $context = []): void
    {
        try {
            $this->currentTemplate = $name;
            $this->twig->display($name, $context);
        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler')) {
                ErrorHandler::handleTwigError($e, $name);
            } else {
                error_log("Twig Error ({$name}): " . $e->getMessage());
                http_response_code(500);
                echo "Template Error: {$name}";
            }
            exit;
        }
    }
    
    /**
     * Pass through all other method calls to original Twig
     */
    public function __call($method, $arguments)
    {
        return call_user_func_array([$this->twig, $method], $arguments);
    }
    
    /**
     * Pass through property access
     */
    public function __get($name)
    {
        return $this->twig->$name;
    }
    
    /**
     * Pass through property setting
     */
    public function __set($name, $value)
    {
        $this->twig->$name = $value;
    }
    
    /**
     * Get original Twig instance if needed
     */
    public function getTwig()
    {
        return $this->twig;
    }
    
    /**
     * Get current template being rendered
     */
    public function getCurrentTemplate()
    {
        return $this->currentTemplate;
    }
}
