<?php

// classes/Router.php



class RouteNotFoundException extends Exception {}

class MethodNotAllowedException extends Exception {}



class Router

{

    private array $routes = [

        'GET'    => [],

        'POST'   => [],

        'PUT'    => [],

        'DELETE' => [],

        'PATCH'  => [],

        'ANY'    => [],

    ];



    /**

     * Clean URI: remove query string, standardize slashes

     */

    private function cleanUri(string $uri): string

    {

        $uri = strtok($uri, '?');           // remove query string

        $uri = '/' . trim($uri, '/');       // remove leading/trailing slashes

        return ($uri === '//') ? '/' : $uri;

    }



    public function get(string $path, callable $callback): void

    {

        $this->routes['GET'][$path] = $callback;

    }



    public function post(string $path, callable $callback): void

    {

        $this->routes['POST'][$path] = $callback;

    }



    public function any(string $path, callable $callback): void

    {

        $this->routes['ANY'][$path] = $callback;

    }



    public function put(string $path, callable $callback): void

    {

        $this->routes['PUT'][$path] = $callback;

    }



    public function delete(string $path, callable $callback): void

    {

        $this->routes['DELETE'][$path] = $callback;

    }



    public function patch(string $path, callable $callback): void

    {

        $this->routes['PATCH'][$path] = $callback;

    }



    /**

     * Dispatch the request

     */

    public function dispatch(string $method, string $uri): void

    {

        $uri = $this->cleanUri($uri);

        $availableMethods = [];



        foreach ($this->routes as $httpMethod => $routesByMethod) {

            foreach ($routesByMethod as $route => $callback) {



                // Get parameter names from {param} placeholders

                preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $route, $paramNames);

                $paramNames = $paramNames[1];



                // Convert route to regex

                $routePattern = '#^' . preg_replace('/\{[a-zA-Z0-9_]+\}/', '([^/]+)', $route) . '/?$#';



                if (preg_match($routePattern, $uri, $matches)) {

                    array_shift($matches); // Remove full match



                    // Map parameters **positional** (preserve order)

                    $params = [];

                    foreach ($matches as $i => $val) {

                        $key = $paramNames[$i] ?? $i;

                        $params[] = ($key === 'type_url') ? urldecode($val) : $val;

                    }



                    if ($httpMethod === $method || $httpMethod === 'ANY') {

                        try {
                            // Set current route context in ErrorHandler
                            if (class_exists('ErrorHandler')) {
                                ErrorHandler::setCurrentRoute($method, $uri);
                            }
                            
                            call_user_func_array($callback, $params);

                        } catch (\Throwable $e) {

                            // Use ErrorHandler for isolated error handling
                            if (class_exists('ErrorHandler')) {
                                ErrorHandler::handleRouteError($e, $method, $uri);
                            } else {
                                // Fallback if ErrorHandler not available
                                error_log(
                                    "[" . date('Y-m-d H:i:s') . "] Route Error ({$method} {$uri}): "
                                    . $e->getMessage()
                                    . " in " . $e->getFile()
                                    . " on line " . $e->getLine() . "\n",
                                    3,
                                    ini_get('error_log')
                                );

                                if (!headers_sent()) {
                                    http_response_code(500);
                                }

                                if (function_exists('renderError')) {
                                    renderError(500, "এই পৃষ্ঠাটি লোড করতে সমস্যা হচ্ছে।");
                                } else {
                                    echo "<h3>500 Internal Server Error</h3>";
                                }
                            }
                        }

                        return;
                    }




                    // Record allowed methods if URI matched but method didn't

                    if (!in_array($httpMethod, $availableMethods) && $httpMethod !== 'ANY') {

                        $availableMethods[] = $httpMethod;

                    }

                }

            }

        }



        // Handle errors

        if (!empty($availableMethods)) {

            $allowedMethods = implode(', ', array_unique($availableMethods));

            header("Allow: {$allowedMethods}");

            throw new MethodNotAllowedException("Method {$method} not allowed. Allowed: {$allowedMethods}");

        }



        throw new RouteNotFoundException("Route not found: {$uri}");

    }

}

