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
    if (isset($_POST['create_group'])) {
        // Create new savings group
        $groupName = trim($_POST['name']);
        $description = trim($_POST['description']);
        $amountPerCycle = floatval($_POST['amount_per_cycle']);
        $cycleFrequency = $_POST['cycle_frequency'];
        
        if (empty($groupName) || $amountPerCycle <= 0) {
            $_SESSION['error'] = 'Please provide valid group details';
        } else {
            $stmt = $conn->prepare("INSERT INTO saving_groups (name, description, amount_per_cycle, cycle_frequency, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdsi", $groupName, $description, $amountPerCycle, $cycleFrequency, $userId);
            
            if ($stmt->execute()) {
                $groupId = $stmt->insert_id;
                $stmt->close();
                
                // Add creator as member
                $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $groupId, $userId);
                $stmt->execute();
                $stmt->close();
                
                // Create first cycle
                $startDate = date('Y-m-d');
                $endDate = getNextPayoutDate($cycleFrequency, $startDate);
                
                $stmt = $conn->prepare("INSERT INTO group_cycles (group_id, cycle_number, start_date, end_date) VALUES (?, 1, ?, ?)");
                $stmt->bind_param("iss", $groupId, $startDate, $endDate);
                $stmt->execute();
                $stmt->close();
                
                createNotification($userId, 'New Savings Circle', "You created a new savings circle: $groupName");
                $_SESSION['success'] = 'Savings circle created successfully!';
                header("Location: groups.php?id=$groupId");
                exit;
            } else {
                $_SESSION['error'] = 'Failed to create savings circle: ' . $stmt->error;
            }
        }
    } elseif (isset($_POST['join_group'])) {
        // Join an existing group
        $groupId = intval($_POST['group_id']);
        
        // Check if group exists and is open
        $stmt = $conn->prepare("SELECT id FROM saving_groups WHERE id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $_SESSION['error'] = 'Invalid savings circle';
            header("Location: groups.php");
            exit;
        }
        
        $stmt->close();
        
        // Check if user is already a member
        $stmt = $conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $groupId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $_SESSION['error'] = 'You are already a member of this savings circle';
            header("Location: groups.php?id=$groupId");
            exit;
        }
        
        $stmt->close();
        
        // Add user to group
        $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $groupId, $userId);
        
        if ($stmt->execute()) {
            $stmt->close();
            
            // Get group name for notification
            $stmt = $conn->prepare("SELECT name FROM saving_groups WHERE id = ?");
            $stmt->bind_param("i", $groupId);
            $stmt->execute();
            $result = $stmt->get_result();
            $group = $result->fetch_assoc();
            $stmt->close();
            
            createNotification($userId, 'Joined Savings Circle', "You joined the savings circle: " . $group['name']);
            $_SESSION['success'] = 'Successfully joined the savings circle!';
        } else {
            $_SESSION['error'] = 'Failed to join savings circle: ' . $stmt->error;
        }
        
        header("Location: groups.php?id=$groupId");
        exit;
    } elseif (isset($_POST['make_contribution'])) {
        // Make a contribution to a group
        $cycleId = intval($_POST['cycle_id']);
        $amount = floatval($_POST['amount']);
        
        // Get cycle and group details
        $stmt = $conn->prepare("
            SELECT gc.*, sg.amount_per_cycle, sg.cycle_frequency 
            FROM group_cycles gc
            JOIN saving_groups sg ON gc.group_id = sg.id
            WHERE gc.id = ? AND gc.is_completed = FALSE
        ");
        $stmt->bind_param("i", $cycleId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $_SESSION['error'] = 'Invalid or completed cycle';
            header("Location: groups.php");
            exit;
        }
        
        $cycle = $result->fetch_assoc();
        $stmt->close();
        
        // Verify user is a member of the group
        $stmt = $conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ? AND is_active = TRUE");
        $stmt->bind_param("ii", $cycle['group_id'], $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $_SESSION['error'] = 'You are not an active member of this group';
            header("Location: groups.php");
            exit;
        }
        
        $stmt->close();
        
        // Verify amount matches group requirement
        if (abs($amount - $cycle['amount_per_cycle']) > 0.01) {
            $_SESSION['error'] = 'Contribution amount must be exactly ' . formatCurrency($cycle['amount_per_cycle']);
            header("Location: groups.php?id=" . $cycle['group_id']);
            exit;
        }
        
        // Check if user has already contributed to this cycle
        $stmt = $conn->prepare("
            SELECT id FROM group_contributions 
            WHERE cycle_id = ? AND user_id = ? AND status = 'completed'
        ");
        $stmt->bind_param("ii", $cycleId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $_SESSION['error'] = 'You have already contributed to this cycle';
            header("Location: groups.php?id=" . $cycle['group_id']);
            exit;
        }
        
        $stmt->close();
        
        // Process payment via Bitnob
        $user = getUserById($userId);
        $reference = 'CONTRIB-' . uniqid();
        $narration = "Contribution to group cycle ID: $cycleId";
        
        $paymentResult = processBitnobPayment($user['phone'], $amount, $reference, $narration);
        
        if ($paymentResult['success']) {
            // Record contribution
            $stmt = $conn->prepare("
                INSERT INTO group_contributions (cycle_id, user_id, amount, transaction_reference, status)
                VALUES (?, ?, ?, ?, 'completed')
            ");
            $stmt->bind_param("iids", $cycleId, $userId, $amount, $reference);
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['success'] = 'Contribution successful!';
            
            // Check if all members have contributed
            $stmt = $conn->prepare("
                SELECT COUNT(gm.id) as total_members, 
                       COUNT(gc.id) as completed_contributions
                FROM group_members gm
                LEFT JOIN group_contributions gc ON gm.user_id = gc.user_id AND gc.cycle_id = ? AND gc.status = 'completed'
                WHERE gm.group_id = ? AND gm.is_active = TRUE
            ");
            $stmt->bind_param("ii", $cycleId, $cycle['group_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            $stmt->close();
            
            if ($stats['total_members'] == $stats['completed_contributions']) {
                // All contributions received - select recipient and complete cycle
                $members = [];
                $stmt = $conn->prepare("
                    SELECT user_id FROM group_members 
                    WHERE group_id = ? AND is_active = TRUE
                    ORDER BY RAND()
                    LIMIT 1
                ");
                $stmt->bind_param("i", $cycle['group_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $recipient = $result->fetch_assoc();
                $stmt->close();
                
                // Calculate total amount (number of members × contribution amount)
                $totalAmount = $stats['total_members'] * $cycle['amount_per_cycle'];
                
                // Record payout
                $stmt = $conn->prepare("
                    INSERT INTO group_payouts (cycle_id, user_id, amount, payout_date, status)
                    VALUES (?, ?, ?, CURDATE(), 'pending')
                ");
                $stmt->bind_param("iid", $cycleId, $recipient['user_id'], $totalAmount);
                $stmt->execute();
                $payoutId = $stmt->insert_id;
                $stmt->close();
                
                // Mark cycle as completed
                $stmt = $conn->prepare("
                    UPDATE group_cycles SET is_completed = TRUE WHERE id = ?
                ");
                $stmt->bind_param("i", $cycleId);
                $stmt->execute();
                $stmt->close();
                
                // Create new cycle
                $newCycleNumber = $cycle['cycle_number'] + 1;
                $newStartDate = date('Y-m-d');
                $newEndDate = getNextPayoutDate($cycle['cycle_frequency'], $newStartDate);
                
                $stmt = $conn->prepare("
                    INSERT INTO group_cycles (group_id, cycle_number, start_date, end_date)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param("iiss", $cycle['group_id'], $newCycleNumber, $newStartDate, $newEndDate);
                $stmt->execute();
                $stmt->close();
                
                // Notify recipient
                createNotification(
                    $recipient['user_id'], 
                    'Savings Circle Payout', 
                    "You've been selected to receive the payout of " . formatCurrency($totalAmount) . 
                    " for cycle #" . $cycle['cycle_number'] . " of your savings circle."
                );
                
                // Notify all members
                $stmt = $conn->prepare("
                    SELECT user_id FROM group_members WHERE group_id = ? AND is_active = TRUE
                ");
                $stmt->bind_param("i", $cycle['group_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($member = $result->fetch_assoc()) {
                    if ($member['user_id'] != $recipient['user_id']) {
                        createNotification(
                            $member['user_id'], 
                            'Savings Circle Update', 
                            "Cycle #" . $cycle['cycle_number'] . " has been completed. " . 
                            getUserById($recipient['user_id'])['first_name'] . " was selected to receive the payout."
                        );
                    }
                }
                $stmt->close();
                
                // Process payout to recipient
                $recipientUser = getUserById($recipient['user_id']);
                $payoutReference = 'PAYOUT-' . uniqid();
                $payoutNarration = "Savings circle payout for cycle #" . $cycle['cycle_number'];
                
                $payoutResult = processBitnobPayment($recipientUser['phone'], $totalAmount, $payoutReference, $payoutNarration);
                
                if ($payoutResult['success']) {
                    // Update payout status
                    $stmt = $conn->prepare("
                        UPDATE group_payouts 
                        SET transaction_reference = ?, status = 'completed' 
                        WHERE id = ?
                    ");
                    $stmt->bind_param("si", $payoutReference, $payoutId);
                    $stmt->execute();
                    $stmt->close();
                    
                    createNotification(
                        $recipient['user_id'], 
                        'Payout Received', 
                        "Your savings circle payout of " . formatCurrency($totalAmount) . 
                        " has been sent to your mobile money account."
                    );
                } else {
                    createNotification(
                        $recipient['user_id'], 
                        'Payout Failed', 
                        "We couldn't process your savings circle payout. Please contact support."
                    );
                }
            }
        } else {
            // Record failed contribution
            $stmt = $conn->prepare("
                INSERT INTO group_contributions (cycle_id, user_id, amount, transaction_reference, status)
                VALUES (?, ?, ?, ?, 'failed')
            ");
            $stmt->bind_param("iids", $cycleId, $userId, $amount, $reference);
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['error'] = 'Contribution failed: ' . $paymentResult['error'];
        }
        
        header("Location: groups.php?id=" . $cycle['group_id']);
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

// Check if viewing a single group
if (isset($_GET['id'])) {
    $groupId = intval($_GET['id']);
    
    // Get group details
    $stmt = $conn->prepare("
        SELECT sg.*, u.first_name, u.last_name 
        FROM saving_groups sg
        JOIN users u ON sg.created_by = u.id
        WHERE sg.id = ?
    ");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        header("Location: groups.php");
        exit;
    }
    
    $group = $result->fetch_assoc();
    $stmt->close();
    
    // Check if user is a member
    $isMember = false;
    $stmt = $conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $groupId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $isMember = $result->num_rows > 0;
    $stmt->close();
    
    // Get current cycle
    $currentCycle = null;
    $stmt = $conn->prepare("
        SELECT * FROM group_cycles 
        WHERE group_id = ? AND is_completed = FALSE
        ORDER BY cycle_number DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $currentCycle = $result->fetch_assoc();
    }
    $stmt->close();
    
    // Get completed cycles
    $completedCycles = [];
    $stmt = $conn->prepare("
        SELECT gc.*, gp.user_id as recipient_id, u.first_name, u.last_name, gp.amount as payout_amount
        FROM group_cycles gc
        LEFT JOIN group_payouts gp ON gc.id = gp.cycle_id
        LEFT JOIN users u ON gp.user_id = u.id
        WHERE gc.group_id = ? AND gc.is_completed = TRUE
        ORDER BY gc.cycle_number DESC
    ");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $completedCycles[] = $row;
    }
    $stmt->close();
    
    // Get group members
    $members = [];
    $stmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.phone, gm.joined_at
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ? AND gm.is_active = TRUE
        ORDER BY gm.joined_at
    ");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
    
    // Get my contributions for current cycle if exists
    $myContribution = null;
    if ($currentCycle && $isMember) {
        $stmt = $conn->prepare("
            SELECT * FROM group_contributions
            WHERE cycle_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $currentCycle['id'], $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $myContribution = $result->fetch_assoc();
        }
        $stmt->close();
    }
    
    // Display single group view
    ?>
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="groups.php">Savings Circles</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($group['name']); ?></li>
                </ol>
            </nav>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Savings Circle Details</h5>
                </div>
                <div class="card-body">
                    <h4><?php echo htmlspecialchars($group['name']); ?></h4>
                    <p><?php echo htmlspecialchars($group['description']); ?></p>
                    
                    <dl class="row">
                        <dt class="col-sm-3">Created By</dt>
                        <dd class="col-sm-9"><?php echo htmlspecialchars($group['first_name'] . ' ' . $group['last_name']); ?></dd>
                        
                        <dt class="col-sm-3">Contribution</dt>
                        <dd class="col-sm-9"><?php echo formatCurrency($group['amount_per_cycle']); ?> per <?php echo $group['cycle_frequency']; ?></dd>
                        
                        <dt class="col-sm-3">Members</dt>
                        <dd class="col-sm-9"><?php echo count($members); ?></dd>
                        
                        <dt class="col-sm-3">Created</dt>
                        <dd class="col-sm-9"><?php echo date('M j, Y', strtotime($group['created_at'])); ?></dd>
                    </dl>
                    
                    <?php if (!$isMember): ?>
                        <form method="POST" action="groups.php">
                            <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                            <button type="submit" name="join_group" class="btn btn-primary">Join This Circle</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($isMember && $currentCycle): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Current Cycle (#<?php echo $currentCycle['cycle_number']; ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <div>
                                <strong>Start Date:</strong> <?php echo date('M j, Y', strtotime($currentCycle['start_date'])); ?>
                            </div>
                            <div>
                                <strong>End Date:</strong> <?php echo date('M j, Y', strtotime($currentCycle['end_date'])); ?>
                            </div>
                        </div>
                        
                        <?php if ($myContribution): ?>
                            <?php if ($myContribution['status'] == 'completed'): ?>
                                <div class="alert alert-success">
                                    You've contributed <?php echo formatCurrency($myContribution['amount']); ?> to this cycle.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    Your contribution of <?php echo formatCurrency($myContribution['amount']); ?> is pending.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST" action="groups.php">
                                <input type="hidden" name="cycle_id" value="<?php echo $currentCycle['id']; ?>">
                                <input type="hidden" name="amount" value="<?php echo $group['amount_per_cycle']; ?>">
                                <button type="submit" name="make_contribution" class="btn btn-primary">
                                    Contribute <?php echo formatCurrency($group['amount_per_cycle']); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <h6 class="mt-4">Members' Contributions</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $member): ?>
                                        <?php
                                        $stmt = $conn->prepare("
                                            SELECT status, contribution_date 
                                            FROM group_contributions 
                                            WHERE cycle_id = ? AND user_id = ?
                                            LIMIT 1
                                        ");
                                        $stmt->bind_param("ii", $currentCycle['id'], $member['id']);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        $contribution = $result->num_rows > 0 ? $result->fetch_assoc() : null;
                                        $stmt->close();
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                            <td>
                                                <?php if ($contribution): ?>
                                                    <span class="badge bg-<?php echo $contribution['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                        <?php echo ucfirst($contribution['status']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($contribution): ?>
                                                    <?php echo date('M j', strtotime($contribution['contribution_date'])); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($completedCycles)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Previous Cycles</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Cycle</th>
                                        <th>Date</th>
                                        <th>Recipient</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completedCycles as $cycle): ?>
                                        <tr>
                                            <td>#<?php echo $cycle['cycle_number']; ?></td>
                                            <td><?php echo date('M j, Y', strtotime($cycle['end_date'])); ?></td>
                                            <td>
                                                <?php if ($cycle['recipient_id']): ?>
                                                    <?php echo htmlspecialchars($cycle['first_name'] . ' ' . $cycle['last_name']); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($cycle['payout_amount']): ?>
                                                    <?php echo formatCurrency($cycle['payout_amount']); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
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
        
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Members (<?php echo count($members); ?>)</h5>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <?php foreach ($members as $member): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h6>
                                    <?php if ($member['id'] == $group['created_by']): ?>
                                        <small class="text-muted">Creator</small>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">Joined: <?php echo date('M j, Y', strtotime($member['joined_at'])); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($isMember && $currentCycle): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Cycle Progress</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Get contribution stats
                        $stmt = $conn->prepare("
                            SELECT COUNT(gm.id) as total_members, 
                                   COUNT(gc.id) as completed_contributions
                            FROM group_members gm
                            LEFT JOIN group_contributions gc ON gm.user_id = gc.user_id AND gc.cycle_id = ? AND gc.status = 'completed'
                            WHERE gm.group_id = ? AND gm.is_active = TRUE
                        ");
                        $stmt->bind_param("ii", $currentCycle['id'], $group['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $stats = $result->fetch_assoc();
                        $stmt->close();
                        
                        $percentage = ($stats['completed_contributions'] / $stats['total_members']) * 100;
                        ?>
                        <div class="progress mb-3" style="height: 20px;">
                            <div class="progress-bar" style="width: <?php echo $percentage; ?>%">
                                <?php echo round($percentage); ?>%
                            </div>
                        </div>
                        <p>
                            <?php echo $stats['completed_contributions']; ?> of <?php echo $stats['total_members']; ?> members 
                            have contributed (<?php echo formatCurrency($group['amount_per_cycle']); ?> each)
                        </p>
                        
                        <?php 
                        $today = new DateTime();
                        $endDate = new DateTime($currentCycle['end_date']);
                        $daysLeft = $today->diff($endDate)->format('%r%a');
                        
                        if ($daysLeft > 0): ?>
                            <p class="mb-0">
                                <i class="bi bi-calendar-check"></i> 
                                <?php echo $daysLeft; ?> day<?php echo $daysLeft != 1 ? 's' : ''; ?> remaining
                            </p>
                        <?php elseif ($daysLeft < 0): ?>
                            <p class="mb-0 text-danger">
                                <i class="bi bi-exclamation-triangle"></i> 
                                <?php echo abs($daysLeft); ?> day<?php echo abs($daysLeft) != 1 ? 's' : ''; ?> overdue
                            </p>
                        <?php else: ?>
                            <p class="mb-0 text-warning">
                                <i class="bi bi-exclamation-circle"></i> 
                                Cycle ends today
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
} elseif (isset($_GET['action']) && $_GET['action'] == 'new') {
    // Display new group form
    ?>
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Create New Savings Circle</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="groups.php">
                        <div class="mb-3">
                            <label for="name" class="form-label">Circle Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="amount_per_cycle" class="form-label">Contribution Amount (<?php echo APP_CURRENCY; ?>)</label>
                            <input type="number" class="form-control" id="amount_per_cycle" name="amount_per_cycle" min="1000" step="1000" required>
                            <small class="text-muted">Amount each member will contribute per cycle</small>
                        </div>
                        <div class="mb-3">
                            <label for="cycle_frequency" class="form-label">Cycle Frequency</label>
                            <select class="form-select" id="cycle_frequency" name="cycle_frequency" required>
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                            </select>
                        </div>
                        <button type="submit" name="create_group" class="btn btn-primary">Create Circle</button>
                        <a href="groups.php" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
} else {
    // Display list of all groups
    $groups = [];
    $query = "
        SELECT sg.*, 
               (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = sg.id AND gm.is_active = TRUE) as member_count
        FROM saving_groups sg
        ORDER BY sg.created_at DESC
    ";
    
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
    
    // Get groups I'm a member of
    $myGroups = [];
    $stmt = $conn->prepare("
        SELECT sg.* 
        FROM saving_groups sg
        JOIN group_members gm ON sg.id = gm.group_id
        WHERE gm.user_id = ? AND gm.is_active = TRUE
        ORDER BY sg.created_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $myGroups[] = $row;
    }
    $stmt->close();
    ?>
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Savings Circles</h2>
                <a href="groups.php?action=new" class="btn btn-primary">New Savings Circle</a>
            </div>
            
            <ul class="nav nav-tabs" id="groupsTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="my-groups-tab" data-bs-toggle="tab" data-bs-target="#my-groups" type="button" role="tab">
                        My Circles
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="all-groups-tab" data-bs-toggle="tab" data-bs-target="#all-groups" type="button" role="tab">
                        All Circles
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="groupsTabContent">
                <div class="tab-pane fade show active" id="my-groups" role="tabpanel">
                    <?php if (empty($myGroups)): ?>
                        <div class="card mt-4">
                            <div class="card-body text-center py-5">
                                <h4 class="text-muted">You're not part of any savings circles yet</h4>
                                <p>Join an existing circle or create your own to get started</p>
                                <a href="groups.php?action=new" class="btn btn-primary">Create Savings Circle</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row mt-4">
                            <?php foreach ($myGroups as $group): ?>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <h5><?php echo htmlspecialchars($group['name']); ?></h5>
                                                <span class="badge bg-primary">Member</span>
                                            </div>
                                            <p class="text-muted"><?php echo htmlspecialchars(substr($group['description'], 0, 100) . (strlen($group['description']) > 100 ? '...' : '')); ?></p>
                                            
                                            <div class="d-flex justify-content-between mb-2">
                                                <small>Contribution:</small>
                                                <small><?php echo formatCurrency($group['amount_per_cycle']); ?> per <?php echo $group['cycle_frequency']; ?></small>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <?php 
                                                    $stmt = $conn->prepare("
                                                        SELECT COUNT(*) as count FROM group_members 
                                                        WHERE group_id = ? AND is_active = TRUE
                                                    ");
                                                    $stmt->bind_param("i", $group['id']);
                                                    $stmt->execute();
                                                    $result = $stmt->get_result();
                                                    $memberCount = $result->fetch_assoc()['count'];
                                                    $stmt->close();
                                                    ?>
                                                    <?php echo $memberCount; ?> member<?php echo $memberCount != 1 ? 's' : ''; ?>
                                                </small>
                                                <a href="groups.php?id=<?php echo $group['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="tab-pane fade" id="all-groups" role="tabpanel">
                    <?php if (empty($groups)): ?>
                        <div class="card mt-4">
                            <div class="card-body text-center py-5">
                                <h4 class="text-muted">No savings circles found</h4>
                                <p>Be the first to create a savings circle</p>
                                <a href="groups.php?action=new" class="btn btn-primary">Create Savings Circle</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row mt-4">
                            <?php foreach ($groups as $group): ?>
                                <?php
                                $isMember = false;
                                foreach ($myGroups as $myGroup) {
                                    if ($myGroup['id'] == $group['id']) {
                                        $isMember = true;
                                        break;
                                    }
                                }
                                ?>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <h5><?php echo htmlspecialchars($group['name']); ?></h5>
                                                <?php if ($isMember): ?>
                                                    <span class="badge bg-primary">Member</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-muted"><?php echo htmlspecialchars(substr($group['description'], 0, 100) . (strlen($group['description']) > 100 ? '...' : '')); ?></p>
                                            
                                            <div class="d-flex justify-content-between mb-2">
                                                <small>Contribution:</small>
                                                <small><?php echo formatCurrency($group['amount_per_cycle']); ?> per <?php echo $group['cycle_frequency']; ?></small>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <?php echo $group['member_count']; ?> member<?php echo $group['member_count'] != 1 ? 's' : ''; ?>
                                                </small>
                                                <a href="groups.php?id=<?php echo $group['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

$conn->close();
require_once __DIR__ . '/includes/footer.php';
?>