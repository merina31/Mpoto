<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

$auth->requireAdmin();
$db = Database::getInstance();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: manage_orders.php');
    exit();
}

$stmt = $db->prepare("SELECT o.*, u.username, u.full_name FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

$itemsStmt = $db->prepare("SELECT oi.*, m.name, m.image_url FROM order_items oi LEFT JOIN meals m ON oi.meal_id = m.id WHERE oi.order_id = ?");
$itemsStmt->bind_param('i', $id);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Details - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_style.css">
</head>
<body>
    <?php include 'includes/admin_navbar.php'; ?>
    <div class="admin-container">
        <main class="admin-main">
            <h1>Order #<?php echo htmlspecialchars($order['order_number']); ?></h1>
            <p><strong>User:</strong> <?php echo htmlspecialchars($order['full_name'] ?: $order['username']); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars($order['status']); ?></p>
            <h3>Items</h3>
            <table class="data-table">
                <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($it['name']); ?></td>
                        <td><?php echo (int)$it['quantity']; ?></td>
                        <td>$<?php echo number_format($it['unit_price'],2); ?></td>
                        <td>$<?php echo number_format($it['total_price'],2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><strong>Final Amount:</strong> $<?php echo number_format($order['final_amount'],2); ?></p>
            <a href="manage_orders.php" class="btn">Back</a>
        </main>
    </div>
    <?php include 'includes/admin_footer.php'; ?>
</body>
</html>
