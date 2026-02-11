<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/auth_functions.php';

// Require login
$auth->requireLogin();

$user_id = $_SESSION['user_id'];
$db = Database::getInstance();

// Load logged-in user details for payment slip
$userInfoSql = "SELECT full_name, email, phone FROM users WHERE id = ? LIMIT 1";
$userInfoStmt = $db->prepare($userInfoSql);
$userInfoStmt->bind_param("i", $user_id);
$userInfoStmt->execute();
$currentUser = $userInfoStmt->get_result()->fetch_assoc() ?: [];

// Helper function to get order count
function getOrderCount($user_id, $status) {
    $db = Database::getInstance();
    if ($status === 'all') {
        $sql = "SELECT COUNT(*) as total FROM orders WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $user_id);
    } else {
        $sql = "SELECT COUNT(*) as total FROM orders WHERE user_id = ? AND status = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("is", $user_id, $status);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['total'] ?? 0;
}

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Build where clause for status filter
$where = "WHERE user_id = ?";
$params = [$user_id];
$types = "i";

if ($status !== 'all') {
    $where .= " AND status = ?";
    $params[] = $status;
    $types .= "s";
}

// Get total count
$countSql = "SELECT COUNT(*) as total FROM orders {$where}";
$countStmt = $db->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Get orders
$sql = "SELECT * FROM orders {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Payments table may not exist on all setups
$paymentsTableExists = false;
$paymentsCheckStmt = $db->prepare("SHOW TABLES LIKE 'payments'");
$paymentsCheckStmt->execute();
$paymentsCheckResult = $paymentsCheckStmt->get_result();
$paymentsTableExists = ($paymentsCheckResult && $paymentsCheckResult->num_rows > 0);
$paymentsCheckStmt->close();

// Get order items for each order
foreach ($orders as &$order) {
    $itemsSql = "SELECT oi.*, m.name, m.image_url 
                 FROM order_items oi 
                 JOIN meals m ON oi.meal_id = m.id 
                 WHERE oi.order_id = ?";
    $itemsStmt = $db->prepare($itemsSql);
    $itemsStmt->bind_param("i", $order['id']);
    $itemsStmt->execute();
    $order['items'] = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Attach payment details for slip
    $order['payment_record'] = null;
    if ($paymentsTableExists) {
        $paymentSql = "SELECT amount, payment_method, payment_status, transaction_id, payment_details, created_at
                       FROM payments
                       WHERE order_id = ?
                       ORDER BY id DESC
                       LIMIT 1";
        $paymentStmt = $db->prepare($paymentSql);
        $paymentStmt->bind_param("i", $order['id']);
        $paymentStmt->execute();
        $paymentRow = $paymentStmt->get_result()->fetch_assoc();

        if ($paymentRow) {
            $paymentDetails = json_decode($paymentRow['payment_details'] ?? '', true);
            $paymentRow['payment_details_decoded'] = is_array($paymentDetails) ? $paymentDetails : [];
            $order['payment_record'] = $paymentRow;
        }
    }
}
unset($order);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Orders Page -->
    <div class="orders-container container">
        <h1 class="page-title">My Orders</h1>
        
        <!-- Stats Overview -->
        <div class="order-stats">
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #2196F3;">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo getOrderCount($user_id, 'pending'); ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #FF9800;">
                    <i class="fas fa-utensils"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo getOrderCount($user_id, 'preparing'); ?></h3>
                    <p>Preparing</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #4CAF50;">
                    <i class="fas fa-shipping-fast"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo getOrderCount($user_id, 'delivered'); ?></h3>
                    <p>Delivered</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #9C27B0;">
                    <i class="fas fa-history"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo getOrderCount($user_id, 'all'); ?></h3>
                    <p>Total Orders</p>
                </div>
            </div>
        </div>

        <!-- Orders Filter -->
        <div class="orders-filter">
            <div class="filter-tabs">
                <a href="?status=all" class="filter-tab <?php echo $status == 'all' ? 'active' : ''; ?>">
                    All Orders
                </a>
                <a href="?status=pending" class="filter-tab <?php echo $status == 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Pending
                </a>
                <a href="?status=confirmed" class="filter-tab <?php echo $status == 'confirmed' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle"></i> Confirmed
                </a>
                <a href="?status=preparing" class="filter-tab <?php echo $status == 'preparing' ? 'active' : ''; ?>">
                    <i class="fas fa-utensils"></i> Preparing
                </a>
                <a href="?status=out_for_delivery" class="filter-tab <?php echo $status == 'out_for_delivery' ? 'active' : ''; ?>">
                    <i class="fas fa-shipping-fast"></i> On the Way
                </a>
                <a href="?status=delivered" class="filter-tab <?php echo $status == 'delivered' ? 'active' : ''; ?>">
                    <i class="fas fa-box"></i> Delivered
                </a>
                <a href="?status=cancelled" class="filter-tab <?php echo $status == 'cancelled' ? 'active' : ''; ?>">
                    <i class="fas fa-times-circle"></i> Cancelled
                </a>
            </div>
            
            <div class="filter-search">
                <input type="text" placeholder="Search orders..." id="orderSearch">
                <button type="button">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>

        <!-- Orders List -->
        <?php if (empty($orders)): ?>
            <div class="no-orders">
                <i class="fas fa-receipt"></i>
                <h3>No orders found</h3>
                <p>You haven't placed any orders yet</p>
                <a href="menu.php" class="btn btn-primary">
                    <i class="fas fa-utensils"></i> Order Now
                </a>
            </div>
        <?php else: ?>
            <div class="orders-list">
                <?php foreach ($orders as $order): ?>
                    <?php
                        $payment = $order['payment_record'];
                        $paymentMethod = $payment['payment_method'] ?? $order['payment_method'];
                        $paymentStatus = $payment['payment_status'] ?? $order['payment_status'];
                        $paymentAmount = $payment['amount'] ?? $order['final_amount'];
                        $paymentTime = $payment['created_at'] ?? $order['created_at'];
                        $transactionId = $payment['transaction_id'] ?? null;
                        $paymentDetails = $payment['payment_details_decoded'] ?? [];
                    ?>
                    <div class="order-card" data-order-id="<?php echo $order['id']; ?>">
                        <div class="order-header">
                            <div class="order-meta">
                                <h3>Order #<?php echo $order['order_number']; ?></h3>
                                <div class="order-date">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('F d, Y - h:i A', strtotime($order['created_at'])); ?>
                                </div>
                            </div>
                            
                            <div class="order-status">
                                <span class="status-badge status-<?php echo $order['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="order-items">
                            <?php foreach ($order['items'] as $item): ?>
                                <div class="order-item">
                                    <div class="item-image">
                                        <img src="<?php echo htmlspecialchars($item['image_url'] ?: 'assets/images/default-food.jpg'); ?>" 
                                             alt="<?php echo htmlspecialchars($item['name']); ?>">
                                    </div>
                                    <div class="item-details">
                                        <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                        <div class="item-quantity">
                                            Quantity: <?php echo $item['quantity']; ?>
                                        </div>
                                    </div>
                                    <div class="item-price">
                                        <?php echo format_currency($item['total_price']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="order-footer">
                            <div class="order-totals">
                                <div class="total-row">
                                    <span>Subtotal:</span>
                                    <span><?php echo format_currency($order['total_amount']); ?></span>
                                </div>
                                <div class="total-row">
                                    <span>Delivery Fee:</span>
                                    <span><?php echo format_currency($order['delivery_fee']); ?></span>
                                </div>
                                <div class="total-row">
                                    <span>Tax:</span>
                                    <span><?php echo format_currency($order['tax_amount']); ?></span>
                                </div>
                                <div class="total-row grand-total">
                                    <span>Total:</span>
                                    <span><?php echo format_currency($order['final_amount']); ?></span>
                                </div>
                            </div>
                            
                            <div class="order-actions">
                                <button type="button" class="btn btn-secondary" onclick="showReceiptModal(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Details
                                </button>

                                <?php if ($order['status'] === 'pending'): ?>
                                    <button type="button" 
                                            class="btn btn-danger" 
                                            onclick="cancelOrder(<?php echo $order['id']; ?>)">
                                        <i class="fas fa-times"></i> Cancel Order
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['status'] === 'delivered'): ?>
                                    <button type="button" 
                                            class="btn btn-primary" 
                                            onclick="showReviewForm(<?php echo $order['id']; ?>)">
                                        <i class="fas fa-star"></i> Write Review
                                    </button>
                                    
                                    <button type="button" 
                                            class="btn btn-secondary" 
                                            onclick="reorder(<?php echo $order['id']; ?>)">
                                        <i class="fas fa-redo"></i> Reorder
                                    </button>
                                <?php endif; ?>
                                <?php if (in_array($order['status'], ['out_for_delivery','delivered'])): ?>
                                    <button type="button" class="btn btn-success" onclick="markReceived(<?php echo $order['id']; ?>)">
                                        <i class="fas fa-check"></i> Mark as Received
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div id="receiptData-<?php echo $order['id']; ?>" class="receipt-template">
                            <div class="receipt-print">
                                <h2>Payment Receipt</h2>
                                <p><strong>Order #:</strong> <?php echo htmlspecialchars($order['order_number']); ?></p>
                                <p><strong>Date:</strong> <?php echo date('F d, Y - h:i A', strtotime($paymentTime)); ?></p>
                                <p><strong>Payer:</strong> <?php echo htmlspecialchars($currentUser['full_name'] ?? ($_SESSION['username'] ?? 'Customer')); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($currentUser['email'] ?? ($_SESSION['email'] ?? 'N/A')); ?></p>
                                <p><strong>Phone:</strong> <?php echo htmlspecialchars($order['contact_phone'] ?: ($currentUser['phone'] ?? 'N/A')); ?></p>
                                <p><strong>Payment Method:</strong> <?php echo ucwords(str_replace('_', ' ', (string) $paymentMethod)); ?></p>
                                <p><strong>Payment Status:</strong> <?php echo ucfirst((string) $paymentStatus); ?></p>
                                <p><strong>Transaction ID:</strong> <?php echo htmlspecialchars($transactionId ?: 'Not available'); ?></p>
                                <p><strong>Delivery Address:</strong> <?php echo htmlspecialchars($order['delivery_address'] ?? 'N/A'); ?></p>
                                <?php if (!empty($order['special_instructions'])): ?>
                                    <p><strong>Special Instructions:</strong> <?php echo htmlspecialchars($order['special_instructions']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($paymentDetails['card_last4'])): ?>
                                    <p><strong>Card Last 4:</strong> **** <?php echo htmlspecialchars($paymentDetails['card_last4']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($paymentDetails['paypal_email'])): ?>
                                    <p><strong>PayPal Email:</strong> <?php echo htmlspecialchars($paymentDetails['paypal_email']); ?></p>
                                <?php endif; ?>
                                <hr>
                                <table class="receipt-items-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Unit Price</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($order['items'] as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                                <td><?php echo (int) $item['quantity']; ?></td>
                                                <td><?php echo format_currency($item['unit_price'] ?? 0); ?></td>
                                                <td><?php echo format_currency($item['total_price']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="receipt-totals">
                                    <div><span>Subtotal</span><strong><?php echo format_currency($order['total_amount']); ?></strong></div>
                                    <div><span>Delivery Fee</span><strong><?php echo format_currency($order['delivery_fee']); ?></strong></div>
                                    <div><span>Tax</span><strong><?php echo format_currency($order['tax_amount']); ?></strong></div>
                                    <div class="grand"><span>Total Paid</span><strong><?php echo format_currency($paymentAmount); ?></strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="payment-slip-card" id="paymentSlip-<?php echo $order['id']; ?>">
                            <div class="slip-header">
                                <div>
                                    <h4>Payment Slip</h4>
                                    <p>Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm" onclick="printPaymentSlip(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-print"></i> Print Slip
                                </button>
                            </div>

                            <div class="slip-body" id="printableSlip-<?php echo $order['id']; ?>">
                                <div class="slip-row">
                                    <span>Date</span>
                                    <strong><?php echo date('F d, Y - h:i A', strtotime($paymentTime)); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Payer</span>
                                    <strong><?php echo htmlspecialchars($currentUser['full_name'] ?? ($_SESSION['username'] ?? 'Customer')); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Email</span>
                                    <strong><?php echo htmlspecialchars($currentUser['email'] ?? ($_SESSION['email'] ?? 'N/A')); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Phone</span>
                                    <strong><?php echo htmlspecialchars($order['contact_phone'] ?: ($currentUser['phone'] ?? 'N/A')); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Payment Method</span>
                                    <strong><?php echo ucwords(str_replace('_', ' ', (string) $paymentMethod)); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Payment Status</span>
                                    <strong><?php echo ucfirst((string) $paymentStatus); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Transaction ID</span>
                                    <strong><?php echo htmlspecialchars($transactionId ?: 'Not available'); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Delivery Address</span>
                                    <strong><?php echo htmlspecialchars($order['delivery_address'] ?? 'N/A'); ?></strong>
                                </div>
                                <?php if (!empty($order['special_instructions'])): ?>
                                    <div class="slip-row">
                                        <span>Special Instructions</span>
                                        <strong><?php echo htmlspecialchars($order['special_instructions']); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($paymentDetails['card_last4'])): ?>
                                    <div class="slip-row">
                                        <span>Card Last 4</span>
                                        <strong>**** <?php echo htmlspecialchars($paymentDetails['card_last4']); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($paymentDetails['paypal_email'])): ?>
                                    <div class="slip-row">
                                        <span>PayPal Email</span>
                                        <strong><?php echo htmlspecialchars($paymentDetails['paypal_email']); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="slip-row">
                                    <span>Subtotal</span>
                                    <strong><?php echo format_currency($order['total_amount']); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Delivery Fee</span>
                                    <strong><?php echo format_currency($order['delivery_fee']); ?></strong>
                                </div>
                                <div class="slip-row">
                                    <span>Tax</span>
                                    <strong><?php echo format_currency($order['tax_amount']); ?></strong>
                                </div>
                                <div class="slip-row total-row">
                                    <span>Total Paid</span>
                                    <strong><?php echo format_currency($paymentAmount); ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($order['status'] === 'out_for_delivery'): ?>
                            <div class="order-tracking">
                                <h4><i class="fas fa-map-marker-alt"></i> Order Tracking</h4>
                                <div class="tracking-timeline">
                                    <div class="tracking-step completed">
                                        <div class="step-icon">
                                            <i class="fas fa-check"></i>
                                        </div>
                                        <div class="step-content">
                                            <strong>Order Placed</strong>
                                            <small><?php echo date('h:i A', strtotime($order['created_at'])); ?></small>
                                        </div>
                                    </div>
                                    <div class="tracking-step completed">
                                        <div class="step-icon">
                                            <i class="fas fa-check"></i>
                                        </div>
                                        <div class="step-content">
                                            <strong>Order Confirmed</strong>
                                            <small><?php echo date('h:i A', strtotime($order['created_at']) + 300); ?></small>
                                        </div>
                                    </div>
                                    <div class="tracking-step completed">
                                        <div class="step-icon">
                                            <i class="fas fa-check"></i>
                                        </div>
                                        <div class="step-content">
                                            <strong>Food Preparing</strong>
                                            <small><?php echo date('h:i A', strtotime($order['created_at']) + 900); ?></small>
                                        </div>
                                    </div>
                                    <div class="tracking-step active">
                                        <div class="step-icon">
                                            <i class="fas fa-shipping-fast"></i>
                                        </div>
                                        <div class="step-content">
                                            <strong>Out for Delivery</strong>
                                            <small>Estimated: <?php echo date('h:i A', strtotime($order['created_at']) + 1800); ?></small>
                                        </div>
                                    </div>
                                    <div class="tracking-step">
                                        <div class="step-icon">
                                            <i class="fas fa-home"></i>
                                        </div>
                                        <div class="step-content">
                                            <strong>Delivered</strong>
                                            <small>Not delivered yet</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <div class="page-numbers">
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Download Orders -->
        <div class="download-orders">
            <button type="button" class="btn btn-secondary" onclick="downloadOrders()">
                <i class="fas fa-download"></i> Download Order History
            </button>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="modal" id="receiptModal">
        <div class="modal-content receipt-modal-content">
            <div class="modal-header">
                <h2>Order Receipt</h2>
                <div class="receipt-modal-actions">
                    <button type="button" class="btn btn-primary btn-sm" onclick="printReceiptModal()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button type="button" onclick="closeReceiptModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body" id="receiptModalBody"></div>
        </div>
    </div>

    <!-- Review Modal -->
    <div class="modal" id="reviewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Write a Review</h2>
                <button type="button" onclick="closeReviewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="reviewForm">
                    <input type="hidden" id="reviewOrderId">
                    
                    <div class="form-group">
                        <label>Overall Rating</label>
                        <div class="rating-stars" id="ratingStars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star" data-rating="<?php echo $i; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" id="rating" name="rating" value="5" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="reviewTitle">Review Title</label>
                        <input type="text" id="reviewTitle" name="title" class="form-control" placeholder="Summarize your experience">
                    </div>
                    
                    <div class="form-group">
                        <label for="reviewComment">Your Review</label>
                        <textarea id="reviewComment" name="comment" class="form-control" rows="4" 
                                  placeholder="Share details about your experience..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane"></i> Submit Review
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <script>
        function showReceiptModal(orderId) {
            const source = document.getElementById(`receiptData-${orderId}`);
            const modalBody = document.getElementById('receiptModalBody');
            const modal = document.getElementById('receiptModal');
            if (!source || !modalBody || !modal) return;

            modalBody.innerHTML = source.innerHTML;
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeReceiptModal() {
            const modal = document.getElementById('receiptModal');
            if (!modal) return;
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function printReceiptModal() {
            const modalBody = document.getElementById('receiptModalBody');
            if (!modalBody) return;

            const printWindow = window.open('', '_blank', 'width=980,height=760');
            if (!printWindow) {
                showNotification('Unable to open print window. Please allow pop-ups.', 'error');
                return;
            }

            printWindow.document.write(`
                <html>
                <head>
                    <title>Receipt</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 24px; color: #1f1f1f; }
                        h2 { margin-top: 0; }
                        p { margin: 6px 0; }
                        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background: #f7f7f7; }
                        .receipt-totals { margin-top: 14px; }
                        .receipt-totals div { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #eee; }
                        .receipt-totals .grand { border-top: 2px solid #222; border-bottom: none; font-size: 18px; font-weight: 700; margin-top: 6px; }
                    </style>
                </head>
                <body>${modalBody.innerHTML}</body>
                </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        }

        function togglePaymentSlip(orderId) {
            const slipCard = document.getElementById(`paymentSlip-${orderId}`);
            if (!slipCard) return;
            slipCard.classList.toggle('active');
        }

        function printPaymentSlip(orderId) {
            const printable = document.getElementById(`printableSlip-${orderId}`);
            if (!printable) return;

            const printWindow = window.open('', '_blank', 'width=900,height=700');
            if (!printWindow) {
                showNotification('Unable to open print window. Please allow pop-ups.', 'error');
                return;
            }

            printWindow.document.write(`
                <html>
                <head>
                    <title>Payment Slip</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; color: #222; }
                        h2 { margin: 0 0 12px; }
                        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; gap: 12px; }
                        .row span { color: #666; }
                        .row strong { text-align: right; word-break: break-word; }
                        .total { border-top: 2px solid #333; margin-top: 12px; font-size: 18px; font-weight: 700; }
                    </style>
                </head>
                <body>
                    <h2>Payment Slip</h2>
                    ${printable.innerHTML
                        .replace(/class="slip-row total-row"/g, 'class="row total"')
                        .replace(/class="slip-row"/g, 'class="row"')}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        }

        // Cancel order
        function cancelOrder(orderId) {
            if (confirm('Are you sure you want to cancel this order?')) {
                fetch('api/orders.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'cancel',
                        order_id: orderId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Order cancelled successfully', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification(data.message || 'Failed to cancel order', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
            }
        }

        // Mark received
        function markReceived(orderId) {
            if (!confirm('Confirm you have received this order?')) return;

            fetch('api/orders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_received', order_id: orderId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showNotification('Thank you — receipt confirmed', 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showNotification(data.message || 'Failed to confirm receipt', 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showNotification('An error occurred', 'error');
            });
        }
        
        // Show review form
        function showReviewForm(orderId) {
            document.getElementById('reviewOrderId').value = orderId;
            document.getElementById('reviewModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        // Modal close on outside click
        const reviewModal = document.getElementById('reviewModal');
        if (reviewModal) {
            reviewModal.addEventListener('click', function(e) {
                if (e.target === reviewModal) {
                    closeReviewModal();
                }
            });
        }

        const receiptModal = document.getElementById('receiptModal');
        if (receiptModal) {
            receiptModal.addEventListener('click', function(e) {
                if (e.target === receiptModal) {
                    closeReceiptModal();
                }
            });
        }
        
        // Close review modal
        function closeReviewModal() {
            document.getElementById('reviewModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            // Reset form
            document.getElementById('reviewForm').reset();
            document.getElementById('rating').value = '5';
            document.querySelectorAll('#ratingStars i').forEach((s, index) => {
                if (index < 5) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        }
        
        // Rating stars functionality
        const ratingStars = document.querySelectorAll('#ratingStars i');
        const ratingInput = document.getElementById('rating');
        
        ratingStars.forEach(star => {
            star.addEventListener('click', function() {
                const rating = this.dataset.rating;
                ratingInput.value = rating;
                
                ratingStars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });
            
            star.addEventListener('mouseover', function() {
                const rating = this.dataset.rating;
                ratingStars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('hover');
                    } else {
                        s.classList.remove('hover');
                    }
                });
            });
        });
        
        document.getElementById('ratingStars').addEventListener('mouseleave', function() {
            const rating = ratingInput.value;
            ratingStars.forEach((s, index) => {
                s.classList.remove('hover');
                if (index < rating) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        });
        
        // Submit review
        document.getElementById('reviewForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const orderId = document.getElementById('reviewOrderId').value;
            const rating = document.getElementById('rating').value;
            const title = document.getElementById('reviewTitle').value;
            const comment = document.getElementById('reviewComment').value;
            
            fetch('api/reviews.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'add',
                    order_id: orderId,
                    rating: rating,
                    title: title,
                    comment: comment
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Review submitted successfully', 'success');
                    closeReviewModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || 'Failed to submit review', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        });
        
        // Reorder
        function reorder(orderId) {
            if (confirm('Add all items from this order to your cart?')) {
                fetch('api/orders.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'reorder',
                        order_id: orderId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Items added to cart!', 'success');
                        // Update cart count
                        const cartCount = document.querySelector('.cart-count');
                        if (cartCount) {
                            const currentCount = parseInt(cartCount.textContent) || 0;
                            const addedItems = data.items_added || 0;
                            cartCount.textContent = currentCount + addedItems;
                        }
                    } else {
                        showNotification(data.message || 'Failed to reorder', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
            }
        }
        
        // Download order history
        function downloadOrders() {
            fetch('api/orders.php?action=export')
                .then(response => response.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'order_history.csv';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Failed to download order history', 'error');
                });
        }
        
        // Search orders
        document.getElementById('orderSearch').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            document.querySelectorAll('.order-card').forEach(card => {
                const orderNumber = card.querySelector('h3').textContent.toLowerCase();
                const orderDate = card.querySelector('.order-date').textContent.toLowerCase();
                
                if (orderNumber.includes(searchTerm) || orderDate.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
        
        // Show notification
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                ${message}
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
    </script>
    
    <style>
        .orders-container {
            padding: 2rem 0;
        }
        
        .page-title {
            margin-bottom: 2rem;
            color: var(--primary-color);
        }
        
        .order-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background-color: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: var(--shadow);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .stat-info h3 {
            margin-bottom: 0.2rem;
            font-size: 1.5rem;
        }
        
        .stat-info p {
            margin-bottom: 0;
            color: var(--text-light);
        }
        
        .orders-filter {
            background-color: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .filter-tab {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            color: var(--text-color);
            background-color: var(--light-color);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        
        .filter-tab:hover,
        .filter-tab.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .filter-search {
            display: flex;
        }
        
        .filter-search input {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius) 0 0 var(--radius);
        }
        
        .filter-search button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 0 1rem;
            border-radius: 0 var(--radius) var(--radius) 0;
            cursor: pointer;
        }
        
        .no-orders {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .no-orders i {
            font-size: 4rem;
            color: #e0e0e0;
            margin-bottom: 1rem;
        }
        
        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .order-card {
            background-color: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .order-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .order-meta h3 {
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        
        .order-date {
            color: var(--text-light);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending {
            background-color: #FFF3CD;
            color: #856404;
        }
        
        .status-confirmed {
            background-color: #D1ECF1;
            color: #0C5460;
        }
        
        .status-preparing {
            background-color: #D4EDDA;
            color: #155724;
        }
        
        .status-out_for_delivery {
            background-color: #CCE5FF;
            color: #004085;
        }
        
        .status-delivered {
            background-color: #D1E7DD;
            color: #0F5132;
        }
        
        .status-cancelled {
            background-color: #F8D7DA;
            color: #721C24;
        }
        
        .order-items {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .order-item {
            display: flex;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .item-image {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .item-details {
            flex: 1;
        }
        
        .item-details h4 {
            margin-bottom: 0.3rem;
            font-size: 1rem;
        }
        
        .item-quantity {
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .item-price {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .order-footer {
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 1.5rem;
        }
        
        .order-totals {
            min-width: 200px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            color: var(--text-light);
        }
        
        .total-row.grand-total {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--text-color);
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid var(--border-color);
        }
        
        .order-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .order-actions .btn {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.82rem;
        }

        .payment-slip-card {
            display: none;
            margin: 0 1.5rem 1.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            background-color: #fff;
            overflow: hidden;
        }

        .payment-slip-card.active {
            display: block;
        }

        .slip-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--light-color);
        }

        .slip-header h4 {
            margin: 0;
            font-size: 1.05rem;
        }

        .slip-header p {
            margin: 0.25rem 0 0;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .slip-body {
            padding: 0.75rem 1.25rem 1rem;
        }

        .slip-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            padding: 0.6rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .slip-row span {
            color: var(--text-light);
            min-width: 140px;
        }

        .slip-row strong {
            text-align: right;
            word-break: break-word;
        }

        .slip-row.total-row {
            margin-top: 0.4rem;
            border-top: 2px solid var(--text-color);
            border-bottom: none;
            padding-top: 0.9rem;
            font-size: 1.05rem;
        }
        
        .order-tracking {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            background-color: var(--light-color);
        }
        
        .order-tracking h4 {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .tracking-timeline {
            display: flex;
            justify-content: space-between;
            position: relative;
        }
        
        .tracking-timeline::before {
            content: '';
            position: absolute;
            top: 25px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: var(--border-color);
            z-index: 1;
        }
        
        .tracking-step {
            position: relative;
            z-index: 2;
            flex: 1;
            text-align: center;
        }
        
        .step-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: white;
            border: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 1.2rem;
            color: var(--text-light);
        }
        
        .tracking-step.completed .step-icon {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .tracking-step.active .step-icon {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            color: white;
        }
        
        .step-content {
            padding: 0 0.5rem;
        }
        
        .step-content strong {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }
        
        .step-content small {
            color: var(--text-light);
            font-size: 0.8rem;
        }
        
        .download-orders {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }

        .receipt-template {
            display: none;
        }

        .receipt-modal-content {
            max-width: 820px;
        }

        .receipt-modal-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .receipt-print h2 {
            margin: 0 0 0.8rem;
            color: var(--text-color);
        }

        .receipt-print p {
            margin: 0.35rem 0;
        }

        .receipt-items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.9rem 0;
        }

        .receipt-items-table th,
        .receipt-items-table td {
            border: 1px solid var(--border-color);
            padding: 0.55rem;
            text-align: left;
        }

        .receipt-items-table th {
            background-color: var(--light-color);
            font-weight: 600;
        }

        .receipt-totals {
            margin-top: 0.8rem;
        }

        .receipt-totals div {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.4rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .receipt-totals .grand {
            margin-top: 0.3rem;
            border-top: 2px solid var(--text-color);
            border-bottom: none;
            font-size: 1.05rem;
            font-weight: 700;
        }
        
        /* Review Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-header h2 {
            margin: 0;
            color: var(--text-color);
        }

        .modal-header button {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.2s;
        }

        .modal-header button:hover {
            color: var(--primary-color);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .form-control textarea {
            resize: vertical;
            min-height: 100px;
        }

        .btn-block {
            width: 100%;
        }

        .rating-stars {
            display: flex;
            gap: 0.5rem;
            margin: 0.5rem 0;
        }
        
        .rating-stars i {
            font-size: 2rem;
            color: #e0e0e0;
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .rating-stars i.active,
        .rating-stars i.hover {
            color: #FFC107;
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            color: white;
            z-index: 3000;
            animation: slideInRight 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .notification-success {
            background-color: #4CAF50;
        }

        .notification-error {
            background-color: #f44336;
        }
        
        @media (max-width: 768px) {
            .orders-filter {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-tabs {
                justify-content: center;
            }
            
            .order-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .order-footer {
                flex-direction: column;
                align-items: stretch;
            }
            
            .order-actions {
                justify-content: center;
            }

            .slip-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .slip-row {
                flex-direction: column;
                gap: 0.35rem;
            }

            .slip-row strong {
                text-align: left;
            }
            
            .tracking-timeline {
                overflow-x: auto;
                padding-bottom: 1rem;
            }
            
            .tracking-step {
                min-width: 120px;
            }

            .receipt-modal-content {
                width: 94%;
            }
        }
        
        @media (max-width: 576px) {
            .order-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-tabs {
                overflow-x: auto;
                padding-bottom: 0.5rem;
            }
            
            .order-actions .btn {
                flex: 1;
                text-align: center;
            }
        }
    </style>
</body>
</html>
