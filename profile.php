<?php
// File: profile.php

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$userId = $_SESSION['user_id'];

// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    // Validate inputs
    if (empty($firstName) || empty($lastName) || empty($email) || empty($phone)) {
        $_SESSION['error'] = 'All fields are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email format';
    } else {
        // Check if email is already taken by another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $_SESSION['error'] = 'Email already taken by another user';
        } else {
            $stmt->close();
            
            // Check if phone is already taken by another user
            $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
            $stmt->bind_param("si", $phone, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $_SESSION['error'] = 'Phone number already taken by another user';
            } else {
                $stmt->close();
                
                // Update profile
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $firstName, $lastName, $email, $phone, $userId);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = 'Profile updated successfully';
                } else {
                    $_SESSION['error'] = 'Failed to update profile: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
    
    header("Location: profile.php");
    exit;
}

// Handle password change
if (isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $_SESSION['error'] = 'All password fields are required';
    } elseif ($newPassword !== $confirmPassword) {
        $_SESSION['error'] = 'New passwords do not match';
    } elseif (strlen($newPassword) < 8) {
        $_SESSION['error'] = 'Password must be at least 8 characters';
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (password_verify($currentPassword, $user['password'])) {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashedPassword, $userId);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = 'Password changed successfully';
            } else {
                $_SESSION['error'] = 'Failed to change password';
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = 'Current password is incorrect';
        }
    }
    
    header("Location: profile.php");
    exit;
}

// Get user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Get user stats
$stats = [
    'savings_goals' => 0,
    'active_loans' => 0,
    'saving_groups' => 0
];

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM savings_goals WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$stats['savings_goals'] = $result->fetch_assoc()['count'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM loans WHERE user_id = ? AND status IN ('approved', 'disbursed')");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$stats['active_loans'] = $result->fetch_assoc()['count'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM group_members WHERE user_id = ? AND is_active = TRUE");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$stats['saving_groups'] = $result->fetch_assoc()['count'];
$stmt->close();

$conn->close();

// Display messages
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
    unset($_SESSION['error']);
}

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
?>

<div class="row">
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-body text-center">
                <div class="mb-3">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width: 100px; height: 100px;">
                        <h2 class="mb-0"><?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?></h2>
                    </div>
                </div>
                <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                <p class="text-muted">Member since <?php echo date('M Y', strtotime($user['created_at'])); ?></p>
                
                <?php if ($user['is_admin']): ?>
                    <span class="badge bg-success">Admin</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">My Stats</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        Savings Goals
                        <span class="badge bg-primary rounded-pill"><?php echo $stats['savings_goals']; ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        Active Loans
                        <span class="badge bg-primary rounded-pill"><?php echo $stats['active_loans']; ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        Savings Circles
                        <span class="badge bg-primary rounded-pill"><?php echo $stats['saving_groups']; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Profile Information</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="profile.php">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                   value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                   value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="profile.php">
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>