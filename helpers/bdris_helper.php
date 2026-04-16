<?php
/**
 * helpers/bdris_helper.php
 */

/**
 * Convert number to Bengali words (handles up to 9999)
 */
if (!function_exists('numberToWordsBn')) {
    function numberToWordsBn($number): string {
        $words = [
            0=>'শূন্য',1=>'এক',2=>'দুই',3=>'তিন',4=>'চার',5=>'পাঁচ',6=>'ছয়',7=>'সাত',8=>'আট',9=>'নয়',
            10=>'দশ',11=>'এগারো',12=>'বারো',13=>'তেরো',14=>'চৌদ্দ',15=>'পনেরো',16=>'ষোল',
            17=>'সতেরো',18=>'আঠারো',19=>'উনিশ',20=>'বিশ',21=>'একুশ',22=>'বাইশ',23=>'তেইশ',
            24=>'চব্বিশ',25=>'পঁচিশ',26=>'ছাব্বিশ',27=>'সাতাশ',28=>'আটাশ',29=>'ঊনত্রিশ',30=>'ত্রিশ',
            31=>'একত্রিশ',32=>'বত্রিশ',33=>'তেত্রিশ',34=>'চৌত্রিশ',35=>'পঁয়ত্রিশ',36=>'ছত্রিশ',
            37=>'সাঁইত্রিশ',38=>'আটত্রিশ',39=>'ঊনচল্লিশ',40=>'চল্লিশ',50=>'পঞ্চাশ',
            60=>'ষাট',70=>'সত্তর',80=>'আশি',90=>'নব্বই'
        ];

        $n = (int)$number;
        if ($n < 40 && isset($words[$n])) return $words[$n];
        if ($n < 100) {
            $tens = floor($n / 10) * 10;
            $ones = $n % 10;
            return trim($words[$tens] . ($ones ? ' ' . $words[$ones] : ''));
        }
        if ($n < 1000) {
            $hundreds = floor($n / 100);
            $remainder = $n % 100;
            $text = trim($words[$hundreds] . 'শ' . ($remainder ? ' ' . numberToWordsBn($remainder) : ''));
            return $text;
        }
        if ($n < 10000) {
            $thousands = floor($n / 1000);
            $remainder = $n % 1000;
            $text = trim($words[$thousands] . ' হাজার' . ($remainder ? ' ' . numberToWordsBn($remainder) : ''));
            return $text;
        }
        return (string)$number;
    }
}

/**
 * Convert English year to words (BD Birth Certificate style)
 */
if (!function_exists('yearToWordsEn')) {
    function yearToWordsEn($year): string {
        $y = (int)$year;
        $f = new \NumberFormatter("en", \NumberFormatter::SPELLOUT);

        // 1900–1999 → Nineteen Hundred Ninety Nine
        if ($y >= 1900 && $y <= 1999) {
            $lastTwo = $y % 100;
            $yearWord = 'Nineteen Hundred';
            if ($lastTwo > 0) {
                $yearWord .= ' ' . ucwords(str_replace(['-', ' and '], ' ', $f->format($lastTwo)));
            }
            return $yearWord;
        }

        // 2000 → Two Thousand
        if ($y === 2000) {
            return 'Two Thousand';
        }

        // 2001+ → Two Thousand One, Two Thousand Twenty Three
        if ($y > 2000) {
            return ucwords(str_replace(['-', ' and '], ' ', $f->format($y)));
        }

        // fallback for <1900
        return ucwords(str_replace(['-', ' and '], ' ', $f->format($y)));
    }
}

/**
 * Convert date to words (BN + EN) - English formatted for birth certificate
 */
