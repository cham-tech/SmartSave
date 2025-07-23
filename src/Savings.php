<?php
// File: /src/Savings.php

namespace SmartSaveCircle;

class Savings {
    private $conn;
    
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }
    
    public function createGoal($userId, $goalName, $targetAmount, $frequency) {
        $nextSavingDate = $this->getNextPayoutDate($frequency);
        
        $stmt = $this->conn->prepare("INSERT INTO savings_goals (user_id, goal_name, target_amount, frequency, next_saving_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $userId, $goalName, $targetAmount, $frequency, $nextSavingDate);
        
        if ($stmt->execute()) {
            return ['success' => true, 'goal_id' => $stmt->insert_id];
        } else {
            return ['success' => false, 'message' => 'Failed to create savings goal'];
        }
    }
    
    public function getGoals($userId) {
        $stmt = $this->conn->prepare("SELECT * FROM savings_goals WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $goals = [];
        while ($row = $result->fetch_assoc()) {
            $goals[] = $row;
        }
        
        return $goals;
    }
    
    public function getGoalById($goalId, $userId) {
        $stmt = $this->conn->prepare("SELECT * FROM savings_goals WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $goalId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    public function addToSavings($goalId, $userId, $amount) {
        // Verify goal belongs to user
        $goal = $this->getGoalById($goalId, $userId);
        if (!$goal) {
            return ['success' => false, 'message' => 'Invalid savings goal'];
        }
        
        // Process payment (this would be replaced with actual Bitnob API call)
        $reference = 'SAVE-' . uniqid();
        $paymentResult = $this->processPayment($userId, $amount, $reference, "Savings deposit for goal: " . $goal['goal_name']);
        
        if ($paymentResult['success']) {
            // Update savings goal
            $stmt = $this->conn->prepare("UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ?");
            $stmt->bind_param("di", $amount, $goalId);
            $stmt->execute();
            
            // Record transaction
            $stmt = $this->conn->prepare("INSERT INTO savings_transactions (savings_goal_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'completed')");
            $stmt->bind_param("ids", $goalId, $amount, $reference);
            $stmt->execute();
            
            // Check if goal is completed
            if (($goal['current_amount'] + $amount) >= $goal['target_amount']) {
                $stmt = $this->conn->prepare("UPDATE savings_goals SET is_completed = TRUE WHERE id = ?");
                $stmt->bind_param("i", $goalId);
                $stmt->execute();
            }
            
            return ['success' => true];
        } else {
            // Record failed transaction
            $stmt = $this->conn->prepare("INSERT INTO savings_transactions (savings_goal_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'failed')");
            $stmt->bind_param("ids", $goalId, $amount, $reference);
            $stmt->execute();
            
            return ['success' => false, 'message' => $paymentResult['error']];
        }
    }
    
    public function withdrawFromSavings($goalId, $userId, $amount) {
        // Verify goal belongs to user
        $goal = $this->getGoalById($goalId, $userId);
        if (!$goal) {
            return ['success' => false, 'message' => 'Invalid savings goal'];
        }
        
        if ($amount <= 0 || $amount > $goal['current_amount']) {
            return ['success' => false, 'message' => 'Invalid withdrawal amount'];
        }
        
        // Check if early withdrawal (not completed and not reaching target)
        $isEarlyWithdrawal = !$goal['is_completed'] && ($goal['current_amount'] - $amount) < $goal['target_amount'];
        $penalty = $isEarlyWithdrawal ? $amount * EARLY_WITHDRAWAL_PENALTY : 0;
        $totalDeduction = $amount + $penalty;
        
        // Process withdrawal payment
        $user = $this->getUser($userId);
        $reference = 'WTH-' . uniqid();
        $paymentResult = $this->processPayment($user['phone'], $amount, $reference, "Withdrawal from savings goal: " . $goal['goal_name']);
        
        if ($paymentResult['success']) {
            // Update savings goal
            $stmt = $this->conn->prepare("UPDATE savings_goals SET current_amount = current_amount - ? WHERE id = ?");
            $stmt->bind_param("di", $totalDeduction, $goalId);
            $stmt->execute();
            
            // Record transaction
            $negativeAmount = -$amount;
            $stmt = $this->conn->prepare("INSERT INTO savings_transactions (savings_goal_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'completed')");
            $stmt->bind_param("ids", $goalId, $negativeAmount, $reference);
            $stmt->execute();
            
            $result = ['success' => true];
            if ($isEarlyWithdrawal) {
                $result['penalty'] = $penalty;
            }
            
            return $result;
        } else {
            return ['success' => false, 'message' => $paymentResult['error']];
        }
    }
    
    public function getTransactions($goalId, $userId) {
        // Verify goal belongs to user
        if (!$this->getGoalById($goalId, $userId)) {
            return [];
        }
        
        $stmt = $this->conn->prepare("SELECT * FROM savings_transactions WHERE savings_goal_id = ? ORDER BY transaction_date DESC");
        $stmt->bind_param("i", $goalId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        return $transactions;
    }
    
    private function getNextPayoutDate($frequency, $lastDate = null) {
        $date = $lastDate ? new \DateTime($lastDate) : new \DateTime();
        
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
    
    private function processPayment($userId, $amount, $reference, $narration) {
        // This would be replaced with actual Bitnob API integration
        // For now, we'll simulate a successful payment 80% of the time
        $success = rand(0, 100) < 80;
        
        if ($success) {
            return [
                'success' => true,
                'transaction_id' => 'MOCK-' . uniqid(),
                'reference' => $reference
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Payment failed due to insufficient funds or network issues'
            ];
        }
    }
    
    private function getUser($userId) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
}
?>