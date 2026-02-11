<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

$db = Database::getInstance();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'mark_received':
        // User marks order as received; notify admin and update status/payment if needed
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        $user_id = $_SESSION['user_id'];
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $order_id = intval($input['order_id'] ?? 0);
        if ($order_id <= 0) { echo json_encode(['success'=>false]); exit; }

        // Ensure the order belongs to this user
        $check = $db->prepare("SELECT id, status, payment_method FROM orders WHERE id = ? AND user_id = ?");
        $check->bind_param('ii', $order_id, $user_id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        if (!$row) { echo json_encode(['success'=>false,'message'=>'Order not found']); exit; }

        // Update status to delivered if not already
        if ($row['status'] !== 'delivered') {
            $upd = $db->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?");
            $upd->bind_param('i', $order_id);
            $upd->execute();
        }

        // If payment was cash_on_delivery, mark payment_status=completed (user confirmed payment)
        if ($row['payment_method'] === 'cash_on_delivery') {
            $p = $db->prepare("UPDATE orders SET payment_status = 'completed' WHERE id = ?");
            $p->bind_param('i', $order_id);
            $p->execute();
        }

        // Notify admin
        $adminId = 0; // system
        $msg = "User #{$user_id} confirmed receipt for order ID {$order_id}.";
        $link = 'admin/manage_orders.php';
        $ins = $db->prepare("INSERT INTO notifications (user_id, sender_id, message, link) VALUES (?, ?, ?, ?)");
        // send to admin user(s) - simplistic: send to admin id 1 if exists
        $adminQuery = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $adminQuery->execute();
        $admin = $adminQuery->get_result()->fetch_assoc();
        if ($admin) {
            $toAdmin = $admin['id'];
            $ins->bind_param('iiss', $toAdmin, $user_id, $msg, $link);
            $ins->execute();
        }

        echo json_encode(['success' => true]);
        break;

    case 'cancel':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        $user_id = $_SESSION['user_id'];
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $order_id = intval($input['order_id'] ?? 0);
        if ($order_id <= 0) { echo json_encode(['success'=>false]); exit; }

        // Verify ownership and that order can be cancelled (pending)
        $check = $db->prepare("SELECT id, status, user_id FROM orders WHERE id = ? AND user_id = ?");
        $check->bind_param('ii', $order_id, $user_id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        if (!$row) { echo json_encode(['success'=>false,'message'=>'Order not found']); exit; }

        if ($row['status'] !== 'pending') {
            echo json_encode(['success'=>false,'message'=>'Only pending orders can be cancelled']); exit;
        }

        $upd = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
        $upd->bind_param('i', $order_id);
        $upd->execute();

        // Notify admins
        $adminQuery = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $adminQuery->execute();
        $admin = $adminQuery->get_result()->fetch_assoc();
        if ($admin) {
            $toAdmin = $admin['id'];
            $msg = "User #{$user_id} cancelled order ID {$order_id}.";
            $ins = $db->prepare("INSERT INTO notifications (user_id, sender_id, message, link) VALUES (?, ?, ?, ?)");
            $link = 'admin/manage_orders.php';
            $ins->bind_param('iiss', $toAdmin, $user_id, $msg, $link);
            $ins->execute();
        }

        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