if (!function_exists('dateToWords')) {
    function dateToWords(string $date): array {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d) throw new Exception("Invalid date format for dateToWords, expected yyyy-mm-dd.");

        $day   = (int)$d->format('d');
        $month = (int)$d->format('m');
        $year  = (int)$d->format('Y');

        $bnMonths = [1=>'জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','অগাস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];

        $enDayWords = [
            1=>'First',2=>'Second',3=>'Third',4=>'Fourth',5=>'Fifth',6=>'Sixth',7=>'Seventh',8=>'Eighth',9=>'Ninth',10=>'Tenth',
            11=>'Eleventh',12=>'Twelfth',13=>'Thirteenth',14=>'Fourteenth',15=>'Fifteenth',16=>'Sixteenth',17=>'Seventeenth',
            18=>'Eighteenth',19=>'Nineteenth',20=>'Twentieth',21=>'Twenty First',22=>'Twenty Second',23=>'Twenty Third',
            24=>'Twenty Fourth',25=>'Twenty Fifth',26=>'Twenty Sixth',27=>'Twenty Seventh',28=>'Twenty Eighth',
            29=>'Twenty Ninth',30=>'Thirtieth',31=>'Thirty First'
        ];

        $birth_date_words_en = "{$enDayWords[$day]} of {$d->format('F')} " . yearToWordsEn($year);

        if ($year < 2000) {
            $bnYearWords = 'উনিশ শত ' . numberToWordsBn($year % 100);
        } elseif ($year < 2100) {
            $bnYearWords = 'দুই হাজার ' . numberToWordsBn($year % 100);
        } else {
            $bnYearWords = numberToWordsBn($year);
        }

        $birth_date_words_bn = numberToWordsBn($day) . ' ' . $bnMonths[$month] . ' ' . $bnYearWords;

        return [
            'bn' => $birth_date_words_bn,
            'en' => $birth_date_words_en
        ];
    }
}

if (!function_exists('bdris_log')) {
    function bdris_log($msg) {
        $logFile = BASE_PATH . '/storage/logs/bdris_log.txt';
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }
        $t = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$t] $msg\n", FILE_APPEND);
    }
}

if (!function_exists('bdris_cookie_file')) {
    function bdris_cookie_file() {
        return TEMP_DIR . '/cookies_' . session_id() . '.txt';
    }
}

if (!function_exists('bdris_captcha_file')) {
    function bdris_captcha_file() {
        return TEMP_DIR . '/captcha_' . session_id() . '.png';
    }
}

/* ---------------- CURL WRAPPERS ---------------- */
if (!function_exists('bdris_curl_get')) {
    function bdris_curl_get($url, $cookie_file, $referer = null, $raw = false) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookie_file,
            CURLOPT_COOKIEFILE => $cookie_file,
            CURLOPT_HTTPHEADER => [
                'Origin: https://everify.bdris.gov.bd',
                'Referer: https://everify.bdris.gov.bd/',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $http >= 400) {
            bdris_log("curl_get failed $url http=$http err=$err");
            return false;
        }
        return $res;
    }
}

if (!function_exists('bdris_curl_post_raw')) {
    function bdris_curl_post_raw($url, $body, $cookie_file, $referer = null, $boundary = null) {
        $ch = curl_init($url);
        $headers = [
            "Origin: https://everify.bdris.gov.bd",
            "Referer: https://everify.bdris.gov.bd",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36",
            "Content-Type: multipart/form-data; boundary=$boundary",
            "Content-Length: " . strlen($body)
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookie_file,
            CURLOPT_COOKIEFILE => $cookie_file,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $http >= 400) {
            bdris_log("curl_post_raw failed $url http=$http err=$err");
            return false;
        }
        return $res;
    }
}

/* ---------------- HTML PARSER ---------------- */
if (!function_exists('bdris_parse_html')) {
    function bdris_parse_html(string $html): array {
        if (stripos($html, 'Death registration number') !== false) {
            return bdris_parse_death_html($html);
        }
        return bdris_parse_birth_html($html);
    }
}

