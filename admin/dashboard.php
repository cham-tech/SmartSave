<?php
// File: /admin/dashboard.php

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /login.php');
    exit;
}

$conn = getDBConnection();

// Get stats for dashboard
$stats = [
    'total_users' => 0,
    'active_users' => 0,
    'total_loans' => 0,
    'pending_loans' => 0,
    'total_savings' => 0,
    'total_groups' => 0
];

// Total users
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
$stmt->execute();
$result = $stmt->get_result();
$stats['total_users'] = $result->fetch_assoc()['count'];
$stmt->close();

// Active users (logged in last 30 days)
$stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) as count FROM user_sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stmt->execute();
$result = $stmt->get_result();
$stats['active_users'] = $result->fetch_assoc()['count'];
$stmt->close();

// Total loans
$stmt = $conn->prepare("SELECT COUNT(*) as count, SUM(amount) as total FROM loans");
$stmt->execute();
$result = $stmt->get_result();
$loanData = $result->fetch_assoc();
$stats['total_loans'] = $loanData['count'];
$stats['total_loan_amount'] = $loanData['total'] ?? 0;
$stmt->close();

// Pending loans
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM loans WHERE status = 'pending'");
$stmt->execute();
$result = $stmt->get_result();
$stats['pending_loans'] = $result->fetch_assoc()['count'];
$stmt->close();

// Total savings
$stmt = $conn->prepare("SELECT SUM(current_amount) as total FROM savings_goals");
$stmt->execute();
$result = $stmt->get_result();
$stats['total_savings'] = $result->fetch_assoc()['total'] ?? 0;
$stmt->close();

// Total groups
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM saving_groups");
$stmt->execute();
$result = $stmt->get_result();
$stats['total_groups'] = $result->fetch_assoc()['count'];
$stmt->close();

// Recent activities
$recentActivities = [];
$stmt = $conn->prepare("
    (SELECT 'loan' as type, l.id, u.first_name, u.last_name, l.amount, l.created_at 
     FROM loans l JOIN users u ON l.user_id = u.id 
     ORDER BY l.created_at DESC LIMIT 5)
    
    UNION ALL
    
    (SELECT 'savings' as type, sg.id, u.first_name, u.last_name, st.amount, st.transaction_date as created_at
     FROM savings_transactions st
     JOIN savings_goals sg ON st.savings_goal_id = sg.id
     JOIN users u ON sg.user_id = u.id
     ORDER BY st.transaction_date DESC LIMIT 5)
    
    UNION ALL
    
    (SELECT 'group' as type, gc.id, u.first_name, u.last_name, gc.amount, gc.contribution_date as created_at
     FROM group_contributions gc
     JOIN users u ON gc.user_id = u.id
     ORDER BY gc.contribution_date DESC LIMIT 5)
    
    ORDER BY created_at DESC LIMIT 10
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentActivities[] = $row;
}
$stmt->close();

$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <h2>Admin Dashboard</h2>
        <p class="lead">Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h5 class="card-title">Total Users</h5>
                <h2 class="card-text"><?php echo $stats['total_users']; ?></h2>
                <small><?php echo $stats['active_users']; ?> active</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h5 class="card-title">Total Savings</h5>
                <h2 class="card-text"><?php echo formatCurrency($stats['total_savings']); ?></h2>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5 class="card-title">Total Loans</h5>
                <h2 class="card-text"><?php echo $stats['total_loans']; ?></h2>
                <small><?php echo formatCurrency($stats['total_loan_amount']); ?></small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <h5 class="card-title">Pending Loans</h5>
                <h2 class="card-text"><?php echo $stats['pending_loans']; ?></h2>
                <a href="loans.php" class="text-dark"><small>Review now</small></a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Recent Activities</h5>
            </div>
            <div class="card-body">
                <?php if (empty($recentActivities)): ?>
                    <p class="text-muted">No recent activities found.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($recentActivities as $activity): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1">
                                        <?php 
                                        $typeMap = [
                                            'loan' => 'Loan Request',
                                            'savings' => 'Savings Deposit',
                                            'group' => 'Group Contribution'
                                        ];
                                        echo $typeMap[$activity['type']];
                                        ?>
                                    </h6>
                                    <small><?php echo date('M j, Y', strtotime($activity['created_at'])); ?></small>
                                </div>
                                <p class="mb-1">
                                    <?php echo htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']); ?>
                                    <?php if (isset($activity['amount'])): ?>
                                        - <?php echo formatCurrency($activity['amount']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="loans.php" class="btn btn-outline-primary">Manage Loans</a>
                    <a href="savings.php" class="btn btn-outline-primary">View Savings</a>
                    <a href="groups.php" class="btn btn-outline-primary">Manage Groups</a>
                    <a href="users.php" class="btn btn-outline-primary">Manage Users</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
