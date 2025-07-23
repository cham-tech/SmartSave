<?php
// File: /src/Loans.php

namespace SmartSaveCircle;

class Loans {
    private $conn;
    
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }
    
    public function requestLoan($userId, $amount, $purpose) {
        $stmt = $this->conn->prepare("INSERT INTO loans (user_id, amount, purpose) VALUES (?, ?, ?)");
        $stmt->bind_param("ids", $userId, $amount, $purpose);
        
        if ($stmt->execute()) {
            return ['success' => true, 'loan_id' => $stmt->insert_id];
        } else {
            return ['success' => false, 'message' => 'Failed to submit loan request'];
        }
    }
    
    public function getLoans($userId, $isAdmin = false) {
        if ($isAdmin) {
            $stmt = $this->conn->prepare("SELECT l.*, u.first_name, u.last_name FROM loans l JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC");
        } else {
            $stmt = $this->conn->prepare("SELECT * FROM loans WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->bind_param("i", $userId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $loans = [];
        while ($row = $result->fetch_assoc()) {
            $loans[] = $row;
        }
        
        return $loans;
    }
    
    public function getLoanById($loanId, $userId, $isAdmin = false) {
        if ($isAdmin) {
            $stmt = $this->conn->prepare("SELECT l.*, u.first_name, u.last_name FROM loans l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
        } else {
            $stmt = $this->conn->prepare("SELECT * FROM loans WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $loanId, $userId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    public function approveLoan($loanId, $adminId) {
        $dueDate = date('Y-m-d', strtotime('+30 days'));
        
        $stmt = $this->conn->prepare("UPDATE loans SET status = 'approved', approved_by = ?, approved_at = NOW(), due_date = ? WHERE id = ?");
        $stmt->bind_param("isi", $adminId, $dueDate, $loanId);
        
        return $stmt->execute();
    }
    
    public function rejectLoan($loanId, $adminId) {
        $stmt = $this->conn->prepare("UPDATE loans SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $adminId, $loanId);
        
        return $stmt->execute();
    }
    
    public function disburseLoan($loanId) {
        $loan = $this->getLoanById($loanId, null, true);
        if (!$loan || $loan['status'] != 'approved') {
            return ['success' => false, 'message' => 'Loan not approved'];
        }
        
        $user = $this->getUser($loan['user_id']);
        $reference = 'LOAN-' . uniqid();
        $paymentResult = $this->processPayment($user['phone'], $loan['amount'], $reference, "Loan disbursement");
        
        if ($paymentResult['success']) {
            $stmt = $this->conn->prepare("UPDATE loans SET status = 'disbursed', disbursed_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $loanId);
            $stmt->execute();
            
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => $paymentResult['error']];
        }
    }
    
    public function repayLoan($loanId, $userId, $amount) {
        $loan = $this->getLoanById($loanId, $userId);
        if (!$loan) {
            return ['success' => false, 'message' => 'Invalid loan'];
        }
        
        if ($loan['status'] != 'disbursed') {
            return ['success' => false, 'message' => 'Loan not disbursed'];
        }
        
        $user = $this->getUser($userId);
        $reference = 'REPAY-' . uniqid();
        $paymentResult = $this->processPayment($user['phone'], $amount, $reference, "Loan repayment");
        
        if ($paymentResult['success']) {
            // Record repayment
            $stmt = $this->conn->prepare("INSERT INTO loan_repayments (loan_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'completed')");
            $stmt->bind_param("ids", $loanId, $amount, $reference);
            $stmt->execute();
            
            // Check if loan is fully paid
            $totalPaid = $this->getTotalRepaid($loanId);
            if ($totalPaid >= $loan['amount']) {
                $stmt = $this->conn->prepare("UPDATE loans SET status = 'paid' WHERE id = ?");
                $stmt->bind_param("i", $loanId);
                $stmt->execute();
            }
            
            return ['success' => true];
        } else {
            // Record failed repayment
            $stmt = $this->conn->prepare("INSERT INTO loan_repayments (loan_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'failed')");
            $stmt->bind_param("ids", $loanId, $amount, $reference);
            $stmt->execute();
            
            return ['success' => false, 'message' => $paymentResult['error']];
        }
    }
    
    public function getRepayments($loanId, $userId, $isAdmin = false) {
        if (!$isAdmin) {
            // Verify loan belongs to user
            $loan = $this->getLoanById($loanId, $userId);
            if (!$loan) {
                return [];
            }
        }
        
        $stmt = $this->conn->prepare("SELECT * FROM loan_repayments WHERE loan_id = ? ORDER BY repayment_date DESC");
        $stmt->bind_param("i", $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $repayments = [];
        while ($row = $result->fetch_assoc()) {
            $repayments[] = $row;
        }
        
        return $repayments;
    }
    
    private function getTotalRepaid($loanId) {
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM loan_repayments WHERE loan_id = ? AND status = 'completed'");
        $stmt->bind_param("i", $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['total'];
    }
    
    private function processPayment($phone, $amount, $reference, $narration) {
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