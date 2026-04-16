<?php
// /helpers/pdfGenarate.php
    function generatePdf($htmlContent, $Filename = null) {

        ob_start(); 



        $tempDir = TEMP_DIR . '/mpdf'; 

        if (!is_dir($tempDir) || !is_writable($tempDir)) {

            $tempDir = sys_get_temp_dir();

        }

        $path = __DIR__ . '/../public/assets/fonts'; 

        $fontFileSolaiman = 'SolaimanLipi.ttf';

        $fontFileLalithabai = 'Lalithabai.ttf';



        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();

        $fontDirs = $defaultConfig['fontDir'];



        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();

        $fontData = $defaultFontConfig['fontdata'];



        try {

            $mpdf = new \Mpdf\Mpdf([

                'tempDir' => $tempDir,

                'format' => [210, 297],

                'orientation' => 'P',

                'fontDir' => array_merge($fontDirs, [$path]),

                'fontdata' => $fontData + [

                    'solaimanlipi' => [

                        'R' => $fontFileSolaiman,

                        'useOTL' => 0xFF,

                    ],

                    'arial' => [

                        'R' => $fontFileLalithabai, 

                        'useOTL' => 0xFF,

                    ],

                ],

                'default_font' => 'solaimanlipi', 

                'margin_left' => 0.25,   

                'margin_right' => 0.25,  

                'margin_top' => 0.25,   

                'margin_bottom' => 0.25, 

            ]);

            $mpdf->SetDisplayMode('fullpage');

            $mpdf->SetAuthor('lgdhaka'); // Author

            $mpdf->SetTitle('Document');  // Title (optional)

            $mpdf->SetSubject('PDF Generated Document'); // Subject

            $mpdf->SetKeywords('Bangla, PDF, mPDF, Certificate, lgdhaka'); 

            $mpdf->showImageErrors = true;

            $mpdf->SetWatermarkImage('assets/watermark.png', 0.07, '', [40, 80]);

            $mpdf->showWatermarkImage = true;

            $datetime = date('Ymd_His');

            if (empty($Filename) || preg_match('/[\x{0980}-\x{09FF}]/u', $Filename)) {

                $Filename = "documents";

            }

            $finalFilename = $Filename . "_" . $datetime;



            $mpdf->AddPage();

            $mpdf->WriteHTML($htmlContent);



            ob_end_clean(); 

        $settings = getSettings();  
        $pdf_mode = $settings['pdf_output_mode'] ?? 'inline';

        if ($pdf_mode === 'download') {
            $mpdf->Output($finalFilename . ".pdf", 'D'); 
        } else {
            $mpdf->Output($finalFilename . ".pdf", 'I'); 
        }

        } catch (\Mpdf\MpdfException $e) {

            ob_end_clean();

            echo "PDF Generation Error: " . $e->getMessage();

        }

    }

function makePdf($htmlContent, $Filename = null, $footerHTML = '', $backgroundImage = '', $showWatermark = true)
{
    if (ob_get_length()) ob_end_clean();
    ob_start();
    ini_set('zlib.output_compression', 'Off');

    $TempDir = sys_get_temp_dir();
    if (!is_dir($TempDir) || !is_writable($TempDir)) {
        $TempDir = TEMP_DIR . '/mpdf';
    }

    $fontPath = __DIR__ . '/../public/assets/fonts';
    $fontSolaiman = 'SolaimanLipi.ttf';
    $fontNikosh   = 'Nikosh.ttf';
    $fontHelvetica   = 'Helvetica.ttf';

    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfig['fontDir'];

    $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defaultFontConfig['fontdata'];

    try {
        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => $TempDir,
            'format' => [210, 297],
            'orientation' => 'P',
            'fontDir' => array_merge($fontDirs, [$fontPath]),
            'fontdata' => $fontData + [
                'solaimanlipi' => ['R' => $fontSolaiman, 'useOTL' => 0xFF],
                'nikosh' => ['R' => $fontNikosh, 'useOTL' => 0xFF],
                'helvetica' => ['R' => $fontHelvetica, 'useOTL' => 0xFF],
            ],
            'default_font' => 'solaimanlipi',
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 5,
            'margin_bottom' => 5,
            'compress' => false,
        ]);

        // Optimize PDF
        $mpdf->useAdobeCJK = true;
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        //$mpdf->SetCompression(true);
        //$mpdf->restrictColorSpace = 3;

        // Metadata
        $mpdf->SetAuthor('mpdf.org');
        $mpdf->SetTitle('Document');
        $mpdf->SetSubject('PDF Document');
        $mpdf->SetKeywords('PDF, mPDF, SolaimanLipi, Nikosh, Helvetica');

        // Background Image (Full Page)
        if (!empty($backgroundImage) && file_exists($backgroundImage)) {
            $mpdf->SetDefaultBodyCSS('background', "url('$backgroundImage')");
            $mpdf->SetDefaultBodyCSS('background-image-resize', 6); // 6 = full page stretch
        }

        // Watermark Image (Optional)
        if (!empty($showWatermark)) {
            $watermarkPath = __DIR__ . '/../public/assets/watermark.png';
            if (file_exists($watermarkPath)) {
                $mpdf->SetWatermarkImage($watermarkPath, 0.05, '', [40, 80]);
                $mpdf->showWatermarkImage = true;
            }
        }

        // Safe filename
        $datetime = date('Ymd_His');
        if (empty($Filename) || preg_match('/[\x{0980}-\x{09FF}]/u', $Filename)) {
            $Filename = "document";
        }
        $finalFilename = $Filename . "_" . $datetime;

        // Add page & footer
        $mpdf->AddPage();
        if (!empty($footerHTML)) {
            $mpdf->SetHTMLFooter($footerHTML);
        }

        // Global CSS
        $css = "<style>
            body, table, p, div, span, tr, td, th {
                font-family: 'solaimanlipi', 'nikosh', Arial, sans-serif;
            }
        </style>";

        $mpdf->WriteHTML($css . $htmlContent);

        ob_end_clean();

        // Output
        $settings = function_exists('getSettings') ? getSettings() : [];
        $pdf_mode = $settings['pdf_output_mode'] ?? 'inline';

        if ($pdf_mode === 'download') {
            $mpdf->Output($finalFilename . ".pdf", 'D');
        } else {
            $mpdf->Output($finalFilename . ".pdf", 'I');
        }

    } catch (\Mpdf\MpdfException $e) {
        if (ob_get_length()) ob_end_clean();
        echo "PDF Generation Error: " . $e->getMessage();
        error_log("PDF Error: " . $e->getMessage());
    }
}



