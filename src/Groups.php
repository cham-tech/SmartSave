<?php
// File: /src/Groups.php

namespace SmartSaveCircle;

class Groups {
    private $conn;
    
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }
    
    public function createGroup($userId, $name, $description, $amountPerCycle, $cycleFrequency) {
        $stmt = $this->conn->prepare("INSERT INTO saving_groups (name, description, amount_per_cycle, cycle_frequency, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdsi", $name, $description, $amountPerCycle, $cycleFrequency, $userId);
        
        if ($stmt->execute()) {
            $groupId = $stmt->insert_id;
            
            // Add creator as member
            $this->addMember($groupId, $userId);
            
            // Create first cycle
            $this->createCycle($groupId);
            
            return ['success' => true, 'group_id' => $groupId];
        } else {
            return ['success' => false, 'message' => 'Failed to create savings group'];
        }
    }
    
    public function getGroups($userId = null) {
        if ($userId) {
            // Get groups user is a member of
            $stmt = $this->conn->prepare("
                SELECT sg.* 
                FROM saving_groups sg
                JOIN group_members gm ON sg.id = gm.group_id
                WHERE gm.user_id = ? AND gm.is_active = TRUE
                ORDER BY sg.created_at DESC
            ");
            $stmt->bind_param("i", $userId);
        } else {
            // Get all groups
            $stmt = $this->conn->prepare("
                SELECT sg.*, 
                       (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = sg.id AND gm.is_active = TRUE) as member_count
                FROM saving_groups sg
                ORDER BY sg.created_at DESC
            ");
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groups[] = $row;
        }
        
        return $groups;
    }
    
    public function getGroupById($groupId, $userId = null) {
        if ($userId) {
            // Verify user is a member if userId is provided
            $stmt = $this->conn->prepare("
                SELECT sg.* 
                FROM saving_groups sg
                JOIN group_members gm ON sg.id = gm.group_id
                WHERE sg.id = ? AND gm.user_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("ii", $groupId, $userId);
        } else {
            $stmt = $this->conn->prepare("SELECT * FROM saving_groups WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $groupId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    public function joinGroup($groupId, $userId) {
        // Check if already a member
        $stmt = $this->conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $groupId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return ['success' => false, 'message' => 'You are already a member of this group'];
        }
        
        // Add user to group
        $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $groupId, $userId);
        
        return ['success' => $stmt->execute()];
    }
    
    public function getMembers($groupId) {
        $stmt = $this->conn->prepare("
            SELECT u.id, u.first_name, u.last_name, u.phone, gm.joined_at
            FROM group_members gm
            JOIN users u ON gm.user_id = u.id
            WHERE gm.group_id = ? AND gm.is_active = TRUE
            ORDER BY gm.joined_at
        ");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $members = [];
        while ($row = $result->fetch_assoc()) {
            $members[] = $row;
        }
        
        return $members;
    }
    
    public function getCurrentCycle($groupId) {
        $stmt = $this->conn->prepare("
            SELECT * FROM group_cycles 
            WHERE group_id = ? AND is_completed = FALSE
            ORDER BY cycle_number DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    public function getCompletedCycles($groupId) {
        $stmt = $this->conn->prepare("
            SELECT gc.*, gp.user_id as recipient_id, u.first_name, u.last_name, gp.amount as payout_amount
            FROM group_cycles gc
            LEFT JOIN group_payouts gp ON gc.id = gp.cycle_id
            LEFT JOIN users u ON gp.user_id = u.id
            WHERE gc.group_id = ? AND gc.is_completed = TRUE
            ORDER BY gc.cycle_number DESC
        ");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $cycles = [];
        while ($row = $result->fetch_assoc()) {
            $cycles[] = $row;
        }
        
        return $cycles;
    }
    
    public function makeContribution($cycleId, $userId, $amount) {
        // Get cycle and group details
        $cycle = $this->getCycleById($cycleId);
        if (!$cycle || $cycle['is_completed']) {
            return ['success' => false, 'message' => 'Invalid or completed cycle'];
        }
        
        // Verify user is a member of the group
        if (!$this->isActiveMember($cycle['group_id'], $userId)) {
            return ['success' => false, 'message' => 'You are not an active member of this group'];
        }
        
        // Verify amount matches group requirement
        $group = $this->getGroupById($cycle['group_id']);
        if (abs($amount - $group['amount_per_cycle']) > 0.01) {
            return ['success' => false, 'message' => 'Contribution amount must be exactly ' . formatCurrency($group['amount_per_cycle'])];
        }
        
        // Check if user has already contributed to this cycle
        if ($this->hasContributed($cycleId, $userId)) {
            return ['success' => false, 'message' => 'You have already contributed to this cycle'];
        }
        
        // Process payment
        $user = $this->getUser($userId);
        $reference = 'CONTRIB-' . uniqid();
        $paymentResult = $this->processPayment($user['phone'], $amount, $reference, "Contribution to savings group");
        
        if ($paymentResult['success']) {
            // Record contribution
            $stmt = $this->conn->prepare("
                INSERT INTO group_contributions (cycle_id, user_id, amount, transaction_reference, status)
                VALUES (?, ?, ?, ?, 'completed')
            ");
            $stmt->bind_param("iids", $cycleId, $userId, $amount, $reference);
            $stmt->execute();
            
            // Check if all members have contributed
            if ($this->allMembersHaveContributed($cycleId, $cycle['group_id'])) {
                $this->completeCycle($cycleId, $cycle['group_id']);
            }
            
            return ['success' => true];
        } else {
            // Record failed contribution
            $stmt = $this->conn->prepare("
                INSERT INTO group_contributions (cycle_id, user_id, amount, transaction_reference, status)
                VALUES (?, ?, ?, ?, 'failed')
            ");
            $stmt->bind_param("iids", $cycleId, $userId, $amount, $reference);
            $stmt->execute();
            
            return ['success' => false, 'message' => $paymentResult['error']];
        }
    }
    
    public function getContributions($cycleId, $groupId = null) {
        if ($groupId) {
            // Get all contributions for a group's cycle
            $stmt = $this->conn->prepare("
                SELECT gc.*, u.first_name, u.last_name
                FROM group_contributions gc
                JOIN users u ON gc.user_id = u.id
                WHERE gc.cycle_id = ?
                ORDER BY gc.contribution_date DESC
            ");
            $stmt->bind_param("i", $cycleId);
        } else {
            // Get user's contributions for a cycle
            $stmt = $this->conn->prepare("
                SELECT * FROM group_contributions
                WHERE cycle_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $cycleId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $contributions = [];
        while ($row = $result->fetch_assoc()) {
            $contributions[] = $row;
        }
        
        return $contributions;
    }
    
    private function addMember($groupId, $userId) {
        $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $groupId, $userId);
        return $stmt->execute();
    }
    
    private function createCycle($groupId) {
        $startDate = date('Y-m-d');
        $endDate = $this->getNextPayoutDate('monthly', $startDate); // Default to monthly
        
        // Get group frequency
        $group = $this->getGroupById($groupId);
        if ($group) {
            $endDate = $this->getNextPayoutDate($group['cycle_frequency'], $startDate);
        }
        
        // Get next cycle number
        $cycleNumber = 1;
        $stmt = $this->conn->prepare("SELECT MAX(cycle_number) as max_cycle FROM group_cycles WHERE group_id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        $maxCycle = $result->fetch_assoc()['max_cycle'];
        
        if ($maxCycle) {
            $cycleNumber = $maxCycle + 1;
        }
        
        $stmt = $this->conn->prepare("
            INSERT INTO group_cycles (group_id, cycle_number, start_date, end_date)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iiss", $groupId, $cycleNumber, $startDate, $endDate);
        return $stmt->execute();
    }
    
    private function getCycleById($cycleId) {
        $stmt = $this->conn->prepare("SELECT * FROM group_cycles WHERE id = ?");
        $stmt->bind_param("i", $cycleId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows === 1 ? $result->fetch_assoc() : null;
    }
    
    private function isActiveMember($groupId, $userId) {
        $stmt = $this->conn->prepare("
            SELECT id FROM group_members 
            WHERE group_id = ? AND user_id = ? AND is_active = TRUE
        ");
        $stmt->bind_param("ii", $groupId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }
    
    private function hasContributed($cycleId, $userId) {
        $stmt = $this->conn->prepare("
            SELECT id FROM group_contributions 
            WHERE cycle_id = ? AND user_id = ? AND status = 'completed'
        ");
        $stmt->bind_param("ii", $cycleId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }
    
    private function allMembersHaveContributed($cycleId, $groupId) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(gm.id) as total_members, 
                   COUNT(gc.id) as completed_contributions
            FROM group_members gm
            LEFT JOIN group_contributions gc ON gm.user_id = gc.user_id AND gc.cycle_id = ? AND gc.status = 'completed'
            WHERE gm.group_id = ? AND gm.is_active = TRUE
        ");
        $stmt->bind_param("ii", $cycleId, $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        
        return $stats['total_members'] == $stats['completed_contributions'];
    }
    
    private function completeCycle($cycleId, $groupId) {
        // Select random recipient (excluding those who have already received)
        $recipient = $this->selectRecipient($cycleId, $groupId);
        if (!$recipient) {
            return false;
        }
        
        // Calculate total amount (number of members × contribution amount)
        $totalAmount = $this->getTotalContributions($cycleId);
        
        // Record payout
        $stmt = $this->conn->prepare("
            INSERT INTO group_payouts (cycle_id, user_id, amount, payout_date, status)
            VALUES (?, ?, ?, CURDATE(), 'pending')
        ");
        $stmt->bind_param("iid", $cycleId, $recipient['user_id'], $totalAmount);
        $stmt->execute();
        $payoutId = $stmt->insert_id;
        
        // Mark cycle as completed
        $stmt = $this->conn->prepare("
            UPDATE group_cycles SET is_completed = TRUE WHERE id = ?
        ");
        $stmt->bind_param("i", $cycleId);
        $stmt->execute();
        
        // Create new cycle
        $this->createCycle($groupId);
        
        // Process payout to recipient
        $user = $this->getUser($recipient['user_id']);
        $payoutReference = 'PAYOUT-' . uniqid();
        $paymentResult = $this->processPayment($user['phone'], $totalAmount, $payoutReference, "Savings circle payout");
        
        if ($paymentResult['success']) {
            // Update payout status
            $stmt = $this->conn->prepare("
                UPDATE group_payouts 
                SET transaction_reference = ?, status = 'completed' 
                WHERE id = ?
            ");
            $stmt->bind_param("si", $payoutReference, $payoutId);
            $stmt->execute();
        }
        
        return true;
    }
    
    private function selectRecipient($cycleId, $groupId) {
        // Get members who haven't received payout in previous cycles
        $stmt = $this->conn->prepare("
            SELECT gm.user_id
            FROM group_members gm
            LEFT JOIN group_payouts gp ON gm.user_id = gp.user_id
            LEFT JOIN group_cycles gc ON gp.cycle_id = gc.id AND gc.group_id = ?
            WHERE gm.group_id = ? AND gm.is_active = TRUE
            GROUP BY gm.user_id
            HAVING COUNT(gp.id) = 0
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->bind_param("ii", $groupId, $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        // If all members have received at least once, select randomly
        $stmt = $this->conn->prepare("
            SELECT user_id FROM group_members 
            WHERE group_id = ? AND is_active = TRUE
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }
    
    private function getTotalContributions($cycleId) {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM group_contributions 
            WHERE cycle_id = ? AND status = 'completed'
        ");
        $stmt->bind_param("i", $cycleId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['total'];
    }
    
    private function getNextPayoutDate($frequency, $lastDate = null) {
        $date = $lastDate ? new \DateTime($lastDate) : new \DateTime();
        
        switch ($frequency) {
            case 'weekly':
                $date->modify('+1 week');
                break;
            case 'monthly':
                $date->modify('+1 month');
                break;
            default:
                $date->modify('+1 month');
        }
        
        return $date->format('Y-m-d');
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