if (!function_exists('bdris_parse_birth_html')) {
    function bdris_parse_birth_html(string $html): array {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadHTML($html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $fields = [];

        /* =======================
           COMMON TABLE PARSING
        ======================= */
        $tables = $xp->query('//table[contains(@class,"table")]');

        // --- Main birth table ---
        if ($tables->length >= 1) {
            $trs = $tables->item(0)->getElementsByTagName('tr');
            $rows = [];

            foreach ($trs as $tr) {
                $tds = $tr->getElementsByTagName('td');
                $r = [];
                foreach ($tds as $td) {
                    $r[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
                }
                $rows[] = $r;
            }

            if (isset($rows[2])) {
                $fields['registration_date_en']   = $rows[2][0] ?? null;
                $fields['registration_office_en'] = $rows[2][1] ?? null;
                $fields['issuance_date_en']       = $rows[2][2] ?? null;
            }

            if (isset($rows[4])) {
                $fields['date_of_birth_en']             = $rows[4][0] ?? null;
                $fields['birth_registration_number_en'] = trim($rows[4][1] ?? '');
                $fields['sex_en']                        = $rows[4][2] ?? null;
            }
        }

        /* =======================
           PERSON DETAILS
        ======================= */
        if ($tables->length >= 2) {
            foreach ($tables->item(1)->getElementsByTagName('tr') as $tr) {
                $tds = $tr->getElementsByTagName('td');
                if ($tds->length < 4) continue;

                $label_en = strtolower(trim($tds->item(2)->textContent));
                $value_en = trim($tds->item(3)->textContent);
                $value_bn = trim($tds->item(1)->textContent);

                $label_en = str_replace(["'", "'"], '', $label_en);

                switch ($label_en) {
                    case 'registered person name':
                        $fields['name_en'] = $value_en;
                        $fields['name_bn'] = $value_bn;
                        break;

                    case 'place of birth':
                        $fields['place_of_birth_en'] = $value_en;
                        $fields['place_of_birth_bn'] = $value_bn;
                        break;

                    case 'father name':
                    case 'fathers name':
                        $fields['father_name_en'] = $value_en;
                        $fields['father_name_bn'] = $value_bn;
                        break;

                    case 'mother name':
                    case 'mothers name':
                        $fields['mother_name_en'] = $value_en;
                        $fields['mother_name_bn'] = $value_bn;
                        break;
                }
            }
        }

        /* =======================
           LOCATION PARSE
        ======================= */
        $pnode = $xp->query('//p[contains(.,"Location of the Register office")]');
        if ($pnode->length) {
            if (preg_match('/:\s*(.+?)\./', $pnode->item(0)->textContent, $m)) {
                $loc = trim($m[1]);

                $fields['office_location_en'] = $loc . '.';

                $parts = array_map('trim', explode(',', $loc));
                if (count($parts) >= 3) {
                    $fields['district_en'] = array_pop($parts);
                    $fields['upazila_en']  = array_pop($parts);
                    $fields['union_en']    = implode(', ', $parts);
                }
            }
        }

        return $fields;
    }
}

if (!function_exists('bdris_parse_death_html')) {
    function bdris_parse_death_html(string $html): array {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadHTML($html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $fields = [];

        /* =======================
           HEADER INFO
        ======================= */
        $infoNode = $xp->query(
            '//table[contains(@class,"boxshadow")]//span[contains(text(),"Death registration number")]'
        );

        if (!$infoNode->length) {
            return [];
        }

        $htmlSpan = $doc->saveHTML($infoNode->item(0));

        if (preg_match('/<b>(\d+)<\/b>/', $htmlSpan, $m)) {
            $fields['death_registration_number_en'] = $m[1];
        }

        if (preg_match('/Date of Death is\s*<b>([^<]+)<\/b>/i', $htmlSpan, $m)) {
            $fields['death_date_en'] = trim($m[1]);
        }

        /* =======================
           DETAILS TABLE
        ======================= */
        $rows = $xp->query(
            '//table[contains(@class,"boxshadow")]//table[contains(@class,"table")]//tr'
        );

        foreach ($rows as $tr) {
            $tds = $tr->getElementsByTagName('td');
            if ($tds->length !== 4) continue;

            // skip info/footer rows
            if ($tds->item(0)->getAttribute('colspan')) continue;

            $label_en = strtolower(trim($tds->item(2)->textContent));
            $label_en = str_replace(["'", "'"], '', $label_en);

            $value_bn = trim($tds->item(1)->textContent);
            $value_en = trim($tds->item(3)->textContent);

            // fallback: english empty → use bangla
            if ($value_en === '') {
                $value_en = $value_bn;
            }

            switch ($label_en) {
                case 'name of dead person':
                    $fields['name_en'] = $value_en;
                    $fields['name_bn'] = $value_bn;
                    break;

                case 'place of death':
                    $fields['place_of_death_en'] = $value_en;
                    $fields['place_of_death_bn'] = $value_bn;
                    break;

                case 'father name':
                    $fields['father_name_en'] = $value_en;
                    $fields['father_name_bn'] = $value_bn;
                    break;

                case 'mother name':
                    $fields['mother_name_en'] = $value_en;
                    $fields['mother_name_bn'] = $value_bn;
                    break;
            }
        }

        return $fields;
    }
}

/* ---------------- Utility ---------------- */
if (!function_exists('bdris_convert_date')) {
    function bdris_convert_date($dateString, $format = 'Y-m-d') {
        if (empty($dateString)) return null;
        $timestamp = strtotime($dateString);
        return $timestamp ? date($format, $timestamp) : null;
    }
}

/* ================================================================
   ==========  BDRIS Integration Functions for Router  ============
================================================================ */

/**
 * Generate captcha + verification token from BDRIS site
 */
if (!function_exists('bdris_generate_captcha')) {
    function bdris_generate_captcha() {
        try {
            $cookie = bdris_cookie_file();
            $base = 'https://everify.bdris.gov.bd';
            $html = bdris_curl_get("$base/UBRNVerification", $cookie);
            if (!$html) {
                return ['status'=>'error', 'message'=>'Failed to load birth initial page'];
            }

            $doc = new DOMDocument();
            libxml_use_internal_errors(true);
            @$doc->loadHTML($html);
            libxml_clear_errors();
            $xp = new DOMXPath($doc);

            $token = $xp->evaluate("string(//input[@name='__RequestVerificationToken']/@value)");
            $captcha_de_text = $xp->evaluate("string(//input[@name='CaptchaDeText']/@value)");

            if (!$token || !$captcha_de_text) {
                return ['status'=>'error', 'message'=>'Birth Token or Captcha text not found'];
            }

            $captcha_url = "$base/DefaultCaptcha/Generate?t=" . urlencode($captcha_de_text);
            $img = bdris_curl_get($captcha_url, $cookie, "$base/UBRNVerification", true);
            if (!$img) {
                return ['status'=>'error', 'message'=>'Birth Captcha image not fetched'];
            }

            $data_uri = 'data:image/png;base64,' . base64_encode($img);
            return [
                'status' => 'ok',
                'token' => $token,
                'captcha_de_text' => $captcha_de_text,
                'captcha_data_uri' => $data_uri
            ];
        } catch (Throwable $e) {
            return ['status'=>'error', 'message'=>$e->getMessage()];
        }
    }
}

/**
 * Submit UBRN data and fetch parsed birth info
 */
if (!function_exists('bdris_fetch_birth_data')) {
    function bdris_fetch_birth_data($ubrn, $dob, $captcha, $token = '', $captcha_de_text = '') {
        try {
            $cookie = bdris_cookie_file();
            $base = 'https://everify.bdris.gov.bd';
            $boundary = '----WebKitFormBoundary' . bin2hex(random_bytes(8));
            $body = '';
            $eol = "\r\n";

            $fields = [
                '__RequestVerificationToken' => $token,
                'UBRN' => $ubrn,
                'BirthDate' => $dob,
                'CaptchaDeText' => $captcha_de_text,
                'CaptchaInputText' => $captcha
            ];
            foreach ($fields as $name => $value) {
                $body .= "--$boundary{$eol}Content-Disposition: form-data; name=\"$name\"{$eol}{$eol}$value{$eol}";
            }
            $body .= "--$boundary--{$eol}";

            $html = bdris_curl_post_raw("$base/UBRNVerification/Search", $body, $cookie, null, $boundary);
            if (!$html) {
                return ['status'=>'error', 'message'=>'No birth response from BDRIS'];
            }

            $parsed = bdris_parse_html($html);
            if (!$parsed || !isset($parsed['birth_registration_number_en'])) {
                return ['status'=>'error', 'message'=>'Failed to parse Birth Data'];
            }

            // Convert date formats
            $parsed['registration_date_en'] = bdris_convert_date($parsed['registration_date_en']);
            $parsed['issuance_date_en'] = bdris_convert_date($parsed['issuance_date_en']);
            $parsed['date_of_birth_en'] = bdris_convert_date($parsed['date_of_birth_en']);

            return $parsed;
        } catch (Throwable $e) {
            return ['status'=>'error', 'message'=>$e->getMessage()];
        }
    }
}

/**
 * Generate captcha + verification token for Death (UDRN)
 */
if (!function_exists('bdris_generate_death_captcha')) {
    function bdris_generate_death_captcha() {
        try {
            $cookie = bdris_cookie_file();
            $base = 'https://everify.bdris.gov.bd';

            $html = bdris_curl_get("$base/UDRNVerification", $cookie);
            if (!$html) {
                return ['status'=>'error', 'message'=>'Failed to load death verification page'];
            }

            $doc = new DOMDocument();
            libxml_use_internal_errors(true);
            @$doc->loadHTML($html);
            libxml_clear_errors();
            $xp = new DOMXPath($doc);

            $token = $xp->evaluate("string(//input[@name='__RequestVerificationToken']/@value)");
            $captcha_de_text = $xp->evaluate("string(//input[@name='CaptchaDeText']/@value)");

            if (!$token || !$captcha_de_text) {
                return ['status'=>'error', 'message'=>'Death Token or captcha text missing'];
            }

            $captcha_url = "$base/DefaultCaptcha/Generate?t=" . urlencode($captcha_de_text);
            $img = bdris_curl_get($captcha_url, $cookie, "$base/UDRNVerification", true);

            if (!$img) {
                return ['status'=>'error', 'message'=>'Death Captcha image fetch failed'];
            }

            return [
                'status' => 'ok',
                'token'  => $token,
                'captcha_de_text' => $captcha_de_text,
                'captcha_data_uri' => 'data:image/png;base64,' . base64_encode($img)
            ];
        } catch (Throwable $e) {
            return ['status'=>'error', 'message'=>$e->getMessage()];
        }
    }
}

/**
 * Submit UDRN and fetch parsed death registration info
 */
if (!function_exists('bdris_fetch_death_data')) {
    function bdris_fetch_death_data($udrn, $deathDate, $captcha, $token, $captcha_de_text) {
        $cookie = bdris_cookie_file();
        $url = 'https://everify.bdris.gov.bd/UDRNVerification/Search';

        $postData = http_build_query([
            '__RequestVerificationToken' => $token,
            'UBRN' => $udrn,
            'BirthDate' => $deathDate,
            'CaptchaDeText' => $captcha_de_text,
            'CaptchaInputText' => $captcha
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Origin: https://everify.bdris.gov.bd',
                'Referer: https://everify.bdris.gov.bd/UDRNVerification/Search',
                'User-Agent: Mozilla/5.0'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);

        $html = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($html === false || $http >= 400) {
            bdris_log("Death POST failed http=$http err=$err");
            return ['status'=>'error','message'=>'Death request failed'];
        }

        // DEBUG (optional but recommended)
        file_put_contents(__DIR__.'/death_debug.html', $html);

        $parsed = bdris_parse_death_html($html);

        if (empty($parsed['death_registration_number_en'])) {
            return ['status'=>'error','message'=>'Invalid death record or captcha'];
        }

        return $parsed;
    }
}