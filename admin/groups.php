<?php
// File: /admin/groups.php

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /login.php');
    exit;
}

$conn = getDBConnection();

// Get all saving groups with member count
$savingGroups = [];
$stmt = $conn->prepare("
    SELECT sg.*, 
           (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = sg.id AND gm.is_active = TRUE) as member_count,
           u.first_name as creator_first_name, u.last_name as creator_last_name
    FROM saving_groups sg
    JOIN users u ON sg.created_by = u.id
    ORDER BY sg.created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $savingGroups[] = $row;
}
$stmt->close();

// Get total groups
$totalGroups = count($savingGroups);

// Get total active cycles
$activeCycles = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM group_cycles WHERE is_completed = FALSE");
$stmt->execute();
$result = $stmt->get_result();
$activeCycles = $result->fetch_assoc()['count'];
$stmt->close();

$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Savings Circles Management</h2>
            <div>
                <span class="badge bg-primary me-2">Total Groups: <?php echo $totalGroups; ?></span>
                <span class="badge bg-success">Active Cycles: <?php echo $activeCycles; ?></span>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">All Savings Circles</h5>
            </div>
            <div class="card-body">
                <?php if (empty($savingGroups)): ?>
                    <div class="alert alert-info">No savings circles found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Contribution</th>
                                    <th>Members</th>
                                    <th>Creator</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($savingGroups as $group): ?>
                                    <tr>
                                        <td><?php echo $group['id']; ?></td>
                                        <td><?php echo htmlspecialchars($group['name']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($group['description'], 0, 30) . (strlen($group['description']) > 30 ? '...' : '')); ?></td>
                                        <td><?php echo formatCurrency($group['amount_per_cycle']); ?> per <?php echo $group['cycle_frequency']; ?></td>
                                        <td><?php echo $group['member_count']; ?></td>
                                        <td><?php echo htmlspecialchars($group['creator_first_name'] . ' ' . $group['creator_last_name']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($group['created_at'])); ?></td>
                                        <td>
                                            <a href="/groups.php?id=<?php echo $group['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
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
