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
    if (isset($_POST['request_loan'])) {
        // Request a new loan
        $amount = floatval($_POST['amount']);
        $purpose = trim($_POST['purpose']);
        
        if ($amount <= 0 || empty($purpose)) {
            $_SESSION['error'] = 'Please provide valid loan details';
        } else {
            $stmt = $conn->prepare("INSERT INTO loans (user_id, amount, purpose) VALUES (?, ?, ?)");
            $stmt->bind_param("ids", $userId, $amount, $purpose);
            
            if ($stmt->execute()) {
                $loanId = $stmt->insert_id;
                $stmt->close();
                
                createNotification($userId, 'Loan Request Submitted', "Your loan request for " . formatCurrency($amount) . " has been submitted for approval.");
                
                // Notify admins
                $stmt = $conn->prepare("SELECT id FROM users WHERE is_admin = TRUE");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($admin = $result->fetch_assoc()) {
                    createNotification($admin['id'], 'New Loan Request', "A new loan request for " . formatCurrency($amount) . " needs approval.");
                }
                $stmt->close();
                
                $_SESSION['success'] = 'Loan request submitted successfully!';
                header("Location: loans.php?id=$loanId");
                exit;
            } else {
                $_SESSION['error'] = 'Failed to submit loan request: ' . $stmt->error;
            }
        }
    } elseif (isset($_POST['repay_loan'])) {
        // Repay a loan
        $loanId = intval($_POST['loan_id']);
        $amount = floatval($_POST['amount']);
        
        // Verify loan belongs to user
        $stmt = $conn->prepare("SELECT id, amount FROM loans WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $loanId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $_SESSION['error'] = 'Invalid loan';
            header("Location: loans.php");
            exit;
        }
        
        $loan = $result->fetch_assoc();
        $stmt->close();
        
        if ($amount <= 0) {
            $_SESSION['error'] = 'Please enter a valid amount';
            header("Location: loans.php?id=$loanId");
            exit;
        }
        
        // Process payment via Bitnob
        $user = getUserById($userId);
        $reference = 'REPAY-' . uniqid();
        $narration = "Loan repayment for loan ID: $loanId";
        
        $paymentResult = processBitnobPayment($user['phone'], $amount, $reference, $narration);
        
        if ($paymentResult['success']) {
            // Record repayment
            $stmt = $conn->prepare("INSERT INTO loan_repayments (loan_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'completed')");
            $stmt->bind_param("ids", $loanId, $amount, $reference);
            $stmt->execute();
            $stmt->close();
            
            // Check if loan is fully paid
            $stmt = $conn->prepare("
                SELECT l.amount, COALESCE(SUM(lr.amount), 0) as total_paid 
                FROM loans l
                LEFT JOIN loan_repayments lr ON l.id = lr.loan_id AND lr.status = 'completed'
                WHERE l.id = ?
                GROUP BY l.id
            ");
            $stmt->bind_param("i", $loanId);
            $stmt->execute();
            $result = $stmt->get_result();
            $loanStatus = $result->fetch_assoc();
            $stmt->close();
            
            if ($loanStatus['total_paid'] >= $loanStatus['amount']) {
                $stmt = $conn->prepare("UPDATE loans SET status = 'paid' WHERE id = ?");
                $stmt->bind_param("i", $loanId);
                $stmt->execute();
                $stmt->close();
                
                createNotification($userId, 'Loan Paid', "Congratulations! You've fully paid your loan of " . formatCurrency($loanStatus['amount']) . ".");
            }
            
            $_SESSION['success'] = 'Loan repayment successful!';
        } else {
            // Record failed repayment
            $stmt = $conn->prepare("INSERT INTO loan_repayments (loan_id, amount, transaction_reference, status) VALUES (?, ?, ?, 'failed')");
            $stmt->bind_param("ids", $loanId, $amount, $reference);
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['error'] = 'Payment failed: ' . $paymentResult['error'];
        }
        
        header("Location: loans.php?id=$loanId");
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

// Check if viewing a single loan
if (isset($_GET['id'])) {
    $loanId = intval($_GET['id']);
    
    // Get loan details
    $stmt = $conn->prepare("SELECT l.*, u.first_name, u.last_name FROM loans l JOIN users u ON l.user_id = u.id WHERE l.id = ? AND (l.user_id = ? OR ?)");
    $isAdmin = isAdmin() ? 1 : 0;
    $stmt->bind_param("iii", $loanId, $userId, $isAdmin);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        header("Location: loans.php");
        exit;
    }
    
    $loan = $result->fetch_assoc();
    $stmt->close();
    
    // Get repayments for this loan
    $repayments = [];
    $stmt = $conn->prepare("SELECT * FROM loan_repayments WHERE loan_id = ? ORDER BY repayment_date DESC");
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $repayments[] = $row;
    }
    $stmt->close();
    
    // Calculate total paid
    $totalPaid = 0;
    foreach ($repayments as $repayment) {
        if ($repayment['status'] == 'completed') {
            $totalPaid += $repayment['amount'];
        }
    }
    
    $balance = $loan['amount'] - $totalPaid;
    
    // Display single loan view
    ?>
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="loans.php">My Loans</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Loan #<?php echo $loan['id']; ?></li>
                </ol>
            </nav>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Loan Details</h5>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">Loan Amount</dt>
                        <dd class="col-sm-9"><?php echo formatCurrency($loan['amount']); ?></dd>
                        
                        <dt class="col-sm-3">Amount Paid</dt>
                        <dd class="col-sm-9"><?php echo formatCurrency($totalPaid); ?></dd>
                        
                        <dt class="col-sm-3">Balance</dt>
                        <dd class="col-sm-9"><?php echo formatCurrency($balance); ?></dd>
                        
                        <dt class="col-sm-3">Purpose</dt>
                        <dd class="col-sm-9"><?php echo htmlspecialchars($loan['purpose']); ?></dd>
                        
                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            <?php 
                            $statusClass = [
                                'pending' => 'warning',
                                'approved' => 'info',
                                'rejected' => 'danger',
                                'disbursed' => 'primary',
                                'paid' => 'success'
                            ];
                            ?>
                            <span class="badge bg-<?php echo $statusClass[strtolower($loan['status'])]; ?>">
                                <?php echo ucfirst($loan['status']); ?>
                            </span>
                        </dd>
                        
                        <?php if ($loan['status'] == 'approved' || $loan['status'] == 'disbursed' || $loan['status'] == 'paid'): ?>
                            <dt class="col-sm-3">Approved By</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></dd>
                            
                            <dt class="col-sm-3">Approved Date</dt>
                            <dd class="col-sm-9"><?php echo date('M j, Y', strtotime($loan['approved_at'])); ?></dd>
                            
                            <?php if ($loan['status'] == 'disbursed' || $loan['status'] == 'paid'): ?>
                                <dt class="col-sm-3">Disbursed Date</dt>
                                <dd class="col-sm-9"><?php echo date('M j, Y', strtotime($loan['disbursed_at'])); ?></dd>
                                
                                <dt class="col-sm-3">Due Date</dt>
                                <dd class="col-sm-9"><?php echo date('M j, Y', strtotime($loan['due_date'])); ?></dd>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dl>
                    
                    <?php if (isAdmin() && $loan['status'] == 'pending'): ?>
                        <div class="d-flex gap-2 mt-4">
                            <form method="POST" action="/api/loan_action.php" class="d-inline">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success">Approve</button>
                            </form>
                            <form method="POST" action="/api/loan_action.php" class="d-inline">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn btn-danger">Reject</button>
                            </form>
                        </div>
                    <?php elseif (isAdmin() && $loan['status'] == 'approved'): ?>
                        <form method="POST" action="/api/loan_action.php" class="d-inline mt-4">
                            <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                            <input type="hidden" name="action" value="disburse">
                            <button type="submit" class="btn btn-primary">Disburse Funds</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Repayment History</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($repayments)): ?>
                        <p class="text-muted">No repayments yet.</p>
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
                                    <?php foreach ($repayments as $repayment): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($repayment['repayment_date'])); ?></td>
                                            <td><?php echo formatCurrency($repayment['amount']); ?></td>
                                            <td><?php echo $repayment['transaction_reference']; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $repayment['status'] == 'completed' ? 'success' : ($repayment['status'] == 'pending' ? 'warning' : 'danger'); ?>">
                                                    <?php echo ucfirst($repayment['status']); ?>
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
            <?php if ($loan['status'] == 'disbursed' && $balance > 0): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Make Repayment</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="loans.php">
                            <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                            <div class="mb-3">
                                <label for="repay_amount" class="form-label">Amount</label>
                                <input type="number" class="form-control" id="repay_amount" name="amount" 
                                       min="0.01" max="<?php echo $balance; ?>" step="0.01" value="<?php echo $balance; ?>" required>
                                <small class="text-muted">Balance: <?php echo formatCurrency($balance); ?></small>
                            </div>
                            <button type="submit" name="repay_loan" class="btn btn-primary w-100">Repay</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Loan Summary</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Loan Amount:</span>
                        <strong><?php echo formatCurrency($loan['amount']); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Amount Paid:</span>
                        <strong><?php echo formatCurrency($totalPaid); ?></strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <span>Balance:</span>
                        <strong><?php echo formatCurrency($balance); ?></strong>
                    </div>
                    
                    <?php if ($loan['due_date']): ?>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span>Due Date:</span>
                            <strong><?php echo date('M j, Y', strtotime($loan['due_date'])); ?></strong>
                        </div>
                        <?php 
                        $dueDate = new DateTime($loan['due_date']);
                        $today = new DateTime();
                        $daysLeft = $today->diff($dueDate)->format('%r%a');
                        
                        if ($daysLeft > 0): ?>
                            <div class="d-flex justify-content-between mt-2">
                                <span>Days Remaining:</span>
                                <strong><?php echo $daysLeft; ?></strong>
                            </div>
                        <?php elseif ($daysLeft < 0): ?>
                            <div class="d-flex justify-content-between mt-2">
                                <span>Days Overdue:</span>
                                <strong class="text-danger"><?php echo abs($daysLeft); ?></strong>
                            </div>
                        <?php else: ?>
                            <div class="d-flex justify-content-between mt-2">
                                <span>Status:</span>
                                <strong class="text-warning">Due Today</strong>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
} elseif (isset($_GET['action']) && $_GET['action'] == 'new') {
    // Display new loan form
    ?>
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Request a New Loan</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="loans.php">
                        <div class="mb-3">
                            <label for="amount" class="form-label">Loan Amount (<?php echo APP_CURRENCY; ?>)</label>
                            <input type="number" class="form-control" id="amount" name="amount" min="1000" step="1000" required>
                            <small class="text-muted">Minimum: 1,000 <?php echo APP_CURRENCY; ?></small>
                        </div>
                        <div class="mb-3">
                            <label for="purpose" class="form-label">Purpose</label>
                            <textarea class="form-control" id="purpose" name="purpose" rows="3" required></textarea>
                        </div>
                        <button type="submit" name="request_loan" class="btn btn-primary">Submit Request</button>
                        <a href="loans.php" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
} else {
    // Display list of all loans
    $loans = [];
    $query = isAdmin() ? "SELECT l.*, u.first_name, u.last_name FROM loans l JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC" 
                      : "SELECT * FROM loans WHERE user_id = ? ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!isAdmin()) {
        $stmt->bind_param("i", $userId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $loans[] = $row;
    }
    $stmt->close();
    ?>
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><?php echo isAdmin() ? 'All Loans' : 'My Loans'; ?></h2>
                <?php if (!isAdmin()): ?>
                    <a href="loans.php?action=new" class="btn btn-primary">Request Loan</a>
                <?php endif; ?>
            </div>
            
            <?php if (empty($loans)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <h4 class="text-muted">No loans found</h4>
                        <?php if (!isAdmin()): ?>
                            <p>You can request a new loan to get started</p>
                            <a href="loans.php?action=new" class="btn btn-primary">Request Loan</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <?php if (isAdmin()): ?>
                                            <th>User</th>
                                        <?php endif; ?>
                                        <th>Amount</th>
                                        <th>Purpose</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loans as $loan): ?>
                                        <tr>
                                            <?php if (isAdmin()): ?>
                                                <td><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></td>
                                            <?php endif; ?>
                                            <td><?php echo formatCurrency($loan['amount']); ?></td>
                                            <td><?php echo htmlspecialchars(substr($loan['purpose'], 0, 50) . (strlen($loan['purpose']) > 50 ? '...' : '')); ?></td>
                                            <td>
                                                <?php 
                                                $statusClass = [
                                                    'pending' => 'warning',
                                                    'approved' => 'info',
                                                    'rejected' => 'danger',
                                                    'disbursed' => 'primary',
                                                    'paid' => 'success'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $statusClass[strtolower($loan['status'])]; ?>">
                                                    <?php echo ucfirst($loan['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($loan['created_at'])); ?></td>
                                            <td>
                                                <a href="loans.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

$conn->close();
require_once __DIR__ . '/includes/footer.php';
?>