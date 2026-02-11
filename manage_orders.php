<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

 $auth->requireAdmin();
 $db = Database::getInstance();

// Ensure notifications table exists (used below)
$createNot = "CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    sender_id INT,
    message TEXT,
    link VARCHAR(255),
    unread BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$db->prepare($createNot)->execute();

// Handle admin actions (confirm payment)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $order_id = intval($_POST['order_id'] ?? 0);

    if ($action === 'confirm_payment' && $order_id > 0) {
        // Update order status and payment
        $uStmt = $db->prepare("UPDATE orders SET status = 'confirmed', payment_status = 'completed' WHERE id = ?");
        $uStmt->bind_param('i', $order_id);
        $uStmt->execute();

        // Log admin action
        $logStmt = $db->prepare("INSERT INTO admin_logs (admin_id, action, details, ip_address, user_agent) VALUES (?, 'confirm_payment', ?, ?, ?)");
        $details = "Confirmed payment for order ID: {$order_id}";
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $admin_id = $_SESSION['user_id'] ?? 0;
        $logStmt->bind_param('isss', $admin_id, $details, $ip, $ua);
        $logStmt->execute();

        // Notify the user
        // Get user id and order number
        $s = $db->prepare("SELECT user_id, order_number FROM orders WHERE id = ?");
        $s->bind_param('i', $order_id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        if ($row && $row['user_id']) {
            $notify = $db->prepare("INSERT INTO notifications (user_id, sender_id, message, link) VALUES (?, ?, ?, ?)");
            $msg = "Your order #{$row['order_number']} has been confirmed by admin.";
            $link = 'orders.php';
            $notify->bind_param('iiss', $row['user_id'], $admin_id, $msg, $link);
            $notify->execute();
        }
    }

    // After handling, refresh
    header('Location: manage_orders.php');
    exit();
}

// Fetch orders (optionally filter by user)
$userFilter = intval($_GET['user_id'] ?? 0);
$sql = "SELECT o.*, u.username, u.full_name FROM orders o LEFT JOIN users u ON o.user_id = u.id";
if ($userFilter > 0) {
    $sql .= " WHERE o.user_id = " . $userFilter;
}
$sql .= " ORDER BY o.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Orders - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include '../admin/includes/admin_navbar.php'; ?>
    <div class="admin-container">
        <aside class="admin-sidebar"></aside>
        <main class="admin-main">
            <h1>Manage Orders</h1>
            <div class="card">
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr><th>Order #</th><th>User</th><th>Total</th><th>Status</th><th>Date</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($o['order_number']); ?></td>
                                        <td>
                                            <?php if ($o['user_id']): ?>
                                                <a href="manage_orders.php?user_id=<?php echo $o['user_id']; ?>"><?php echo htmlspecialchars($o['full_name'] ?: $o['username']); ?></a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($o['full_name'] ?: $o['username']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>$<?php echo number_format($o['final_amount'],2); ?></td>
                                        <td><?php echo htmlspecialchars($o['status']); ?></td>
                                        <td><?php echo htmlspecialchars($o['created_at']); ?></td>
                                        <td>
                                            <a href="order_details.php?id=<?php echo $o['id']; ?>" class="btn">View</a>
                                            <?php if ($o['payment_status'] !== 'completed'): ?>
                                                <form method="POST" style="display:inline-block;margin-left:6px;">
                                                    <input type="hidden" name="action" value="confirm_payment">
                                                    <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                                    <button type="submit" class="btn btn-primary" onclick="return confirm('Confirm payment for this order?');">Confirm Payment</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    <?php include 'includes/admin_footer.php'; ?>
</body>
</html>
