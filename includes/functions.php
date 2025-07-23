<?php
// File: /includes/functions.php

function formatCurrency($amount) {
    return number_format($amount, 2) . ' ' . APP_CURRENCY;
}

function getSavingsProgressPercentage($current, $target) {
    if ($target == 0) return 0;
    $percentage = ($current / $target) * 100;
    return min(100, max(0, round($percentage, 2)));
}

function getNextPayoutDate($frequency, $lastDate = null) {
    $date = $lastDate ? new DateTime($lastDate) : new DateTime();
    
    switch ($frequency) {
        case 'daily':
            $date->modify('+1 day');
            break;
        case 'weekly':
            $date->modify('+1 week');
            break;
        case 'monthly':
            $date->modify('+1 month');
            break;
    }
    
    return $date->format('Y-m-d');
}

function sendReminder($userId, $message) {
    // In a real app, this would send an email or SMS
    // For now, we'll just create a notification
    createNotification($userId, 'Reminder', $message);
}

function processBitnobPayment($phone, $amount, $reference, $narration) {
    // Mock implementation - in a real app, this would call the Bitnob API
    // For demo purposes, we'll simulate a successful payment 80% of the time
    
    $success = rand(0, 100) < 80;
    
    if ($success) {
        return [
            'success' => true,
            'transaction_id' => 'MOCK-' . uniqid(),
            'reference' => $reference,
            'status' => 'completed'
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Payment failed due to insufficient funds or network issues',
            'status' => 'failed'
        ];
    }
}
?>
