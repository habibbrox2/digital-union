<?php
/**
 * controllers/errorController.php
 * 
 * Error page routes - renders error page template.
 */

global $router, $twig;

$router->get('/error', function () {
    // docError() is defined in config/error.php to handle error codes
    docError();
});
