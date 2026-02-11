<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

$auth->requireAdmin();
$db = Database::getInstance();

// Fetch reviews
$stmt = $db->prepare("SELECT r.*, u.username, m.name as meal_name FROM reviews r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN meals m ON r.meal_id = m.id ORDER BY r.created_at DESC");
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Reviews - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/admin_navbar.php'; ?>
    <div class="admin-container">
        <main class="admin-main">
            <h1>Manage Reviews</h1>
            <div class="card">
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr><th>User</th><th>Meal</th><th>Rating</th><th>Comment</th><th>Approved</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reviews as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['username']); ?></td>
                                <td><?php echo htmlspecialchars($r['meal_name']); ?></td>
                                <td><?php echo (int)$r['rating']; ?></td>
                                <td><?php echo htmlspecialchars($r['comment']); ?></td>
                                <td><?php echo $r['is_approved'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
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