function birthPdf($htmlContent, $Filename = null, $footerHTML = '', $backgroundImage = '', $showWatermark = true, $watermarkOpacity = 0.08)
{
    if (ob_get_length()) ob_end_clean();
    ob_start();
    ini_set('zlib.output_compression', 'Off');

    // Temp directory
    $TempDir = sys_get_temp_dir();
    if (!is_dir($TempDir) || !is_writable($TempDir)) {
        $TempDir = TEMP_DIR . '/mpdf';
    }

    // Fonts
    $fontPath = __DIR__ . '/../public/assets/fonts';
    $fontSolaiman = 'SolaimanLipi.ttf';
    $fontNikosh   = 'Nikosh.ttf';
    $fontHelvetica= 'Helvetica.ttf';

    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfig['fontDir'];

    $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defaultFontConfig['fontdata'];

    try {
        // Initialize mPDF
        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => $TempDir,
            'format' => [210, 297],
            'orientation' => 'P',
            'fontDir' => array_merge($fontDirs, [$fontPath]),
            'fontdata' => $fontData + [
                'solaimanlipi' => ['R' => $fontSolaiman, 'useOTL' => 0xFF],
                'nikosh' => ['R' => $fontNikosh, 'useOTL' => 0xFF],
                'helvetica' => ['R' => $fontHelvetica, 'useOTL' => 0xFF],
            ],
            'default_font' => 'solaimanlipi',
            'dpi' => 96,
            'enableFontSubsetting' => false, // disable per-character font subset
            'useSubstitutions' => false, // skip fallback font checks
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'compress' => false,
        ]);

        // Metadata
        $mpdf->SetAuthor('Office of the Registrar, Birth and Death Registration');
        $mpdf->SetTitle('Birth Certificate');
        $mpdf->SetSubject('Official Birth Certificate of Bangladesh');
        $mpdf->SetKeywords('Birth Certificate, BDRIS, Government of Bangladesh');

        //$mpdf->SetDisplayMode('fullpage');
        //$mpdf->debug = true;

        // Background Image
        if (!empty($backgroundImage) && is_readable($backgroundImage)) {
            $mpdf->SetDefaultBodyCSS('background', "url('$backgroundImage')");
            $mpdf->SetDefaultBodyCSS('background-repeat', 'no-repeat');
            $mpdf->SetDefaultBodyCSS('background-position', 'center center');
            $mpdf->SetDefaultBodyCSS('background-image-resize', 6); // proportional full page
        }

        // Watermark logic
        if ($showWatermark !== false) {
            $watermarkPath = '';
            if ($showWatermark === true) {
                $watermarkPath = __DIR__ . '/../public/assets/watermark.png'; // default
            } elseif (is_string($showWatermark)) {
                $watermarkPath = $showWatermark;
            }

            if (!empty($watermarkPath) && is_readable($watermarkPath)) {
                $mpdf->SetWatermarkImage($watermarkPath, $watermarkOpacity); // default 0.08
                $mpdf->showWatermarkImage = true;
            }
        }

        // Safe filename
        $datetime = date('Ymd');
        if (empty($Filename) || preg_match('/[\x{0980}-\x{09FF}]/u', $Filename)) {
            $Filename = "document";
        }
        $finalFilename = $Filename . "_" . $datetime;

        // Add page and footer
        $mpdf->AddPage();
        if (!empty($footerHTML)) {
            $mpdf->SetHTMLFooter($footerHTML);
        }

        // Global CSS (Bengali fonts)
        $css = "<style>
            body, table, p, div, span, tr, td, th {
                font-family: 'solaimanlipi', 'helvetica', 'nikosh', sans-serif;
            }
        </style>";

        $mpdf->WriteHTML($css . $htmlContent);

        ob_end_clean();

        // Output PDF
        $settings = function_exists('getSettings') ? getSettings() : [];
        $pdf_mode = $settings['pdf_output_mode'] ?? 'inline';

        if ($pdf_mode === 'download') {
            $mpdf->Output($finalFilename . ".pdf", 'D');
        } else {
            $mpdf->Output($finalFilename . ".pdf", 'I');
        }

    } catch (\Mpdf\MpdfException $e) {
        if (ob_get_length()) ob_end_clean();
        echo "PDF Generation Error: " . $e->getMessage();
        error_log("PDF Error: " . $e->getMessage());
    }
}


