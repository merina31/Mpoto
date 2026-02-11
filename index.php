<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

// Require admin authentication
$auth->requireAdmin();

// Get statistics
$db = Database::getInstance();

// User count
$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$stmt->execute();
$user_count = $stmt->get_result()->fetch_assoc()['count'];

// Order count
$stmt = $db->prepare("SELECT COUNT(*) as count FROM orders");
$stmt->execute();
$order_count = $stmt->get_result()->fetch_assoc()['count'];

// Today's orders
$stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE()");
$stmt->execute();
$today_orders = $stmt->get_result()->fetch_assoc()['count'];

// Revenue
$stmt = $db->prepare("SELECT SUM(final_amount) as revenue FROM orders WHERE status = 'delivered'");
$stmt->execute();
$revenue = $stmt->get_result()->fetch_assoc()['revenue'] ?? 0;

// Recent orders
$stmt = $db->prepare("SELECT o.*, u.username, u.full_name 
                     FROM orders o 
                     LEFT JOIN users u ON o.user_id = u.id 
                     ORDER BY o.created_at DESC 
                     LIMIT 10");
$stmt->execute();
$recent_orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include '../admin/includes/admin_navbar.php'; ?>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-cog"></i> Admin Panel</h2>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li class="active"><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="manage_users.php"><i class="fas fa-users"></i> Users</a></li>
                    <li><a href="manage_meals.php"><i class="fas fa-utensils"></i> Meals</a></li>
                    <li><a href="manage_categories.php"><i class="fas fa-list"></i> Categories</a></li>
                    <li><a href="manage_orders.php"><i class="fas fa-shopping-cart"></i> Orders</a></li>
                    <li><a href="manage_reviews.php"><i class="fas fa-star"></i> Reviews</a></li>
                    <li><a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a></li>
                    <li><a href="upload_meal.php"><i class="fas fa-image"></i> Upload Meal Image</a></li>
                    <li><a href="system_logs.php"><i class="fas fa-history"></i> System Logs</a></li>
                    <li><a href="settings.php"><i class="fas fa-cogs"></i> Settings</a></li>
                    <li><a href="../index.php"><i class="fas fa-home"></i> Back to Site</a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <header class="admin-header">
                <h1>Dashboard Overview</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo $_SESSION['full_name']; ?> (Admin)</span>
                </div>
            </header>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #4CAF50;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $user_count; ?></h3>
                        <p>Total Users</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2196F3;">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $order_count; ?></h3>
                        <p>Total Orders</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #FF9800;">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $today_orders; ?></h3>
                        <p>Today's Orders</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9C27B0;">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="stat-info">
                        <h3>$<?php echo number_format($revenue, 2); ?></h3>
                        <p>Total Revenue</p>
                    </div>
                </div>
            </div>

            <!-- Recent Orders Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Recent Orders</h3>
                    <a href="manage_orders.php" class="btn btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td><?php echo $order['order_number']; ?></td>
                                <td><?php echo $order['full_name'] ?: $order['username']; ?></td>
                                <td>$<?php echo number_format($order['final_amount'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $order['status']; ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                <td>
                                    <a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn-action">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h3>Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <a href="manage_meals.php?action=add" class="quick-action">
                            <i class="fas fa-plus-circle"></i>
                            <span>Add New Meal</span>
                        </a>
                        <a href="manage_categories.php?action=add" class="quick-action">
                            <i class="fas fa-tags"></i>
                            <span>Add Category</span>
                        </a>
                        <a href="manage_users.php?action=add" class="quick-action">
                            <i class="fas fa-user-plus"></i>
                            <span>Add User</span>
                        </a>
                        <a href="system_settings.php" class="quick-action">
                            <i class="fas fa-cogs"></i>
                            <span>System Settings</span>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php include '../admin/includes/admin_footer.php'; ?>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>