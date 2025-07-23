<?php
// File: /includes/auth.php

function registerUser($firstName, $lastName, $email, $phone, $password, $isAdmin = false) {
    $conn = getDBConnection();
    
    // Check if email or phone already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
    $stmt->bind_param("ss", $email, $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        $conn->close();
        return ['success' => false, 'message' => 'Email or phone number already registered'];
    }
    
    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, phone, password, is_admin) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $firstName, $lastName, $email, $phone, $hashedPassword, $isAdmin);
    
    if ($stmt->execute()) {
        $userId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
        
        // Create welcome notification
        createNotification($userId, 'Welcome to SmartSave Circle', 'Thank you for registering with SmartSave Circle. Start your savings journey today!');
        
        return ['success' => true, 'user_id' => $userId];
    } else {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        return ['success' => false, 'message' => 'Registration failed: ' . $error];
    }
}

function loginUser($email, $password) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT id, password, is_admin FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['is_admin'] = $user['is_admin'];
            
            $stmt->close();
            $conn->close();
            return ['success' => true];
        }
    }
    
    $stmt->close();
    $conn->close();
    return ['success' => false, 'message' => 'Invalid email or password'];
}

function logoutUser() {
    session_unset();
    session_destroy();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
}

function getUserById($userId) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    $stmt->close();
    $conn->close();
    
    return $user;
}

function createNotification($userId, $title, $message) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $title, $message);
    $stmt->execute();
    
    $stmt->close();
    $conn->close();
}
?>
