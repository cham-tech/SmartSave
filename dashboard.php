<?php
// File: dashboard.php

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$userId = $_SESSION['user_id'];

// Get user's savings goals
$savingsGoals = [];
$stmt = $conn->prepare("SELECT * FROM savings_goals WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $savingsGoals[] = $row;
}
$stmt->close();

// Get user's active loans
$activeLoans = [];
$stmt = $conn->prepare("SELECT * FROM loans WHERE user_id = ? AND status IN ('approved', 'disbursed') ORDER BY created_at DESC LIMIT 3");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activeLoans[] = $row;
}
$stmt->close();

// Get user's saving groups
$savingGroups = [];
$stmt = $conn->prepare("
    SELECT sg.* FROM saving_groups sg
    JOIN group_members gm ON sg.id = gm.group_id
    WHERE gm.user_id = ? AND gm.is_active = TRUE
    ORDER BY sg.created_at DESC LIMIT 3
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $savingGroups[] = $row;
}
$stmt->close();

// Get recent transactions (savings and loans)
$recentTransactions = [];
$stmt = $conn->prepare("
    (SELECT 'saving' as type, st.amount, transaction_date as date, transaction_reference as reference 
     FROM savings_transactions st
     JOIN savings_goals sg ON st.savings_goal_id = sg.id
     WHERE sg.user_id = ? AND st.status = 'completed'
     ORDER BY st.transaction_date DESC LIMIT 3)
    
    UNION ALL
    
    (SELECT 'loan' as type, lr.amount, repayment_date as date, transaction_reference as reference 
     FROM loan_repayments lr
     JOIN loans l ON lr.loan_id = l.id
     WHERE l.user_id = ? AND lr.status = 'completed'
     ORDER BY lr.repayment_date DESC LIMIT 3)
    
    ORDER BY date DESC LIMIT 5
");
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentTransactions[] = $row;
}
$stmt->close();

$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <h2>Dashboard</h2>
        <p class="lead">Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</p>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="savings.php?action=new" class="btn btn-outline-primary">Create Savings Goal</a>
                    <a href="loans.php?action=new" class="btn btn-outline-primary">Request a Loan</a>
                    <a href="groups.php?action=new" class="btn btn-outline-primary">Start a Savings Circle</a>
                    <a href="groups.php" class="btn btn-outline-primary">Join a Savings Circle</a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">Recent Activity</h5>
            </div>
            <div class="card-body">
                <?php if (empty($recentTransactions)): ?>
                    <p class="text-muted">No recent transactions found.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1">
                                        <?php echo $transaction['type'] == 'saving' ? 'Savings Deposit' : 'Loan Repayment'; ?>
                                    </h6>
                                    <small><?php echo date('M j, Y', strtotime($transaction['date'])); ?></small>
                                </div>
                                <p class="mb-1"><?php echo formatCurrency($transaction['amount']); ?></p>
                                <small class="text-muted">Ref: <?php echo $transaction['reference']; ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">My Savings Goals</h5>
            </div>
            <div class="card-body">
                <?php if (empty($savingsGoals)): ?>
                    <p class="text-muted">You don't have any savings goals yet.</p>
                    <a href="savings.php?action=new" class="btn btn-sm btn-primary">Create a Savings Goal</a>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($savingsGoals as $goal): ?>
                            <a href="savings.php?id=<?php echo $goal['id']; ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($goal['goal_name']); ?></h6>
                                    <small><?php echo ucfirst($goal['frequency']); ?></small>
                                </div>
                                <p class="mb-1">
                                    <?php echo formatCurrency($goal['current_amount']); ?> of <?php echo formatCurrency($goal['target_amount']); ?>
                                </p>
                                <div class="progress mb-1" style="height: 10px;">
                                    <div class="progress-bar bg-success" 
                                         style="width: <?php echo getSavingsProgressPercentage($goal['current_amount'], $goal['target_amount']); ?>%">
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?php if ($goal['is_completed']): ?>
                                        <span class="text-success">Goal achieved!</span>
                                    <?php else: ?>
                                        Next: <?php echo date('M j, Y', strtotime($goal['next_saving_date'])); ?>
                                    <?php endif; ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 text-end">
                        <a href="savings.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">My Loans</h5>
            </div>
            <div class="card-body">
                <?php if (empty($activeLoans)): ?>
                    <p class="text-muted">You don't have any active loans.</p>
                    <a href="loans.php?action=new" class="btn btn-sm btn-primary">Request a Loan</a>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($activeLoans as $loan): ?>
                            <a href="loans.php?id=<?php echo $loan['id']; ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1">Loan #<?php echo $loan['id']; ?></h6>
                                    <small class="text-<?php echo $loan['status'] == 'approved' ? 'warning' : 'primary'; ?>">
                                        <?php echo ucfirst($loan['status']); ?>
                                    </small>
                                </div>
                                <p class="mb-1"><?php echo formatCurrency($loan['amount']); ?></p>
                                <small class="text-muted">
                                    <?php if ($loan['due_date']): ?>
                                        Due: <?php echo date('M j, Y', strtotime($loan['due_date'])); ?>
                                    <?php else: ?>
                                        Pending approval
                                    <?php endif; ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 text-end">
                        <a href="loans.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">My Savings Circles</h5>
            </div>
            <div class="card-body">
                <?php if (empty($savingGroups)): ?>
                    <p class="text-muted">You're not part of any savings circles yet.</p>
                    <a href="groups.php" class="btn btn-sm btn-primary">Join a Savings Circle</a>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($savingGroups as $group): ?>
                            <a href="groups.php?id=<?php echo $group['id']; ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($group['name']); ?></h6>
                                    <small><?php echo ucfirst($group['cycle_frequency']); ?></small>
                                </div>
                                <p class="mb-1"><?php echo formatCurrency($group['amount_per_cycle']); ?> per cycle</p>
                                <small class="text-muted"><?php echo htmlspecialchars($group['description']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 text-end">
                        <a href="groups.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
