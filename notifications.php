<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

$db = Database::getInstance();

// Ensure notifications table exists
$createSql = "CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    sender_id INT,
    message TEXT,
    link VARCHAR(255),
    unread BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$db->prepare($createSql)->execute();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        // List notifications for current user
        $user_id = $_SESSION['user_id'] ?? 0;
        if (!$user_id) { echo json_encode([]); exit; }
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        break;

    case 'add':
        // Add notification (admin or system)
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $user_id = intval($input['user_id'] ?? 0);
        $message = $input['message'] ?? '';
        $link = $input['link'] ?? null;
        $sender_id = $_SESSION['user_id'] ?? null;

        if ($user_id && $message) {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, sender_id, message, link) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iiss', $user_id, $sender_id, $message, $link);
            $stmt->execute();
            echo json_encode(['success' => true, 'id' => $db->getLastInsertId()]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid payload']);
        }
        break;

    case 'mark_read':
        $id = intval($_POST['id'] ?? 0);
        $user_id = $_SESSION['user_id'] ?? 0;
        if ($id && $user_id) {
            $stmt = $db->prepare("UPDATE notifications SET unread = 0 WHERE id = ? AND user_id = ?");
            $stmt->bind_param('ii', $id, $user_id);
            $stmt->execute();
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
