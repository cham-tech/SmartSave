<?php
// File: /admin/users.php

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /login.php');
    exit;
}

$conn = getDBConnection();

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['toggle_admin'])) {
        $userId = intval($_POST['user_id']);
        $isAdmin = intval($_POST['is_admin']);
        
        $stmt = $conn->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
        $stmt->bind_param("ii", $isAdmin, $userId);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = 'User admin status updated successfully';
        } else {
            $_SESSION['error'] = 'Failed to update user admin status';
        }
        $stmt->close();
        
        header("Location: users.php");
        exit;
    }
}

// Display messages
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
    unset($_SESSION['error']);
}

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}

// Get all users
$users = [];
$stmt = $conn->prepare("SELECT * FROM users ORDER BY created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

// Get stats
$totalUsers = count($users);
$adminUsers = 0;
foreach ($users as $user) {
    if ($user['is_admin']) {
        $adminUsers++;
    }
}

$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>User Management</h2>
            <div>
                <span class="badge bg-primary">Total Users: <?php echo $totalUsers; ?></span>
                <span class="badge bg-success ms-2">Admins: <?php echo $adminUsers; ?></span>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">All Users</h5>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <div class="alert alert-info">No users found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Admin</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo $user['id']; ?></td>
                                        <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo htmlspecialchars($user['phone']); ?></td>
                                        <td>
                                            <?php if ($user['is_admin']): ?>
                                                <span class="badge bg-success">Yes</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <form method="POST" action="users.php" class="d-inline">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <input type="hidden" name="is_admin" value="<?php echo $user['is_admin'] ? 0 : 1; ?>">
                                                <button type="submit" name="toggle_admin" class="btn btn-sm btn-<?php echo $user['is_admin'] ? 'danger' : 'success'; ?>">
                                                    <?php echo $user['is_admin'] ? 'Remove Admin' : 'Make Admin'; ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
