<?php
// config/twig.php

require_once __DIR__ . '/../classes/TwigManager.php';
require_once __DIR__ . '/../classes/SafeTwig.php';
//require_once __DIR__ . '/functions.php'; 

$settings = getSettings();

// maintenance_mode = 1 is Twig cache enable
$enableCache = !empty($settings['maintenance_mode'])
    && (int)$settings['maintenance_mode'] === 1;

$twigManager = new TwigManager($mysqli, $enableCache);
$originalTwig = $twigManager->getTwig();

// Pass Twig to ErrorHandler if available
if (class_exists('ErrorHandler')) {
    ErrorHandler::init(ini_get('error_log'), false, $originalTwig);
}

// Wrap Twig with SafeTwig for automatic error handling
// This way $twig->render() works exactly as before but with error handling
$twig = new SafeTwig($originalTwig);

return $twig;






    function trySetCertificateTypeFromURL()

    {

        global $mysqli, $twig;



        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';

        $path = parse_url($request_uri, PHP_URL_PATH);

        $uri_parts = explode('/', trim($path, '/'));



        if (empty($uri_parts)) return false;



        foreach (array_reverse($uri_parts) as $segment) {

            if (empty($segment)) continue;



            $decoded_segment = urldecode($segment);

            $lower_segment = strtolower($decoded_segment);

            $base_segment = preg_replace('/_(bn|en|bl)$/i', '', $lower_segment);

            $normalized_segment = preg_replace('/[^a-zA-Z0-9\p{Bengali}-]+/u', '', $base_segment);

            $stmt = $mysqli->prepare("

                SELECT slug, name_bn, name_en, name_bl

                FROM term_translations

                WHERE LOWER(REPLACE(REPLACE(REPLACE(REPLACE(name_bl, ' ', '-'), '/', '-'), '_', '-'), '.', '')) = ?

                LIMIT 1

            ");
                

            if (!$stmt) return false;



            $stmt->bind_param("s", $normalized_segment);

            $stmt->execute();

            $result = $stmt->get_result();

            $row = $result->fetch_assoc();

            $stmt->close();



            // 🔍 name_bl না মিললে slug মিলিয়ে দেখা


            if (!$row) {

                $stmt2 = $mysqli->prepare("

                    SELECT slug, name_bn, name_en, name_bl

                    FROM term_translations

                    WHERE slug = ?

                    LIMIT 1

                ");

                if (!$stmt2) return false;



                $stmt2->bind_param("s", $normalized_segment);

                $stmt2->execute();

                $result2 = $stmt2->get_result();

                $row = $result2->fetch_assoc();

                $stmt2->close();

            }



            // ✅ পেলে Twig Global এ সেট করুন

            if ($row) {

                $go_url = strtolower(str_replace([' ', '/', '_', '.'], '-', $row['name_bl']));

                $url_path = $row['slug'];



                $twig->addGlobal('certificate_type', $row['slug']);

                $twig->addGlobal('certificate_type_bn', $row['name_bn']);

                $twig->addGlobal('certificate_type_en', $row['name_en']);

                $twig->addGlobal('certificate_type_bl', $row['name_bl']);

                $twig->addGlobal('url_path', $url_path);

                $twig->addGlobal('go_url', $go_url);



                return true;

            }

        }



        return false;

    }

















