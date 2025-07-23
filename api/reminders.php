<?php
// File: /api/reminders.php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = getDBConnection();
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get savings goals that need reminders
    $today = date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT id, goal_name, next_saving_date 
        FROM savings_goals 
        WHERE user_id = ? AND is_completed = FALSE AND next_saving_date <= ?
        ORDER BY next_saving_date ASC
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $goals = [];
    while ($row = $result->fetch_assoc()) {
        $goals[] = $row;
    }
    $stmt->close();
    
    if (!empty($goals)) {
        // Update next saving dates
        foreach ($goals as $goal) {
            $nextDate = getNextPayoutDate($goal['frequency'], $goal['next_saving_date']);
            
            $stmt = $conn->prepare("UPDATE savings_goals SET next_saving_date = ? WHERE id = ?");
            $stmt->bind_param("si", $nextDate, $goal['id']);
            $stmt->execute();
            $stmt->close();
            
            // Create notification
            createNotification(
                $userId, 
                'Savings Reminder', 
                "It's time to save for your goal: " . $goal['goal_name']
            );
        }
    }
    
    echo json_encode(['success' => true, 'reminders' => $goals]);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

$conn->close();
?>
