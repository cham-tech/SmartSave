<?php
// File: /api/bitnob.php

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['action'])) {
        switch ($data['action']) {
            case 'send_money':
                if (empty($data['phone']) || empty($data['amount']) || empty($data['reference'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                    exit;
                }
                
                $phone = $data['phone'];
                $amount = floatval($data['amount']);
                $reference = $data['reference'];
                $narration = $data['narration'] ?? 'Payment from SmartSave Circle';
                
                $result = processBitnobPayment($phone, $amount, $reference, $narration);
                
                if ($result['success']) {
                    echo json_encode(['success' => true, 'transaction_id' => $result['transaction_id']]);
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => $result['error']]);
                }
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No action specified']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

$conn->close();
?>
