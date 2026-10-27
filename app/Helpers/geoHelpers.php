<?php
// helpers/geoHelpers.php

function secureFetchAndProcess($xmlUrl) {
    global $mysqli;
    $queryParams = getQueryParams($xmlUrl);
    $geo_order = isset($queryParams['geoOrder']) ? (int)$queryParams['geoOrder'] : 0;
    $geo_type  = isset($queryParams['geoType']) ? (int)$queryParams['geoType'] : 0;

    $ch = curl_init($xmlUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $content = curl_exec($ch);

    if (curl_errno($ch)) {
        $message = "cURL Error: " . curl_error($ch);
        curl_close($ch);
        return ['error', $message];
    }

    curl_close($ch);

    if (isJson($content)) {
        $jsonContent = json_decode($content, true);
        if ($jsonContent === null) return ['error', "Failed to decode JSON content."];
        $status = $message = '';
        processGeoData($jsonContent, $geo_order, $geo_type, $status, $message);
        return [$status, $message];
    } else {
        libxml_disable_entity_loader(true); 
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOENT);
        if ($xml === false) {
            $errors = implode(', ', array_map(fn($e) => $e->message, libxml_get_errors()));
            libxml_clear_errors();
            return ['error', "Failed to load XML: $errors"];
        }
        $status = $message = '';
        processGeoData($xml, $geo_order, $geo_type, $status, $message);
        return [$status, $message];
    }
}

function isJson($string) {
    json_decode($string);
    return (json_last_error() === JSON_ERROR_NONE);
}

function getQueryParams($url) {
    $queryParams = [];
    $parsedUrl = parse_url($url);
    if (isset($parsedUrl['query'])) parse_str($parsedUrl['query'], $queryParams);
    return $queryParams;
}

function processGeoData($data, $geo_order, $geo_type, &$status, &$message) {
    global $mysqli;
    $status = 'success';
    $messages = [];
    $geoObjects = is_array($data) && isset($data['geoObject']) ? $data['geoObject'] : $data->geoObject;

    foreach ($geoObjects as $geoObject) {
        $object = is_array($geoObject) ? (object)$geoObject : $geoObject;
        $msg = '';
        $insertStatus = '';
        insertGeoObject($object, $geo_order, $geo_type, $insertStatus, $msg);
        $messages[] = $msg;
        if ($insertStatus === 'error') $status = 'error';
    }
    $message = implode("\n", $messages);
}

function insertGeoObject($geoObject, $geo_order, $geo_type, &$status, &$message) {
    global $mysqli;
    // Implementation as in your original helper
}

function getData() { /* ... */ }
function getByType() { /* ... */ }
function getByTypeTree() { /* ... */ }
function getUnion() { /* ... */ }
function geoUnion() { /* ... */ }
