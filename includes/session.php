<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php'; // 👈 make sure this is correct
$conn = getDBConnection(); // or just use $conn if your db.php defines it directly

if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("
        INSERT INTO user_sessions (user_id, last_activity)
        VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE last_activity = NOW()
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
}
?>
