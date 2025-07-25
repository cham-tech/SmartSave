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

// Fetch Savings Goals
$savingsGoals = [];
$stmt = $conn->prepare("SELECT * FROM savings_goals WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $savingsGoals[] = $row;
}
$stmt->close();

// Fetch Active Loans
$activeLoans = [];
$stmt = $conn->prepare("SELECT * FROM loans WHERE user_id = ? AND status IN ('approved', 'disbursed') ORDER BY created_at DESC LIMIT 3");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activeLoans[] = $row;
}
$stmt->close();



// Fetch Recent Transactions
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

<div class="container-fluid py-4">
    <!-- Welcome Message -->
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="fw-bold">Dashboard</h2>
            <p class="lead mb-0">Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</p>
        </div>
    </div>

    <!-- Quick Actions & Recent Activity -->
    <div class="row g-4 mb-4">
        <!-- Quick Actions -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-3">
                        <a href="savings.php?action=new" class="btn btn-outline-primary">Create Savings Goal</a>
                        <a href="loans.php?action=new" class="btn btn-outline-primary">Request a Loan</a>
                        <a href="groups.php?action=new" class="btn btn-outline-primary">Start a Savings Circle</a>
                        <a href="groups.php" class="btn btn-outline-primary">Join a Savings Circle</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Recent Activity</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recentTransactions)): ?>
                        <p class="text-primary">No recent transactions found.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($recentTransactions as $transaction): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between text-primary">
                                        <h6 class="mb-1">
                                            <?php echo $transaction['type'] === 'saving' ? 'Savings Deposit' : 'Loan Repayment'; ?>
                                        </h6>
                                        <small><?php echo date('M j, Y', strtotime($transaction['date'])); ?></small>
                                    </div>
                                    <p class="mb-1 fw-semibold text-primary"><?php echo formatCurrency($transaction['amount']); ?></p>
                                    <small class="text-primary">Ref: <?php echo $transaction['reference']; ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Savings Goals & Loans -->
    <div class="row g-4 mb-4 ">
        <!-- Savings Goals -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">My Savings Goals</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($savingsGoals)): ?>
                        <p class="text-primary">You don't have any savings goals yet.</p>
                        <a href="savings.php?action=new" class="btn btn-sm btn-primary">Create a Savings Goal</a>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($savingsGoals as $goal): ?>
                                <a href="savings.php?id=<?php echo $goal['id']; ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between text-primary">
                                        <h6 class="mb-1 fw-semibold "><?php echo htmlspecialchars($goal['goal_name']); ?></h6>
                                        <small><?php echo ucfirst($goal['frequency']); ?></small>
                                    </div>
                                    <p class="mb-1">
                                        <?php echo formatCurrency($goal['current_amount']); ?> of <?php echo formatCurrency($goal['target_amount']); ?>
                                    </p>
                                 <div class="progress mb-2" style="height: 10px;">
  <div class="progress-bar" 
       style="width: <?php echo getSavingsProgressPercentage($goal['current_amount'], $goal['target_amount']); ?>%; background-color: #ffc107;">
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
                        <div class="text-end mt-3">
                            <a href="savings.php" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        
                    <?php endif; ?>
                </div>
            </div>
        </div>
                                        </div>
                                        </div>
        
<?php require_once __DIR__ . '/includes/footer.php'; ?>
