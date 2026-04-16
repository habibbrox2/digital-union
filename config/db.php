<?php
// /config/db.php


    // Ensure config.php is loaded
    require_once __DIR__ . '/config.php';
    
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
        if ($mysqli->connect_error) {
            error_log("DB Connection failed: " . $mysqli->connect_error);
            http_response_code(500);
            exit('Internal Server Error');
        }
    
        $mysqli->set_charset('utf8mb4');
    }
