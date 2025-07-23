<?php
// File: /src/User.php

namespace SmartSaveCircle;

class User {
    private $conn;
    
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }
    
    public function register($firstName, $lastName, $email, $phone, $password, $isAdmin = false) {
        // Check if email or phone already exists
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
        $stmt->bind_param("ss", $email, $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return ['success' => false, 'message' => 'Email or phone number already registered'];
        }
        
        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user
        $stmt = $this->conn->prepare("INSERT INTO users (first_name, last_name, email, phone, password, is_admin) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $firstName, $lastName, $email, $phone, $hashedPassword, $isAdmin);
        
        if ($stmt->execute()) {
            $userId = $stmt->insert_id;
            return ['success' => true, 'user_id' => $userId];
        } else {
            return ['success' => false, 'message' => 'Registration failed: ' . $stmt->error];
        }
    }
    
    public function login($email, $password) {
        $stmt = $this->conn->prepare("SELECT id, password, is_admin FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                return ['success' => true, 'user_id' => $user['id'], 'is_admin' => $user['is_admin']];
            }
        }
        
        return ['success' => false, 'message' => 'Invalid email or password'];
    }
    
    public function getUserById($userId) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    public function updateProfile($userId, $firstName, $lastName, $email, $phone) {
        // Check if email is taken by another user
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return ['success' => false, 'message' => 'Email already taken by another user'];
        }
        
        // Check if phone is taken by another user
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
        $stmt->bind_param("si", $phone, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return ['success' => false, 'message' => 'Phone number already taken by another user'];
        }
        
        // Update profile
        $stmt = $this->conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $firstName, $lastName, $email, $phone, $userId);
        
        if ($stmt->execute()) {
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => 'Failed to update profile'];
        }
    }
    
    public function changePassword($userId, $currentPassword, $newPassword) {
        // Verify current password
        $stmt = $this->conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        // Update password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashedPassword, $userId);
        
        if ($stmt->execute()) {
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => 'Failed to change password'];
        }
    }
}
?>