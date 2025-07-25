<?php
// File: /includes/header.php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/constants.php';

$conn = getDBConnection();

// Get user data if logged in
$user = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
}

// Get unread notifications count
$unread_notifications = 0;
if ($user) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $unread_notifications = $result->fetch_assoc()['count'];
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME . ' | ' . (isset($page_title) ? $page_title : ''); ?></title>
    <link href="<?php echo CSS_PATH; ?>/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo CSS_PATH; ?>/style.css" rel="stylesheet">
    <link href="<?php echo CSS_PATH; ?>/login.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">

    <style>
        html, body {
            height: 100%;
            margin: 0;
        }

        body {
            display: flex;
            flex-direction: column;
        }

        .content-wrapper {
            flex: 1;
        }
/* Reduce navbar height and spacing */
.navbar {
  padding-top: 0.3rem;
  padding-bottom: 0.3rem;
}

.navbar .container {
  padding-left: 0.5rem;
  padding-right: 0.5rem;
}

/* Optional: Adjust logo size and text alignment */
.navbar-brand img {
  width: 50x;
  height: 50px;
}

.navbar-brand span {
  font-size: 1.2rem;
  font-family: 'Hanaei Fill', sans-serif;
   
}
@font-face {
  font-family: 'Hanaei Fill';
  src: url('/assets/Hanaei-Fill.ttf') format('truetype');
}



    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <!-- ✅ Fixed App Name + Logo (no navbar break) -->
        <a class="navbar-brand d-flex align-items-center" href="<?php echo APP_URL . '/index.php'; ?>" style="gap: 0.5rem;">
            <img src="<?php echo APP_URL; ?>/assets/logo.png" alt="SmartSave Logo"  style="object-fit: contain;">
            <span class="fw-bold text-warning fs-5">SmartSave</span>
        </a>




            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if ($user): ?>
                        <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="savings.php">My Savings</a></li>
                        <li class="nav-item"><a class="nav-link" href="loans.php">Loans</a></li>
                        <li class="nav-item"><a class="nav-link" href="groups.php">Savings Circles</a></li>
                    <?php endif; ?>
                    <?php if ($user && $user['is_admin']): ?>
                        <li class="nav-item"><a class="nav-link" href="admin/dashboard.php">Admin</a></li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <?php if ($user): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="bi bi-bell-fill"></i>
                                <?php if ($unread_notifications > 0): ?>
                                    <span class="badge bg-danger"><?php echo $unread_notifications; ?></span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header">Notifications</h6></li>
                                <?php
                                $conn = getDBConnection();
                                $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                                $stmt->bind_param("i", $user['id']);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($result->num_rows > 0) {
                                    while ($notification = $result->fetch_assoc()) {
                                        echo '<li><a class="dropdown-item' . ($notification['is_read'] ? '' : ' fw-bold') . '" href="#">' . $notification['title'] . '</a></li>';
                                    }
                                } else {
                                    echo '<li><a class="dropdown-item text-muted" href="#">No notifications</a></li>';
                                }
                                $stmt->close();
                                $conn->close();
                                ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-center" href="#">View All</a></li>
                            </ul>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($user['first_name']); ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
                        <li class="nav-item"><a class="nav-link" href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Start of content wrapper -->
    <div class="content-wrapper container-fluid mt-4">
        
                             </body>
                    </html>
                    