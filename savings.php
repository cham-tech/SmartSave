<?php
// Start output buffering at the very top
ob_start();

// File: loans.php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

// Check authentication and redirect if needed
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Then include header.php
require_once __DIR__ . '/includes/header.php';

require_once __DIR__ . '/includes/functions.php';

// Rest of your code...

$conn = getDBConnection();
$userId = $_SESSION['user_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_goal'])) {
        // Create new savings goal
        $goalName = trim($_POST['goal_name']);
        $targetAmount = floatval($_POST['target_amount']);
        $frequency = $_POST['frequency'];
        
        if (empty($goalName) || $targetAmount <= 0) {
            $_SESSION['error'] = 'Please provide valid goal details';
        } else {
            $nextSavingDate = getNextPayoutDate($frequency);
            
            $stmt = $conn->prepare("INSERT INTO savings_goals (user_id, goal_name, target_amount, frequency, next_saving_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $userId, $goalName, $targetAmount, $frequency, $nextSavingDate);
            
            if ($stmt->execute()) {
                $goalId = $stmt->insert_id;
                $stmt->close();
                
                createNotification($userId, 'New Savings Goal', "You created a new savings goal: $goalName");
                $_SESSION['success'] = 'Savings goal created successfully!';
                header("Location: savings.php?id=$goalId");
                exit;
            } else {
                $_SESSION['error'] = 'Failed to create savings goal: ' . $stmt->error;
            }
        }
    } elseif (isset($_POST['add_savings'])) {
        // Add to savings goal
        $goalId = intval($_POST['goal_id']);
        $amount = floatval($_POST['amount']);
        
        // Verify goal belongs to user
        $stmt = $conn->prepare("SELECT id FROM savings_goals WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $goalId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $_SESSION['error'] = 'Invalid savings goal';
            header("Location: savings.php");
            exit;
        }
        
        $stmt->close();
        
        if ($amount <= 0) {
            $_SESSION['error'] = 'Please enter a valid amount';
            header("Location: savings.php?id=$goalId");
            exit;
        }
        
        // Process payment via Bitnob
        $user = getUserById($userId);
        $reference = 'SAVE-' . uniqid();
        $narration = "Savings deposit for goal ID: $goalId";
        
        $paymentResult = processBitnobPayment($user['phone'], $amount, $reference, $narration);
        
        if ($paymentResult['success']) {
            // Update savings goal
            $stmt = $conn->prepare("UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ?");
            $stmt->bind_param("di", $amount, $goalId);
            $stmt->execute();
            $stmt->close();
            
            // Record transaction
            $stmt = $conn->prepare("INSERT INTO savings_transactions (savings_goal_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'completed')");
            $stmt->bind_param("ids", $goalId, $amount, $reference);
            $stmt->execute();
            $stmt->close();
            
            // Check if goal is completed
            $stmt = $conn->prepare("SELECT target_amount, current_amount FROM savings_goals WHERE id = ?");
            $stmt->bind_param("i", $goalId);
            $stmt->execute();
            $result = $stmt->get_result();
            $goal = $result->fetch_assoc();
            $stmt->close();
            
            if ($goal['current_amount'] >= $goal['target_amount']) {
                $stmt = $conn->prepare("UPDATE savings_goals SET is_completed = TRUE WHERE id = ?");
                $stmt->bind_param("i", $goalId);
                $stmt->execute();
                $stmt->close();
                
                createNotification($userId, 'Goal Achieved!', "Congratulations! You've reached your savings goal: " . htmlspecialchars($_POST['goal_name']));
            }
            
            $_SESSION['success'] = 'Savings deposit successful!';
        } else {
            // Record failed transaction
            $stmt = $conn->prepare("INSERT INTO savings_transactions (savings_goal_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'failed')");
            $stmt->bind_param("ids", $goalId, $amount, $reference);
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['error'] = 'Payment failed: ' . $paymentResult['error'];
        }
        
        header("Location: savings.php?id=$goalId");
        exit;
    } elseif (isset($_POST['withdraw_savings'])) {
        // Withdraw from savings goal
        $goalId = intval($_POST['goal_id']);
        $amount = floatval($_POST['amount']);
        
        // Get goal details
        $stmt = $conn->prepare("SELECT id, goal_name, target_amount, current_amount, is_completed FROM savings_goals WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $goalId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $_SESSION['error'] = 'Invalid savings goal';
            header("Location: savings.php");
            exit;
        }
        
        $goal = $result->fetch_assoc();
        $stmt->close();
        
        if ($amount <= 0 || $amount > $goal['current_amount']) {
            $_SESSION['error'] = 'Invalid withdrawal amount';
            header("Location: savings.php?id=$goalId");
            exit;
        }
        
        // Check if early withdrawal (not completed and not reaching target)
        $isEarlyWithdrawal = !$goal['is_completed'] && ($goal['current_amount'] - $amount) < $goal['target_amount'];
        $penalty = $isEarlyWithdrawal ? $amount * EARLY_WITHDRAWAL_PENALTY : 0;
        $totalDeduction = $amount + $penalty;
        
        // Process withdrawal
        $user = getUserById($userId);
        $reference = 'WTH-' . uniqid();
        $narration = "Withdrawal from savings goal: " . $goal['goal_name'];
        
        $paymentResult = processBitnobPayment($user['phone'], $amount, $reference, $narration);
        
        if ($paymentResult['success']) {
            // Update savings goal
            $stmt = $conn->prepare("UPDATE savings_goals SET current_amount = current_amount - ? WHERE id = ?");
            $stmt->bind_param("di", $totalDeduction, $goalId);
            $stmt->execute();
            $stmt->close();
            
            // Record transaction
            $stmt = $conn->prepare("INSERT INTO savings_transactions (savings_goal_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'completed')");
            $negativeAmount = -$amount;
            $stmt->bind_param("ids", $goalId, $negativeAmount, $reference);
            $stmt->execute();
            $stmt->close();
            
            if ($isEarlyWithdrawal) {
                createNotification($userId, 'Early Withdrawal', "You made an early withdrawal from your savings goal. A penalty of " . formatCurrency($penalty) . " was applied.");
            }
            
            $_SESSION['success'] = 'Withdrawal successful!';
            if ($isEarlyWithdrawal) {
                $_SESSION['success'] .= ' A penalty of ' . formatCurrency($penalty) . ' was applied for early withdrawal.';
            }
        } else {
            $_SESSION['error'] = 'Withdrawal failed: ' . $paymentResult['error'];
        }
        
        header("Location: savings.php?id=$goalId");
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

// Check if viewing/editing a single goal
if (isset($_GET['id'])) {
    $goalId = intval($_GET['id']);
    
    // Get goal details
    $stmt = $conn->prepare("SELECT * FROM savings_goals WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $goalId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        header("Location: savings.php");
        exit;
    }
    
    $goal = $result->fetch_assoc();
    $stmt->close();
    
    // Get transactions for this goal
    $transactions = [];
    $stmt = $conn->prepare("SELECT * FROM savings_transactions WHERE savings_goal_id = ? ORDER BY transaction_date DESC");
    $stmt->bind_param("i", $goalId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();
    
    // Display single goal view
    ?>
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="savings.php">My Savings</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($goal['goal_name']); ?></li>
                </ol>
            </nav>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Savings Goal Details</h5>
                </div>
                <div class="card-body">
                    <h4><?php echo htmlspecialchars($goal['goal_name']); ?></h4>
                    <p class="lead"><?php echo formatCurrency($goal['current_amount']); ?> of <?php echo formatCurrency($goal['target_amount']); ?></p>
                    
                    <div class="progress mb-4" style="height: 20px;">
                        <div class="progress-bar progress-bar-striped bg-success" 
                             style="width: <?php echo getSavingsProgressPercentage($goal['current_amount'], $goal['target_amount']); ?>%">
                            <?php echo getSavingsProgressPercentage($goal['current_amount'], $goal['target_amount']); ?>%
                        </div>
                    </div>
                    
                    <dl class="row">
                        <dt class="col-sm-3">Frequency</dt>
                        <dd class="col-sm-9"><?php echo ucfirst($goal['frequency']); ?></dd>
                        
                        <dt class="col-sm-3">Next Saving Date</dt>
                        <dd class="col-sm-9"><?php echo date('M j, Y', strtotime($goal['next_saving_date'])); ?></dd>
                        
                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            <?php if ($goal['is_completed']): ?>
                                <span class="badge bg-success">Completed</span>
                            <?php else: ?>
                                <span class="badge bg-warning">In Progress</span>
                            <?php endif; ?>
                        </dd>
                        
                        <dt class="col-sm-3">Created</dt>
                        <dd class="col-sm-9"><?php echo date('M j, Y', strtotime($goal['created_at'])); ?></dd>
                    </dl>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Transactions</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($transactions)): ?>
                        <p class="text-muted">No transactions yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                            <td class="<?php echo $transaction['amount'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo formatCurrency($transaction['amount']); ?>
                                            </td>
                                            <td><?php echo $transaction['transaction_reference']; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $transaction['status'] == 'completed' ? 'success' : ($transaction['status'] == 'pending' ? 'warning' : 'danger'); ?>">
                                                    <?php echo ucfirst($transaction['status']); ?>
                                                </span>
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
        
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Add to Savings</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="savings.php">
                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                        <div class="mb-3">
                            <label for="amount" class="form-label">Amount</label>
                            <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" required>
                        </div>
                        <button type="submit" name="add_savings" class="btn btn-primary w-100">Deposit</button>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Withdraw from Savings</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="savings.php">
                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                        <div class="mb-3">
                            <label for="withdraw_amount" class="form-label">Amount</label>
                            <input type="number" class="form-control" id="withdraw_amount" name="amount" 
                                   min="0.01" max="<?php echo $goal['current_amount']; ?>" step="0.01" required>
                            <small class="text-muted">Max: <?php echo formatCurrency($goal['current_amount']); ?></small>
                        </div>
                        <?php if (!$goal['is_completed'] && $goal['current_amount'] < $goal['target_amount']): ?>
                            <div class="alert alert-warning">
                                <small>Early withdrawal penalty: <?php echo EARLY_WITHDRAWAL_PENALTY; ?>× the amount</small>
                            </div>
                        <?php endif; ?>
                        <button type="submit" name="withdraw_savings" class="btn btn-outline-danger w-100">Withdraw</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
} elseif (isset($_GET['action']) && $_GET['action'] == 'new') {
    // Display new goal form
    ?>
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Create New Savings Goal</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="savings.php">
                        <div class="mb-3">
                            <label for="goal_name" class="form-label">Goal Name</label>
                            <input type="text" class="form-control" id="goal_name" name="goal_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="target_amount" class="form-label">Target Amount (<?php echo APP_CURRENCY; ?>)</label>
                            <input type="number" class="form-control" id="target_amount" name="target_amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="frequency" class="form-label">Saving Frequency</label>
                            <select class="form-select" id="frequency" name="frequency" required>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                            </select>
                        </div>
                        <button type="submit" name="create_goal" class="btn btn-primary">Create Goal</button>
                        <a href="savings.php" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
} else {
    // Display list of all savings goals
    $goals = [];
    $stmt = $conn->prepare("SELECT * FROM savings_goals WHERE user_id = ? ORDER BY is_completed, created_at DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $goals[] = $row;
    }
    $stmt->close();
    ?>
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>My Savings Goals</h2>
                <a href="savings.php?action=new" class="btn btn-primary">New Savings Goal</a>
            </div>
            
            <?php if (empty($goals)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <h4 class="text-muted">You don't have any savings goals yet</h4>
                        <p>Start saving towards your goals by creating a new savings plan</p>
                        <a href="savings.php?action=new" class="btn btn-primary">Create Savings Goal</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($goals as $goal): ?>
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <h5><?php echo htmlspecialchars($goal['goal_name']); ?></h5>
                                        <span class="badge bg-<?php echo $goal['is_completed'] ? 'success' : 'warning'; ?>">
                                            <?php echo $goal['is_completed'] ? 'Completed' : 'Active'; ?>
                                        </span>
                                    </div>
                                    <p class="text-muted">Target: <?php echo formatCurrency($goal['target_amount']); ?></p>
                                    
                                    <div class="progress mb-3" style="height: 10px;">
                                        <div class="progress-bar bg-success" 
                                             style="width: <?php echo getSavingsProgressPercentage($goal['current_amount'], $goal['target_amount']); ?>%">
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-2">
                                        <small>Saved: <?php echo formatCurrency($goal['current_amount']); ?></small>
                                        <small><?php echo getSavingsProgressPercentage($goal['current_amount'], $goal['target_amount']); ?>%</small>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <?php echo ucfirst($goal['frequency']); ?> savings
                                        </small>
                                        <a href="savings.php?id=<?php echo $goal['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

$conn->close();
require_once __DIR__ . '/includes/footer.php';
?>