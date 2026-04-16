<?php
// controllers/HomeController.php

// Inline route controller: render home page
global $router;
$router->get('/', function () use ($twig) {
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

    } catch (Throwable $e) {
        error_log('HomeController Error: ' . $e->getMessage());
        renderError(500, 'সার্ভার ত্রুটি: হোম পৃষ্ঠাটি লোড করা যায়নি।');
    }
});
