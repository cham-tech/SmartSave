<?php
// File: /admin/savings.php

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /login.php');
    exit;
}

$conn = getDBConnection();

// Get all savings goals
$savingsGoals = [];
$stmt = $conn->prepare("
    SELECT sg.*, u.first_name, u.last_name 
    FROM savings_goals sg
    JOIN users u ON sg.user_id = u.id
    ORDER BY sg.created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $savingsGoals[] = $row;
}
$stmt->close();

// Get total savings
$totalSavings = 0;
$stmt = $conn->prepare("SELECT SUM(current_amount) as total FROM savings_goals");
$stmt->execute();
$result = $stmt->get_result();
$totalSavings = $result->fetch_assoc()['total'] ?? 0;
$stmt->close();

$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Savings Management</h2>
            <div>
                <span class="badge bg-primary">Total Savings: <?php echo formatCurrency($totalSavings); ?></span>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">All Savings Goals</h5>
            </div>
            <div class="card-body">
                <?php if (empty($savingsGoals)): ?>
                    <div class="alert alert-info">No savings goals found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Goal Name</th>
                                    <th>Target</th>
                                    <th>Saved</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($savingsGoals as $goal): ?>
                                    <tr>
                                        <td><?php echo $goal['id']; ?></td>
                                        <td><?php echo htmlspecialchars($goal['first_name'] . ' ' . $goal['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($goal['goal_name']); ?></td>
                                        <td><?php echo formatCurrency($goal['target_amount']); ?></td>
                                        <td><?php echo formatCurrency($goal['current_amount']); ?></td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar" 
                                                     style="width: <?php echo getSavingsProgressPercentage($goal['current_amount'], $goal['target_amount']); ?>%">
                                                    <?php echo getSavingsProgressPercentage($goal['current_amount'], $goal['target_amount']); ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($goal['is_completed']): ?>
                                                <span class="badge bg-success">Completed</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">In Progress</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="/savings.php?id=<?php echo $goal['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
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
