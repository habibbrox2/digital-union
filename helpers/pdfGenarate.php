<?php
// /helpers/pdfGenarate.php

// ═══════════════════════════════════════════════════════════════
// SHARED HELPERS — used by all three PDF functions below
// ═══════════════════════════════════════════════════════════════

/**
 * Create a configured mPDF instance with Bangla font support.
 * All PDF functions use this internally to eliminate duplication.
 *
 * @param array $options  Overrides for the default mPDF config array
 * @return \Mpdf\Mpdf
 */
function createMpdf(array $options = []): \Mpdf\Mpdf
{
    // --- Temp Directory ---
    // Use project-local storage/tmp/mpdf instead of system temp (/tmp/mpdf)
    // to ensure consistent permissions and avoid stale font cache issues.
    $tempDir = __DIR__ . '/../storage/tmp/mpdf';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0777, true);
    }
    // Always ensure the ttfontdata subdirectory exists for mPDF font cache
    $ttfDir = $tempDir . '/ttfontdata';
    if (!is_dir($ttfDir)) {
        @mkdir($ttfDir, 0777, true);
    }
    // Fallback: if our preferred dir isn't usable, try system temp
    if (!is_dir($tempDir) || !is_writable($tempDir)) {
        $fallback = sys_get_temp_dir() . '/mpdf';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0777, true);
        }
        // Ensure ttfontdata in fallback too
        $fallbackTtf = $fallback . '/ttfontdata';
        if (!is_dir($fallbackTtf)) {
            @mkdir($fallbackTtf, 0777, true);
        }
        if (is_dir($fallback) && is_writable($fallback)) {
            $tempDir = $fallback;
        } else {
            $tempDir = defined('TEMP_DIR') ? TEMP_DIR . '/mpdf' : sys_get_temp_dir();
        }
    }

    // --- Fonts ---
    $fontPath = __DIR__ . '/../public/assets/fonts';

    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfig['fontDir'];

    $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defaultFontConfig['fontdata'];

    // --- Default mPDF config ---
    $defaults = [
        'tempDir'      => $tempDir,
        'format'       => [210, 297],
        'orientation'  => 'P',
        'fontDir'      => array_merge($fontDirs, [$fontPath]),
        'fontdata'     => $fontData + [
            'solaimanlipi' => ['R' => 'SolaimanLipi.ttf', 'useOTL' => 0xFF],
            'nikosh'       => ['R' => 'Nikosh.ttf',       'useOTL' => 0xFF],
            'helvetica'    => ['R' => 'Helvetica.ttf',     'useOTL' => 0xFF],
        ],
        'default_font' => 'solaimanlipi',
        'margin_left'   => 5,
        'margin_right'  => 5,
        'margin_top'    => 5,
        'margin_bottom' => 5,
    ];

    $config = array_merge($defaults, $options);

    $mpdf = new \Mpdf\Mpdf($config);

    // --- Consistent Bangla-complex-script settings ---
    $mpdf->autoScriptToLang = true;   // auto-detect Bengali script
    $mpdf->autoLangToFont   = true;   // map Bengali → solaimanlipi
    $mpdf->useSubstitutions = true;   // enable conjunct shaping (যুক্তাক্ষর)

    return $mpdf;
}

/**
 * Sanitize a filename for PDF output.
 * Falls back to 'document' if the name is empty or contains Bangla characters.
 */
function getSafeFilename($filename): string
{
    if (empty($filename) || preg_match('/[\x{0980}-\x{09FF}]/u', $filename)) {
        return 'document';
    }
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename);
}

/**
 * Output a completed mPDF document to the browser
 * (inline or download, based on settings).
 */
function outputPdf(\Mpdf\Mpdf $mpdf, string $filename): void
{
    $settings = function_exists('getSettings') ? getSettings() : [];
    $pdf_mode = $settings['pdf_output_mode'] ?? 'inline';

    if ($pdf_mode === 'download') {
        $mpdf->Output($filename . '.pdf', 'D');
    } else {
        $mpdf->Output($filename . '.pdf', 'I');
    }
}


// ═══════════════════════════════════════════════════════════════
// PUBLIC FUNCTIONS — kept backward-compatible signatures
// ═══════════════════════════════════════════════════════════════

/**
 * Generate a simple single-page PDF (used for Application Copy).
 *
 * @param string $htmlContent  Fully rendered HTML
 * @param string|null $Filename  Desired output filename (without .pdf)
 */
function generatePdf($htmlContent, $Filename = null)
{
    ob_start();

    try {
        $mpdf = createMpdf([
            'margin_left'   => 5,
            'margin_right'  => 5,
            'margin_top'    => 5,
            'margin_bottom' => 5,
        ]);

        $mpdf->SetDisplayMode('fullpage');
        $mpdf->SetAuthor('lgdhaka');
        $mpdf->SetTitle('Document');
        $mpdf->SetSubject('PDF Generated Document');
        $mpdf->SetKeywords('Bangla, PDF, mPDF, Certificate, lgdhaka');
        $mpdf->showImageErrors = true;

        // Watermark for application copy
        $watermarkPath = __DIR__ . '/../public/assets/watermark.png';
        if (file_exists($watermarkPath)) {
            $mpdf->SetWatermarkImage($watermarkPath, 0.07, '', [40, 80]);
            $mpdf->showWatermarkImage = true;
        }

        $finalFilename = getSafeFilename($Filename);

        $mpdf->AddPage();
        $mpdf->WriteHTML($htmlContent);

        ob_end_clean();
        outputPdf($mpdf, $finalFilename);

    } catch (\Mpdf\MpdfException $e) {
        ob_end_clean();
        echo 'PDF Generation Error: ' . $e->getMessage();
    }
}

