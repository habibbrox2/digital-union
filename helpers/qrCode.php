<?php
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\BarcodeGeneratorPNG;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Generate QR Code (government standard, clean & scanable)
 *
 * @param string $data Data to encode (URL, ID, hash)
 * @param string|null $outputFile Save path (optional)
 * @param int $scale QR block size (10 recommended for print)
 * @return string Raw PNG data or file path
 */
function generate_qr(string $data, ?string $outputFile = null, int $scale = 10): string
{
    $options = new QROptions([
        'scale'       => $scale,               // block size, higher for print
        'outputType'  => QRCode::OUTPUT_IMAGE_PNG,
        'imageBase64' => false,
        'qrColor'     => [0,0,0],              // black
        'bgColor'     => [255,255,255],        // white
        'eccLevel'    => QRCode::ECC_Q,        // highest error correction
        'margin'      => 4,                     // 4 block quiet zone
        // version auto → QR auto size
    ]);

    $qr = new QRCode($options);
    $pngData = $qr->render($data);

    if ($outputFile) {
        file_put_contents($outputFile, $pngData);
        return realpath($outputFile);
    }

    return $pngData;
}

/**
 * Generate Barcode (government standard, CODE_128 recommended)
 *
 * @param string $data Barcode data
 * @param string|null $outputFile Save path (optional)
 * @param string $type Barcode type (default CODE_128)
 * @param float $scale Width multiplier (2–3 recommended)
 * @param int $height Height in px (50–80 recommended)
 * @return string Raw PNG data or file path
 */
function generate_barcode(
    string $data,
    ?string $outputFile = null,
    string $type = 'CODE_128',
    float $scale = 2.5,
    int $height = 60
): string {
    $generator = new BarcodeGeneratorPNG();

    $types = [
        'CODE_128' => $generator::TYPE_CODE_128,
        'CODE_39'  => $generator::TYPE_CODE_39,
        'EAN_13'   => $generator::TYPE_EAN_13,
        'EAN_8'    => $generator::TYPE_EAN_8,
        'UPC_A'    => $generator::TYPE_UPC_A,
    ];

    $barcodeType = $types[$type] ?? $generator::TYPE_CODE_128;
    $pngData = $generator->getBarcode($data, $barcodeType, (int) round($scale), $height);

    if ($outputFile) {
        file_put_contents($outputFile, $pngData);
        return realpath($outputFile);
    }

    return $pngData;
}
