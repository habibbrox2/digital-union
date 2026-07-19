<?php
/**
 * controllers/HomeController.php
 * 
 * Public pages controller - pure closures using HomeService.
 * No inline data processing or helper function definitions.
 * All logic is in modules/Services/HomeService.php.
 */

global $router, $twig;

$homeService = new HomeService();
$pageConfigs = $homeService->getPageConfigs();

// GET : Home page
$router->get('/', function() use ($twig) {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($twig) || (!($twig instanceof \Twig\Environment) && !($twig instanceof SafeTwig))) {
            renderError(500, 'Twig environment not available.');
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $twig->render('public.twig', [
            'title' => 'Home',
            'header_title' => 'Home'
        ]);
    } catch (\Throwable $e) {
        error_log('HomeController Error: ' . $e->getMessage());
        renderError(500, 'সার্ভার ত্রুটি: হোম পৃষ্ঠাটি লোড করা যায়নি।');
    }
});

// GET : Employee listing pages (chairman, secretary, etc.)
foreach ($pageConfigs as $slug => $config) {
    $router->get('/' . $slug, function() use ($twig, $slug, $config, $homeService) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $employees = $homeService->getEmployeeData($slug);

            $pageData = [
                'page_title' => $config['title'],
                'page_subtitle' => $config['subtitle'],
                'page_slug' => $slug,
                'employees' => $employees
            ];

            header('Content-Type: text/html; charset=UTF-8');
            echo $twig->render('public/static-page.twig', $pageData);
        } catch (\Throwable $e) {
            error_log('Public Page Error (' . $slug . '): ' . $e->getMessage());
            renderError(500, 'সার্ভার ত্রুটি: পৃষ্ঠাটি লোড করা যায়নি।');
        }
    });
}