/**
 * Generate a certificate PDF with optional footer, background image, and watermark.
 *
 * @param string $htmlContent      Fully rendered HTML
 * @param string|null $Filename    Desired output filename (without .pdf)
 * @param string $footerHTML       Optional HTML footer
 * @param string $backgroundImage  Optional path to background image
 * @param bool   $showWatermark    Whether to show the default watermark
 */
function makePdf($htmlContent, $Filename = null, $footerHTML = '', $backgroundImage = '', $showWatermark = true)
{
    if (ob_get_length()) ob_end_clean();
    ob_start();
    ini_set('zlib.output_compression', 'Off');

    try {
        $mpdf = createMpdf([
            'compress' => false,
        ]);

        $mpdf->SetAuthor('lgdhaka');
        $mpdf->SetTitle('Document');
        $mpdf->SetSubject('PDF Document');
        $mpdf->SetKeywords('PDF, mPDF, SolaimanLipi, Nikosh, Helvetica');

        // Background Image (Full Page)
        if (!empty($backgroundImage) && file_exists($backgroundImage)) {
            $mpdf->SetDefaultBodyCSS('background', "url('$backgroundImage')");
            $mpdf->SetDefaultBodyCSS('background-image-resize', 6);
        }

        // Watermark
        if (!empty($showWatermark)) {
            $watermarkPath = __DIR__ . '/../public/assets/watermark.png';
            if (file_exists($watermarkPath)) {
                $mpdf->SetWatermarkImage($watermarkPath, 0.05, '', [40, 80]);
                $mpdf->showWatermarkImage = true;
            }
        }

        $finalFilename = getSafeFilename($Filename);

        $mpdf->AddPage();
        if (!empty($footerHTML)) {
            $mpdf->SetHTMLFooter($footerHTML);
        }

        // Global CSS ensuring Bangla fonts apply to all elements
        $css = '<style>
            body, table, p, div, span, tr, td, th {
                font-family: "solaimanlipi", "nikosh", sans-serif;
            }
        </style>';
        $mpdf->WriteHTML($css . $htmlContent);

        ob_end_clean();
        outputPdf($mpdf, $finalFilename);

    } catch (\Mpdf\MpdfException $e) {
        if (ob_get_length()) ob_end_clean();
        echo 'PDF Generation Error: ' . $e->getMessage();
        error_log('PDF Error: ' . $e->getMessage());
    }
}

/**
 * Generate a birth/death certificate PDF with dedicated metadata, background, and watermark opacity.
 *
 * @param string $htmlContent        Fully rendered HTML
 * @param string|null $Filename      Desired output filename (without .pdf)
 * @param string $footerHTML         Optional HTML footer
 * @param string $backgroundImage    Optional path to background image
 * @param bool|string $showWatermark true=default, false=disable, or a custom path string
 * @param float  $watermarkOpacity   Opacity of watermark image (default 0.08)
 */
function birthPdf($htmlContent, $Filename = null, $footerHTML = '', $backgroundImage = '', $showWatermark = true, $watermarkOpacity = 0.08)
{
    if (ob_get_length()) ob_end_clean();
    ob_start();
    ini_set('zlib.output_compression', 'Off');

    try {
        $mpdf = createMpdf([
            'dpi'                   => 96,
            'enableFontSubsetting'  => false,
            'compress'              => false,
            'margin_left'   => 10,
            'margin_right'  => 10,
            'margin_top'    => 10,
            'margin_bottom' => 10,
        ]);

        $mpdf->SetAuthor('Office of the Registrar, Birth and Death Registration');
        $mpdf->SetTitle('Birth Certificate');
        $mpdf->SetSubject('Official Birth Certificate of Bangladesh');
        $mpdf->SetKeywords('Birth Certificate, BDRIS, Government of Bangladesh');

        // Background Image
        if (!empty($backgroundImage) && is_readable($backgroundImage)) {
            $mpdf->SetDefaultBodyCSS('background', "url('$backgroundImage')");
            $mpdf->SetDefaultBodyCSS('background-repeat', 'no-repeat');
            $mpdf->SetDefaultBodyCSS('background-position', 'center center');
            $mpdf->SetDefaultBodyCSS('background-image-resize', 6);
        }

        // Watermark — supports true/false or a custom path string
        if ($showWatermark !== false) {
            $watermarkPath = '';
            if ($showWatermark === true) {
                $watermarkPath = __DIR__ . '/../public/assets/watermark.png';
            } elseif (is_string($showWatermark)) {
                $watermarkPath = $showWatermark;
            }
            if (!empty($watermarkPath) && is_readable($watermarkPath)) {
                $mpdf->SetWatermarkImage($watermarkPath, $watermarkOpacity);
                $mpdf->showWatermarkImage = true;
            }
        }

        $finalFilename = getSafeFilename($Filename);

        $mpdf->AddPage();
        if (!empty($footerHTML)) {
            $mpdf->SetHTMLFooter($footerHTML);
        }

        // Global CSS ensuring Bangla fonts apply to all elements
        $css = '<style>
            body, table, p, div, span, tr, td, th {
                font-family: "solaimanlipi", "helvetica", "nikosh", sans-serif;
            }
        </style>';
        $mpdf->WriteHTML($css . $htmlContent);

        ob_end_clean();
        outputPdf($mpdf, $finalFilename);

    } catch (\Mpdf\MpdfException $e) {
        if (ob_get_length()) ob_end_clean();
        echo 'PDF Generation Error: ' . $e->getMessage();
        error_log('PDF Error: ' . $e->getMessage());
    }
}
