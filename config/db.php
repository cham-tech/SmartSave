<?php
// File: /config/db.php

require_once __DIR__ . '/../load_env.php'; // Adjust path based on your structure
loadEnv(__DIR__ . '/../.env');             // Load .env variables from root

// Define constants using .env values
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASSWORD']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_PORT', $_ENV['DB_PORT'] ?? 3306); // Optional fallback

function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    return $conn;
}
?>
