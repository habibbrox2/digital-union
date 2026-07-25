<?php
/**
 * Scrape employee data from lgdhaka.com and save to storage/data/employee_data.php
 * Run: php scripts/scrape_employees.php
 */

$baseUrl = 'https://lgdhaka.com';
$slugs = [
    'chairman',
    'secretary',
    'computer_operator',
    'member',
    'village_police',
    'udc'
];

$allData = [];

foreach ($slugs as $slug) {
    $url = $baseUrl . '/' . $slug;
    echo "Fetching: {$url}\n";

    $html = fetchUrl($url);
    if (!$html) {
        echo "  Failed to fetch {$url}\n";
        continue;
    }

    $employees = parseEmployees($html, $slug);
    echo "  Found " . count($employees) . " employees\n";

    $allData[$slug] = $employees;
}

$dataFile = __DIR__ . '/../storage/data/employee_data.php';
if (!is_dir(dirname($dataFile))) {
    mkdir(dirname($dataFile), 0755, true);
}

$export = var_export($allData, true);
$php = "<?php\n\nreturn {$export};\n";

file_put_contents($dataFile, $php);
echo "\nSaved to: {$dataFile}\n";

function fetchUrl(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result !== false ? $result : null;
}

function parseEmployees(string $html, string $slug): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $employees = [];

    $cards = $xpath->query('//div[contains(@class,"profile-header")]');
    foreach ($cards as $card) {
        $union = '';
        $prev = $card->previousSibling;
        while ($prev) {
            if ($prev instanceof DOMElement && $prev->hasAttribute('class') && str_contains($prev->getAttribute('class'), 'col-xl-6')) {
                $h5 = $xpath->query('.//h5', $prev);
                if ($h5->length > 0) {
                    $union = trim(strip_tags($h5->item(0)->textContent));
                    break;
                }
            }
            $prev = $prev->previousSibling;
        }

        if (!$union) {
            $parent = $card->parentNode;
            while ($parent && $parent->nodeName !== 'div') {
                $parent = $parent->parentNode;
            }
            if ($parent) {
                $h5 = $xpath->query('.//h5', $parent);
                if ($h5->length > 0) {
                    $union = trim(strip_tags($h5->item(0)->textContent));
                }
            }
        }

        $nameNode = $xpath->query('.//h4/strong', $card);
        $name = $nameNode->length > 0 ? trim(strip_tags($nameNode->item(0)->textContent)) : '';

        $jobNodes = $xpath->query('.//span[contains(@class,"job_post")]', $card);
        $designation = $jobNodes->length > 0 ? trim(strip_tags($jobNodes->item(0)->textContent)) : ucfirst(str_replace('_', ' ', $slug));

        $mobile = extractLabelValue($xpath, $card, 'মোবাইল');
        $email = extractLabelValue($xpath, $card, 'ইমেইল');
        $electoralArea = extractLabelValue($xpath, $card, 'নির্বাচনী এলাকার নাম');

        $imgNode = $xpath->query('.//img', $card);
        $image = '';
        if ($imgNode->length > 0) {
            $src = $imgNode->item(0)->getAttribute('src');
            if ($src) {
                $image = $src;
            }
        }

        if ($name) {
            $employees[] = [
                'union' => $union,
                'name' => $name,
                'designation' => $designation,
                'mobile' => $mobile,
                'email' => $email,
                'image' => $image,
                'electoral_area' => $electoralArea,
            ];
        }
    }

    return $employees;
}

function extractLabelValue(DOMXPath $xpath, DOMElement $card, string $label): string
{
    $nodes = $xpath->query('.//p[contains(text(), "' . $label . '")]', $card);
    if ($nodes->length === 0) {
        return '';
    }

    $text = trim(strip_tags($nodes->item(0)->textContent));
    $prefix = $label . ' :';
    if (str_starts_with($text, $prefix)) {
        return trim(substr($text, strlen($prefix)));
    }

    $parts = explode(':', $text, 2);
    return isset($parts[1]) ? trim($parts[1]) : '';
}
