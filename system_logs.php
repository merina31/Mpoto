<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

$auth->requireAdmin();
$db = Database::getInstance();

$stmt = $db->prepare("SELECT al.*, u.username FROM admin_logs al LEFT JOIN users u ON al.admin_id = u.id ORDER BY al.created_at DESC LIMIT 200");
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Logs - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_style.css">
</head>
<body>
    <?php include 'includes/admin_navbar.php'; ?>
    <div class="admin-container">
        <main class="admin-main">
            <h1>System Logs</h1>
            <div class="card">
                <div class="card-body">
                    <table class="data-table">
                        <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($l['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($l['username']); ?></td>
                                <td><?php echo htmlspecialchars($l['action']); ?></td>
                                <td><?php echo htmlspecialchars($l['details']); ?></td>
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
