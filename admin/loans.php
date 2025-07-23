<?php
// File: /admin/loans.php

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /login.php');
    exit;
}

$conn = getDBConnection();

// Handle loan actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $loanId = intval($_POST['loan_id']);
    $action = $_POST['action'];
    
    // Get loan details
    $stmt = $conn->prepare("SELECT * FROM loans WHERE id = ?");
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['error'] = 'Loan not found';
        header("Location: loans.php");
        exit;
    }
    
    $loan = $result->fetch_assoc();
    $stmt->close();
    
    $userId = $loan['user_id'];
    $user = getUserById($userId);
    
    switch ($action) {
        case 'approve':
            // Approve loan
            $dueDate = date('Y-m-d', strtotime('+30 days'));
            
            $stmt = $conn->prepare("UPDATE loans SET status = 'approved', approved_by = ?, approved_at = NOW(), due_date = ? WHERE id = ?");
            $stmt->bind_param("isi", $_SESSION['user_id'], $dueDate, $loanId);
            
            if ($stmt->execute()) {
                createNotification($userId, 'Loan Approved', "Your loan request for " . formatCurrency($loan['amount']) . " has been approved.");
                $_SESSION['success'] = 'Loan approved successfully';
            } else {
                $_SESSION['error'] = 'Failed to approve loan';
            }
            $stmt->close();
            break;
            
        case 'reject':
            // Reject loan
            $stmt = $conn->prepare("UPDATE loans SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->bind_param("ii", $_SESSION['user_id'], $loanId);
            
            if ($stmt->execute()) {
                createNotification($userId, 'Loan Rejected', "Your loan request for " . formatCurrency($loan['amount']) . " has been rejected.");
                $_SESSION['success'] = 'Loan rejected successfully';
            } else {
                $_SESSION['error'] = 'Failed to reject loan';
            }
            $stmt->close();
            break;
            
        case 'disburse':
            // Disburse loan funds
            $reference = 'LOAN-' . uniqid();
            $narration = "Loan disbursement for loan ID: $loanId";
            
            $paymentResult = processBitnobPayment($user['phone'], $loan['amount'], $reference, $narration);
            
            if ($paymentResult['success']) {
                $stmt = $conn->prepare("UPDATE loans SET status = 'disbursed', disbursed_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $loanId);
                
                if ($stmt->execute()) {
                    createNotification($userId, 'Loan Disbursed', "Your loan of " . formatCurrency($loan['amount']) . " has been disbursed to your mobile money account.");
                    $_SESSION['success'] = 'Loan disbursed successfully';
                } else {
                    $_SESSION['error'] = 'Failed to update loan status';
                }
                $stmt->close();
            } else {
                $_SESSION['error'] = 'Failed to disburse loan: ' . $paymentResult['error'];
            }
            break;
    }
    
    header("Location: loans.php");
    exit;
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

// Get filter status
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build query based on filter
if ($statusFilter == 'all') {
    $query = "SELECT l.*, u.first_name, u.last_name FROM loans l JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($query);
} else {
    $query = "SELECT l.*, u.first_name, u.last_name FROM loans l JOIN users u ON l.user_id = u.id WHERE l.status = ? ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $statusFilter);
}

$stmt->execute();
$result = $stmt->get_result();
$loans = [];
while ($row = $result->fetch_assoc()) {
    $loans[] = $row;
}
$stmt->close();

$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Loan Management</h2>
            <div>
                <a href="/loans.php?action=new" class="btn btn-primary">New Loan</a>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Loan Applications</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <select class="form-select" name="status" onchange="this.form.submit()">
                                <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>All Loans</option>
                                <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $statusFilter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $statusFilter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="disbursed" <?php echo $statusFilter == 'disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                                <option value="paid" <?php echo $statusFilter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                            </select>
                        </div>
                    </form>
                </div>
                
                <?php if (empty($loans)): ?>
                    <div class="alert alert-info">No loans found with the selected filter.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
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
                                        <td><?php echo $loan['id']; ?></td>
                                        <td><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></td>
                                        <td><?php echo formatCurrency($loan['amount']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($loan['purpose'], 0, 30) . (strlen($loan['purpose']) > 30 ? '...' : '')); ?></td>
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
                                            <a href="/loans.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                            
                                            <?php if ($loan['status'] == 'pending'): ?>
                                                <form method="POST" action="loans.php" class="d-inline">
                                                    <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                                </form>
                                                <form method="POST" action="loans.php" class="d-inline">
                                                    <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                                                </form>
                                            <?php elseif ($loan['status'] == 'approved'): ?>
                                                <form method="POST" action="loans.php" class="d-inline">
                                                    <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                                    <input type="hidden" name="action" value="disburse">
                                                    <button type="submit" class="btn btn-sm btn-primary">Disburse</button>
                                                </form>
                                            <?php endif; ?>
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
