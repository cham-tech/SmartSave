<?php

// Application Constants
define('APP_NAME', 'SmartSave');
define('APP_URL', 'http://localhost/SmartSave');
define('APP_CURRENCY', 'UGX');

// Bitnob API Configuration
define('BITNOB_API_KEY', 'your_bitnob_api_key_here');
define('BITNOB_BASE_URL', 'https://api.bitnob.co/v1/');

// Early withdrawal penalty multiplier
define('EARLY_WITHDRAWAL_PENALTY', 3);

// Path constants
define('ASSETS_PATH', APP_URL . '/assets');
define('CSS_PATH', ASSETS_PATH . '/css');
define('JS_PATH', ASSETS_PATH . '/js');
define('IMG_PATH', ASSETS_PATH . '/img');

// Session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// Include database connection
require_once 'db.php';
?>