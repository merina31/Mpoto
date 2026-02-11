<?php
session_start();
require_once 'includes/config.php';
;

// Require login for checkout
$auth->requireLogin();

$user_id = $_SESSION['user_id'];
$db = Database::getInstance();

// Load user data
$user_sql = "SELECT * FROM users WHERE id = ?";
$user_stmt = $db->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Load cart items
$cart = [];
$cartTotal = 0;
$deliveryFee = 5.00;
$taxRate = 0.08;

$cart_sql = "SELECT c.*, m.name, m.price, m.discount_price, m.image_url 
            FROM cart c 
            JOIN meals m ON c.meal_id = m.id 
            WHERE c.user_id = ? AND m.is_available = 1";
$cart_stmt = $db->prepare($cart_sql);
$cart_stmt->bind_param("i", $user_id);
$cart_stmt->execute();
$cart_result = $cart_stmt->get_result();

while ($row = $cart_result->fetch_assoc()) {
    $row['final_price'] = $row['discount_price'] ?: $row['price'];
    $row['subtotal'] = $row['final_price'] * $row['quantity'];
    $cart[] = $row;
    $cartTotal += $row['subtotal'];
}

// Check if cart is empty
if (empty($cart)) {
    header('Location: cart.php');
    exit();
}

$taxAmount = $cartTotal * $taxRate;
$grandTotal = $cartTotal + $deliveryFee + $taxAmount;

// Handle form submission
$errors = [];
$success = false;
$order_id = null;
$order_number = null;
$payment_method = '';
$payment_status = 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? '';
    $delivery_address = trim($_POST['delivery_address'] ?? ($user['address'] ?? ''));
    $contact_phone = trim($_POST['contact_phone'] ?? ($user['phone'] ?? ''));
    $special_instructions = trim($_POST['special_instructions'] ?? '');
    $card_number = preg_replace('/\s+/', '', $_POST['card_number'] ?? '');
    $card_expiry = $_POST['card_expiry'] ?? '';
    $card_cvv = $_POST['card_cvv'] ?? '';
    $card_name = trim($_POST['card_name'] ?? '');
    $paypal_email = trim($_POST['paypal_email'] ?? '');
    
    // Validation
    if (empty($delivery_address)) {
        $errors['delivery_address'] = 'Delivery address is required';
    }
    
    if (empty($contact_phone)) {
        $errors['contact_phone'] = 'Contact phone is required';
    } elseif (!preg_match('/^\+?[0-9\s\-\(\)]{10,}$/', $contact_phone)) {
        $errors['contact_phone'] = 'Invalid phone number format';
    }
    
    if (empty($payment_method)) {
        $errors['payment_method'] = 'Please select a payment method';
    }
    
    // Validate specific payment methods
    switch ($payment_method) {
        case 'credit_card':
        case 'debit_card':
            if (empty($card_number)) {
                $errors['card_number'] = 'Card number is required';
            } elseif (!preg_match('/^\d{16}$/', $card_number)) {
                $errors['card_number'] = 'Invalid card number (16 digits required)';
            }
            
            if (empty($card_expiry)) {
                $errors['card_expiry'] = 'Expiry date is required';
            } elseif (!preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $card_expiry)) {
                $errors['card_expiry'] = 'Invalid expiry date (MM/YY)';
            } else {
                list($month, $year) = explode('/', $card_expiry);
                $expiry_date = DateTime::createFromFormat('y-m-d', $year . '-' . $month . '-01');
                $current_date = new DateTime();
                if ($expiry_date < $current_date) {
                    $errors['card_expiry'] = 'Card has expired';
                }
            }
            
            if (empty($card_cvv)) {
                $errors['card_cvv'] = 'CVV is required';
            } elseif (!preg_match('/^\d{3,4}$/', $card_cvv)) {
                $errors['card_cvv'] = 'Invalid CVV (3-4 digits)';
            }
            
            if (empty($card_name)) {
                $errors['card_name'] = 'Cardholder name is required';
            }
            $payment_status = 'completed'; // Cards are instant payment
            break;
            
        case 'paypal':
            if (empty($paypal_email)) {
                $errors['paypal_email'] = 'PayPal email is required';
            } elseif (!filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
                $errors['paypal_email'] = 'Invalid email format';
            }
            $payment_status = 'completed'; // PayPal is instant payment
            break;
            
        case 'cash_on_delivery':
            $payment_status = 'pending'; // Will be completed when delivered
            break;
            
        case 'bank_transfer':
            $payment_status = 'pending'; // Will be completed when transfer is verified
            break;
    }
    
    if (empty($errors)) {
        // Start transaction
        $db->begin_transaction();
        
        try {
            // Generate unique order number
            $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
            
            // Insert order
            $order_sql = "INSERT INTO orders (order_number, user_id, total_amount, delivery_fee, tax_amount, 
                          final_amount, delivery_address, contact_phone, special_instructions, 
                          payment_method, payment_status, status) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
            $order_stmt = $db->prepare($order_sql);
            $order_stmt->bind_param("sidddddsssss", 
                $order_number, $user_id, $cartTotal, $deliveryFee, $taxAmount, $grandTotal,
                $delivery_address, $contact_phone, $special_instructions, $payment_method, $payment_status
            );
            
            if (!$order_stmt->execute()) {
                throw new Exception("Failed to create order: " . $order_stmt->error);
            }
            
            $order_id = $db->insert_id;
            
            // Insert order items
            $item_sql = "INSERT INTO order_items (order_id, meal_id, quantity, unit_price, total_price) 
                         VALUES (?, ?, ?, ?, ?)";
            $item_stmt = $db->prepare($item_sql);
            
            foreach ($cart as $item) {
                $item_stmt->bind_param("iiidd", 
                    $order_id, 
                    $item['meal_id'], 
                    $item['quantity'],
                    $item['final_price'],
                    $item['subtotal']
                );
                if (!$item_stmt->execute()) {
                    throw new Exception("Failed to add order items: " . $item_stmt->error);
                }
                
                // Update meal statistics
                $update_sql = "UPDATE meals SET total_orders = total_orders + ? WHERE id = ?";
                $update_stmt = $db->prepare($update_sql);
                $update_stmt->bind_param("ii", $item['quantity'], $item['meal_id']);
                $update_stmt->execute();
            }
            
            // Clear cart
            $clear_sql = "DELETE FROM cart WHERE user_id = ?";
            $clear_stmt = $db->prepare($clear_sql);
            $clear_stmt->bind_param("i", $user_id);
            $clear_stmt->execute();
            
            // Update user address and phone if provided
            if (!empty($delivery_address) || !empty($contact_phone)) {
                $update_fields = [];
                $update_params = [];
                $types = "";
                
                if (!empty($delivery_address)) {
                    $update_fields[] = "address = ?";
                    $update_params[] = $delivery_address;
                    $types .= "s";
                }
                
                if (!empty($contact_phone)) {
                    $update_fields[] = "phone = ?";
                    $update_params[] = $contact_phone;
                    $types .= "s";
                }
                
                if (!empty($update_fields)) {
                    $update_user_sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
                    $update_params[] = $user_id;
                    $types .= "i";
                    
                    $update_user_stmt = $db->prepare($update_user_sql);
                    $update_user_stmt->bind_param($types, ...$update_params);
                    $update_user_stmt->execute();
                }
            }
            
            // Create payment record
            $payment_sql = "INSERT INTO payments (order_id, user_id, amount, payment_method, payment_status, 
                           transaction_id, payment_details, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $payment_stmt = $db->prepare($payment_sql);
            
            // Generate transaction ID
            $transaction_id = 'TXN-' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
            
            // Prepare payment details based on method
            $payment_details = json_encode([
                'payment_method' => $payment_method,
                'card_last4' => ($payment_method === 'credit_card' || $payment_method === 'debit_card') ? substr($card_number, -4) : null,
                'paypal_email' => $payment_method === 'paypal' ? $paypal_email : null,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $payment_stmt->bind_param("iidssss", 
                $order_id, $user_id, $grandTotal, $payment_method, $payment_status,
                $transaction_id, $payment_details
            );
            $payment_stmt->execute();
            
            // Send notification to admin (ensure notifications table exists)
            $admin_sql = "SELECT id FROM users WHERE role = 'admin' AND id != ?";
            $admin_stmt = $db->prepare($admin_sql);
            $admin_stmt->bind_param("i", $user_id);
            $admin_stmt->execute();
            $admin_result = $admin_stmt->get_result();
            
            // Check if notifications table exists, create if not
            $table_check = $db->query("SHOW TABLES LIKE 'notifications'");
            if ($table_check->num_rows == 0) {
                // Create notifications table
                $create_notif_sql = "CREATE TABLE IF NOT EXISTS notifications (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT,
                    sender_id INT,
                    message TEXT,
                    link VARCHAR(255),
                    unread BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )";
                $db->query($create_notif_sql);
            }
            
            while ($admin = $admin_result->fetch_assoc()) {
                $notif_sql = "INSERT INTO notifications (user_id, sender_id, message, link, unread) 
                              VALUES (?, ?, ?, ?, 1)";
                $notif_stmt = $db->prepare($notif_sql);
                
                $message = "🛒 New Order #{$order_number} from " . htmlspecialchars($user['full_name']) . 
                          " - Amount: $" . number_format($grandTotal, 2) . 
                          " - Payment: " . ucfirst(str_replace('_', ' ', $payment_method)) . 
                          " - Status: " . ($payment_status === 'completed' ? '✅ Paid' : '⏳ Pending');
                
                $link = "admin/index.php?view=orders&order_id={$order_id}";
                $notif_stmt->bind_param("iiss", $admin['id'], $user_id, $message, $link);
                $notif_stmt->execute();
            }
            
            // Send email notification to admin (optional)
            if (defined('ADMIN_EMAIL') && ADMIN_EMAIL) {
                $admin_email = ADMIN_EMAIL;
                $subject = "New Order #{$order_number} - " . SITE_NAME;
                $message = "Dear Admin,\n\n";
                $message .= "A new order has been placed:\n\n";
                $message .= "Order Number: {$order_number}\n";
                $message .= "Customer: " . htmlspecialchars($user['full_name']) . "\n";
                $message .= "Email: " . htmlspecialchars($user['email']) . "\n";
                $message .= "Phone: " . htmlspecialchars($contact_phone) . "\n";
                $message .= "Total Amount: $" . number_format($grandTotal, 2) . "\n";
                $message .= "Payment Method: " . ucfirst(str_replace('_', ' ', $payment_method)) . "\n";
                $message .= "Payment Status: " . ($payment_status === 'completed' ? 'Paid' : 'Pending') . "\n";
                $message .= "\nView order details: " . SITE_URL . "/admin/index.php?view=orders&order_id={$order_id}\n\n";
                $message .= "Best regards,\n" . SITE_NAME . " System";
                
                $headers = "From: " . SITE_EMAIL . "\r\n" .
                          "Reply-To: " . SITE_EMAIL . "\r\n" .
                          "X-Mailer: PHP/" . phpversion();
                
                @mail($admin_email, $subject, $message, $headers);
            }
            
            // Commit transaction
            $db->commit();
            
            $success = true;
            
            // Clear session cart if exists
            unset($_SESSION['cart']);
            
        } catch (Exception $e) {
            $db->rollback();
            $errors['general'] = 'Failed to process order. Please try again. Error: ' . $e->getMessage();
            error_log("Checkout error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Additional styles for receipt printing */
        @media print {
            body * {
                visibility: hidden;
            }
            #printableReceipt, #printableReceipt * {
                visibility: visible;
            }
            #printableReceipt {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                font-size: 12px;
                padding: 20px;
            }
            .no-print {
                display: none !important;
            }
            .receipt-items th, .receipt-items td {
                border: 1px solid #000;
                padding: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Checkout Page -->
    <div class="checkout-container container">
        <h1 class="page-title">Checkout & Payment</h1>
        
        <?php if ($success): ?>
            <!-- Success Page -->
            <div class="checkout-success">
                <div class="success-header">
                    <i class="fas fa-check-circle"></i>
                    <h2>Payment Successful!</h2>
                    <p>Your order has been confirmed and payment processed</p>
                </div>
                
                <div class="order-summary">
                    <h3>Order & Payment Details</h3>
                    <div class="summary-card">
                        <div class="summary-row">
                            <span>Order Number:</span>
                            <strong>#<?php echo $order_number; ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Transaction ID:</span>
                            <strong><?php echo $transaction_id ?? 'TXN-' . date('YmdHis'); ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Payment Method:</span>
                            <span class="payment-method"><?php echo ucwords(str_replace('_', ' ', $payment_method)); ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Payment Status:</span>
                            <span class="status-badge <?php echo $payment_status === 'completed' ? 'status-success' : 'status-pending'; ?>">
                                <?php echo $payment_status === 'completed' ? '✅ Paid' : '⏳ Pending'; ?>
                            </span>
                        </div>
                        <div class="summary-row">
                            <span>Order Status:</span>
                            <span class="status-badge status-pending">📋 Processing</span>
                        </div>
                        <div class="summary-row">
                            <span>Total Amount:</span>
                            <strong class="total-amount">$<?php echo number_format($grandTotal, 2); ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Estimated Delivery:</span>
                            <strong>30-45 minutes</strong>
                        </div>
                    </div>
                </div>
                
                <div class="order-actions no-print">
                    <button onclick="printReceipt()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Receipt
                    </button>
                    <button onclick="downloadReceipt()" class="btn btn-secondary">
                        <i class="fas fa-download"></i> Download PDF
                    </button>
                    <a href="orders.php?order_id=<?php echo $order_id; ?>" class="btn btn-success">
                        <i class="fas fa-receipt"></i> View Order Details
                    </a>
                    <a href="menu.php" class="btn btn-outline">
                        <i class="fas fa-utensils"></i> Continue Shopping
                    </a>
                </div>
                
                <!-- Printable Receipt -->
                <div class="printable-receipt" id="printableReceipt">
                    <div class="receipt-header">
                        <h2><?php echo SITE_NAME; ?></h2>
                        <p>Food Delivery & Takeaway Service</p>
                        <p><?php echo defined('SITE_ADDRESS') ? SITE_ADDRESS : '123 Food Street, City, Country'; ?></p>
                        <p>Phone: <?php echo defined('SITE_PHONE') ? SITE_PHONE : '+1 234 567 8900'; ?></p>
                        <p>Email: <?php echo defined('SITE_EMAIL') ? SITE_EMAIL : 'info@foodorder.com'; ?></p>
                        <hr>
                    </div>
                    
                    <div class="receipt-body">
                        <div class="receipt-info">
                            <p><strong>RECEIPT #:</strong> <?php echo $order_number; ?></p>
                            <p><strong>TRANSACTION #:</strong> <?php echo $transaction_id ?? 'TXN-' . date('YmdHis'); ?></p>
                            <p><strong>DATE:</strong> <?php echo date('F d, Y h:i A'); ?></p>
                            <p><strong>CUSTOMER:</strong> <?php echo htmlspecialchars($user['full_name']); ?></p>
                            <p><strong>EMAIL:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                            <p><strong>PHONE:</strong> <?php echo htmlspecialchars($contact_phone); ?></p>
                            <p><strong>ADDRESS:</strong> <?php echo htmlspecialchars($delivery_address); ?></p>
                        </div>
                        
                        <hr>
                        
                        <table class="receipt-items" style="width: 100%; border-collapse: collapse; margin: 15px 0;">
                            <thead>
                                <tr style="background-color: #f5f5f5;">
                                    <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;">Item</th>
                                    <th style="text-align: center; padding: 8px; border-bottom: 2px solid #ddd;">Qty</th>
                                    <th style="text-align: right; padding: 8px; border-bottom: 2px solid #ddd;">Price</th>
                                    <th style="text-align: right; padding: 8px; border-bottom: 2px solid #ddd;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cart as $item): ?>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($item['name']); ?></td>
                                    <td style="text-align: center; padding: 8px; border-bottom: 1px solid #eee;"><?php echo $item['quantity']; ?></td>
                                    <td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee;">$<?php echo number_format($item['final_price'], 2); ?></td>
                                    <td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee;">$<?php echo number_format($item['subtotal'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <hr>
                        
                        <div class="receipt-totals" style="margin: 15px 0;">
                            <div class="total-row" style="display: flex; justify-content: space-between; margin: 5px 0;">
                                <span>Subtotal:</span>
                                <span>$<?php echo number_format($cartTotal, 2); ?></span>
                            </div>
                            <div class="total-row" style="display: flex; justify-content: space-between; margin: 5px 0;">
                                <span>Delivery Fee:</span>
                                <span>$<?php echo number_format($deliveryFee, 2); ?></span>
                            </div>
                            <div class="total-row" style="display: flex; justify-content: space-between; margin: 5px 0;">
                                <span>Tax (<?php echo ($taxRate * 100); ?>%):</span>
                                <span>$<?php echo number_format($taxAmount, 2); ?></span>
                            </div>
                            <div class="total-row grand-total" style="display: flex; justify-content: space-between; margin: 10px 0; padding-top: 10px; border-top: 2px solid #000; font-weight: bold; font-size: 14px;">
                                <span>TOTAL AMOUNT:</span>
                                <span>$<?php echo number_format($grandTotal, 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="receipt-payment" style="margin: 15px 0; padding: 10px; background-color: #f9f9f9; border-radius: 5px;">
                            <p><strong>PAYMENT METHOD:</strong> <?php echo strtoupper(str_replace('_', ' ', $payment_method)); ?></p>
                            <p><strong>PAYMENT STATUS:</strong> <span style="color: <?php echo $payment_status === 'completed' ? 'green' : 'orange'; ?>;"><?php echo $payment_status === 'completed' ? 'COMPLETED' : 'PENDING'; ?></span></p>
                            <p><strong>ORDER STATUS:</strong> PROCESSING</p>
                        </div>
                        
                        <div class="receipt-footer" style="text-align: center; margin-top: 20px; color: #666; font-size: 11px;">
                            <p>Thank you for your order!</p>
                            <p>Estimated delivery time: 30-45 minutes</p>
                            <p>For inquiries: <?php echo defined('SITE_PHONE') ? SITE_PHONE : '+1 234 567 8900'; ?></p>
                            <p>Email: <?php echo defined('SITE_EMAIL') ? SITE_EMAIL : 'support@foodorder.com'; ?></p>
                            <p>This is a computer generated receipt. No signature required.</p>
                        </div>
                    </div>
                </div>
                
                <div class="delivery-info no-print">
                    <h3><i class="fas fa-shipping-fast"></i> Delivery Information</h3>
                    <div class="delivery-details">
                        <p><strong>Delivery Address:</strong> <?php echo htmlspecialchars($delivery_address); ?></p>
                        <p><strong>Contact Phone:</strong> <?php echo htmlspecialchars($contact_phone); ?></p>
                        <?php if (!empty($special_instructions)): ?>
                            <p><strong>Special Instructions:</strong> <?php echo htmlspecialchars($special_instructions); ?></p>
                        <?php endif; ?>
                        <p><strong>Estimated Arrival:</strong> <?php echo date('h:i A', strtotime('+45 minutes')); ?></p>
                    </div>
                </div>
                
                <div class="whatsapp-notification no-print">
                    <h3><i class="fab fa-whatsapp"></i> Order Updates</h3>
                    <p>You will receive order updates via WhatsApp on: <strong><?php echo htmlspecialchars($contact_phone); ?></strong></p>
                    <a href="https://wa.me/<?php echo WHATSAPP_NUMBER; ?>?text=Order%20inquiry%20for%20order%20%23<?php echo $order_number; ?>" 
                       class="btn btn-success" target="_blank">
                        <i class="fab fa-whatsapp"></i> Chat on WhatsApp
                    </a>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Checkout Form -->
            <div class="checkout-layout">
                <div class="checkout-form">
                    <div class="checkout-progress">
                        <div class="progress-step active">
                            <div class="step-number">1</div>
                            <div class="step-text">Delivery Info</div>
                        </div>
                        <div class="progress-step active">
                            <div class="step-number">2</div>
                            <div class="step-text">Payment Method</div>
                        </div>
                        <div class="progress-step">
                            <div class="step-number">3</div>
                            <div class="step-text">Confirmation</div>
                        </div>
                    </div>
                    
                    <?php if (isset($errors['general'])): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['general']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="checkoutForm" class="payment-form">
                        <input type="hidden" name="payment_processed" value="1">
                        
                        <h2><i class="fas fa-truck"></i> Delivery Information</h2>
                        
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_phone">Contact Phone *</label>
                            <input type="tel" id="contact_phone" name="contact_phone" 
                                   class="form-control <?php echo isset($errors['contact_phone']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                   placeholder="+1 234 567 8900"
                                   required>
                            <?php if (isset($errors['contact_phone'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['contact_phone']); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="delivery_address">Delivery Address *</label>
                            <textarea id="delivery_address" name="delivery_address" 
                                      class="form-control <?php echo isset($errors['delivery_address']) ? 'is-invalid' : ''; ?>" 
                                      rows="3" 
                                      placeholder="Enter your complete delivery address"
                                      required><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                            <?php if (isset($errors['delivery_address'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['delivery_address']); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="special_instructions">Special Instructions (Optional)</label>
                            <textarea id="special_instructions" name="special_instructions" class="form-control" rows="2"
                                      placeholder="Delivery instructions, dietary restrictions, gate code, etc."></textarea>
                        </div>
                        
                        <h2><i class="fas fa-credit-card"></i> Select Payment Method</h2>
                        
                        <div class="payment-methods">
                            <div class="payment-option">
                                <input type="radio" id="credit_card" name="payment_method" value="credit_card">
                                <label for="credit_card">
                                    <i class="fas fa-credit-card"></i>
                                    <div>
                                        <strong>Credit Card</strong>
                                        <small>Visa, Mastercard, American Express</small>
                                    </div>
                                    <span class="payment-icon">💳</span>
                                </label>
                            </div>
                            
                            <div class="payment-option">
                                <input type="radio" id="debit_card" name="payment_method" value="debit_card">
                                <label for="debit_card">
                                    <i class="fas fa-credit-card"></i>
                                    <div>
                                        <strong>Debit Card</strong>
                                        <small>Direct bank payment</small>
                                    </div>
                                    <span class="payment-icon">🏦</span>
                                </label>
                            </div>
                            
                            <div class="payment-option">
                                <input type="radio" id="paypal" name="payment_method" value="paypal">
                                <label for="paypal">
                                    <i class="fab fa-paypal"></i>
                                    <div>
                                        <strong>PayPal</strong>
                                        <small>Fast and secure online payment</small>
                                    </div>
                                    <span class="payment-icon">🔗</span>
                                </label>
                            </div>
                            
                            <div class="payment-option">
                                <input type="radio" id="cash_on_delivery" name="payment_method" value="cash_on_delivery" checked>
                                <label for="cash_on_delivery">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <div>
                                        <strong>Cash on Delivery</strong>
                                        <small>Pay when you receive your order</small>
                                    </div>
                                    <span class="payment-icon">💵</span>
                                </label>
                            </div>
                            
                            <div class="payment-option">
                                <input type="radio" id="bank_transfer" name="payment_method" value="bank_transfer">
                                <label for="bank_transfer">
                                    <i class="fas fa-university"></i>
                                    <div>
                                        <strong>Bank Transfer</strong>
                                        <small>Direct bank transfer</small>
                                    </div>
                                    <span class="payment-icon">🏛️</span>
                                </label>
                            </div>
                        </div>
                        
                        <?php if (isset($errors['payment_method'])): ?>
                            <div class="alert alert-error"><?php echo htmlspecialchars($errors['payment_method']); ?></div>
                        <?php endif; ?>
                        
                        <!-- Card Details -->
                        <div class="payment-details" id="cardDetails" style="display: none;">
                            <h3><i class="fas fa-lock"></i> Card Information</h3>
                            
                            <div class="form-group">
                                <label for="card_name">Cardholder Name *</label>
                                <input type="text" id="card_name" name="card_name" 
                                       class="form-control <?php echo isset($errors['card_name']) ? 'is-invalid' : ''; ?>"
                                       placeholder="John Doe">
                                <?php if (isset($errors['card_name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['card_name']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label for="card_number">Card Number *</label>
                                <input type="text" id="card_number" name="card_number" 
                                       class="form-control <?php echo isset($errors['card_number']) ? 'is-invalid' : ''; ?>"
                                       placeholder="1234 5678 9012 3456" maxlength="19">
                                <div class="card-icons">
                                    <i class="fab fa-cc-visa" title="Visa"></i>
                                    <i class="fab fa-cc-mastercard" title="Mastercard"></i>
                                    <i class="fab fa-cc-amex" title="American Express"></i>
                                </div>
                                <?php if (isset($errors['card_number'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['card_number']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="card_expiry">Expiry Date (MM/YY) *</label>
                                    <input type="text" id="card_expiry" name="card_expiry" 
                                           class="form-control <?php echo isset($errors['card_expiry']) ? 'is-invalid' : ''; ?>"
                                           placeholder="MM/YY" maxlength="5">
                                    <?php if (isset($errors['card_expiry'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['card_expiry']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label for="card_cvv">CVV *</label>
                                    <div class="cvv-input">
                                        <input type="password" id="card_cvv" name="card_cvv" 
                                               class="form-control <?php echo isset($errors['card_cvv']) ? 'is-invalid' : ''; ?>"
                                               placeholder="123" maxlength="4">
                                        <button type="button" class="toggle-cvv" onclick="toggleCVV()">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <?php if (isset($errors['card_cvv'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['card_cvv']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- PayPal Details -->
                        <div class="payment-details" id="paypalDetails" style="display: none;">
                            <h3><i class="fab fa-paypal"></i> PayPal Information</h3>
                            <div class="form-group">
                                <label for="paypal_email">PayPal Email *</label>
                                <input type="email" id="paypal_email" name="paypal_email" 
                                       class="form-control <?php echo isset($errors['paypal_email']) ? 'is-invalid' : ''; ?>"
                                       placeholder="your.email@example.com">
                                <?php if (isset($errors['paypal_email'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['paypal_email']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="paypal-notice">
                                <i class="fas fa-info-circle"></i>
                                <span>You will be redirected to PayPal to complete your payment</span>
                            </div>
                        </div>
                        
                        <!-- Bank Transfer Details -->
                        <div class="payment-details" id="bankTransferDetails" style="display: none;">
                            <h3><i class="fas fa-university"></i> Bank Transfer Instructions</h3>
                            <div class="bank-info">
                                <p><strong>Bank Name:</strong> FoodOrder Bank</p>
                                <p><strong>Account Name:</strong> <?php echo SITE_NAME; ?> Inc.</p>
                                <p><strong>Account Number:</strong> 1234 5678 9012 3456</p>
                                <p><strong>SWIFT/BIC:</strong> FOBKUS33</p>
                                <p><strong>Reference:</strong> ORDER-<?php echo date('Ymd'); ?></p>
                                <div class="alert alert-info">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Please include your order number in the transfer reference
                                </div>
                            </div>
                        </div>
                        
                        <!-- Cash on Delivery Details -->
                        <div class="payment-details" id="codDetails">
                            <h3><i class="fas fa-money-bill-wave"></i> Cash on Delivery</h3>
                            <div class="cod-info">
                                <p>Pay with cash when your order arrives.</p>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Please have exact change ready for the delivery person
                                </div>
                            </div>
                        </div>
                        
                        <div class="security-notice">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <strong>Secure Payment</strong>
                                <p>Your payment information is encrypted and secure</p>
                            </div>
                        </div>
                        
                        <div class="form-group terms">
                            <label class="checkbox-label">
                                <input type="checkbox" id="terms" required>
                                <span>I agree to the <a href="terms.php" target="_blank">Terms & Conditions</a> and authorize <?php echo SITE_NAME; ?> to charge my selected payment method for the total amount shown.</span>
                            </label>
                        </div>
                        
                        <div class="checkout-actions">
                            <a href="cart.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Cart
                            </a>
                            <button type="submit" class="btn btn-primary btn-checkout">
                                <i class="fas fa-lock"></i> Complete Payment & Place Order
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="order-summary">
                    <h2>Order Summary</h2>
                    
                    <div class="order-items">
                        <?php foreach ($cart as $item): ?>
                        <div class="order-item">
                            <div class="item-image">
                                <img src="<?php echo htmlspecialchars($item['image_url'] ?: 'assets/images/default-food.jpg'); ?>" 
                                     alt="<?php echo htmlspecialchars($item['name']); ?>">
                            </div>
                            <div class="item-info">
                                <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                <div class="item-meta">
                                    <span class="quantity">Qty: <?php echo $item['quantity']; ?></span>
                                    <span class="price">$<?php echo number_format($item['final_price'], 2); ?> each</span>
                                </div>
                            </div>
                            <div class="item-total">
                                $<?php echo number_format($item['subtotal'], 2); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="order-totals">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <span>$<?php echo number_format($cartTotal, 2); ?></span>
                        </div>
                        <div class="total-row">
                            <span>Delivery Fee</span>
                            <span>$<?php echo number_format($deliveryFee, 2); ?></span>
                        </div>
                        <div class="total-row">
                            <span>Tax (<?php echo ($taxRate * 100); ?>%)</span>
                            <span>$<?php echo number_format($taxAmount, 2); ?></span>
                        </div>
                        <div class="total-row grand-total">
                            <span>Total Amount</span>
                            <span class="total-amount">$<?php echo number_format($grandTotal, 2); ?></span>
                        </div>
                    </div>
                    
                    <div class="delivery-estimate">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>Estimated Delivery Time</strong>
                            <p>30-45 minutes</p>
                        </div>
                    </div>
                    
                    <div class="payment-accepted">
                        <p>We accept:</p>
                        <div class="payment-icons">
                            <i class="fab fa-cc-visa" title="Visa"></i>
                            <i class="fab fa-cc-mastercard" title="Mastercard"></i>
                            <i class="fab fa-cc-amex" title="American Express"></i>
                            <i class="fab fa-cc-discover" title="Discover"></i>
                            <i class="fab fa-paypal" title="PayPal"></i>
                            <i class="fab fa-google-pay" title="Google Pay"></i>
                            <i class="fab fa-apple-pay" title="Apple Pay"></i>
                        </div>
                    </div>
                    
                    <div class="money-back-guarantee">
                        <i class="fas fa-shield-alt"></i>
                        <div>
                            <strong>100% Money-Back Guarantee</strong>
                            <p>Full refund if you're not satisfied</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <!-- WhatsApp Float -->
    <a href="https://wa.me/<?php echo WHATSAPP_NUMBER; ?>" class="whatsapp-float" target="_blank">
        <i class="fab fa-whatsapp"></i>
    </a>

    <script>
        // Format card number
        document.getElementById('card_number')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
            let formatted = '';
            for (let i = 0; i < value.length; i++) {
                if (i > 0 && i % 4 === 0) formatted += ' ';
                formatted += value[i];
            }
            e.target.value = formatted.substring(0, 19);
        });
        
        // Format expiry date
        document.getElementById('card_expiry')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9]/g, '');
            if (value.length >= 2) {
                value = value.substring(0, 2) + '/' + value.substring(2, 4);
            }
            e.target.value = value.substring(0, 5);
        });
        
        // Show/hide payment details based on selected method
        const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
        const paymentDetails = document.querySelectorAll('.payment-details');
        
        paymentMethods.forEach(method => {
            method.addEventListener('change', function() {
                // Hide all payment details
                paymentDetails.forEach(detail => {
                    detail.style.display = 'none';
                });
                
                // Show selected payment details
                switch(this.value) {
                    case 'credit_card':
                    case 'debit_card':
                        document.getElementById('cardDetails').style.display = 'block';
                        break;
                    case 'paypal':
                        document.getElementById('paypalDetails').style.display = 'block';
                        break;
                    case 'bank_transfer':
                        document.getElementById('bankTransferDetails').style.display = 'block';
                        break;
                    case 'cash_on_delivery':
                        document.getElementById('codDetails').style.display = 'block';
                        break;
                }
            });
        });
        
        // Toggle CVV visibility
        function toggleCVV() {
            const cvvInput = document.getElementById('card_cvv');
            const eyeIcon = document.querySelector('.toggle-cvv i');
            if (cvvInput.type === 'password') {
                cvvInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                cvvInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }
        
        // Form validation
        document.getElementById('checkoutForm')?.addEventListener('submit', function(e) {
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked').value;
            const termsChecked = document.getElementById('terms').checked;
            
            if (!termsChecked) {
                e.preventDefault();
                alert('Please agree to the Terms & Conditions to proceed');
                return;
            }
            
            if (paymentMethod === 'credit_card' || paymentMethod === 'debit_card') {
                const cardNumber = document.getElementById('card_number').value.replace(/\s+/g, '');
                const cardExpiry = document.getElementById('card_expiry').value;
                const cardCVV = document.getElementById('card_cvv').value;
                const cardName = document.getElementById('card_name').value;
                
                if (!cardNumber || !cardExpiry || !cardCVV || !cardName) {
                    e.preventDefault();
                    alert('Please fill all card details');
                    return false;
                }
                
                // Validate card number (Luhn algorithm)
                if (!validateCardNumber(cardNumber)) {
                    e.preventDefault();
                    alert('Invalid card number. Please check and try again.');
                    return false;
                }
                
                // Validate expiry date
                if (!validateExpiryDate(cardExpiry)) {
                    e.preventDefault();
                    alert('Card has expired or invalid expiry date');
                    return false;
                }
            }
            
            // Show loading
            const submitBtn = document.querySelector('.btn-checkout');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Payment...';
                submitBtn.disabled = true;
            }
            
            // Simulate payment processing delay
            setTimeout(() => {
                // Form will submit normally
            }, 1000);
        });
        
        // Card validation functions
        function validateCardNumber(cardNumber) {
            // Luhn algorithm
            let sum = 0;
            let shouldDouble = false;
            for (let i = cardNumber.length - 1; i >= 0; i--) {
                let digit = parseInt(cardNumber.charAt(i));
                if (shouldDouble) {
                    digit *= 2;
                    if (digit > 9) digit -= 9;
                }
                sum += digit;
                shouldDouble = !shouldDouble;
            }
            return (sum % 10) === 0;
        }
        
        function validateExpiryDate(expiry) {
            if (!expiry || !expiry.includes('/')) return false;
            const [month, year] = expiry.split('/');
            const expiryDate = new Date(2000 + parseInt(year), parseInt(month) - 1);
            const currentDate = new Date();
            return expiryDate > currentDate;
        }
        
        // Print receipt
        function printReceipt() {
            const printContent = document.getElementById('printableReceipt').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = printContent;
            window.print();
            document.body.innerHTML = originalContent;
            location.reload(); // Reload to restore functionality
        }
        
        // Download receipt as PDF (simulated)
        function downloadReceipt() {
            alert('Receipt download feature would be implemented with a PDF generation library like TCPDF or mPDF.');
            // In production, this would make an AJAX call to generate and download PDF
        }
        
        // Initialize form with saved data
        document.addEventListener('DOMContentLoaded', function() {
            // Load saved form data from localStorage
            const savedData = JSON.parse(localStorage.getItem('checkoutFormData') || '{}');
            Object.keys(savedData).forEach(key => {
                const element = document.querySelector(`[name="${key}"]`);
                if (element) {
                    if (element.type === 'radio') {
                        if (element.value === savedData[key]) {
                            element.checked = true;
                            element.dispatchEvent(new Event('change'));
                        }
                    } else {
                        element.value = savedData[key];
                    }
                }
            });
            
            // Save form data on change
            const form = document.getElementById('checkoutForm');
            if (form) {
                form.addEventListener('input', function(e) {
                    if (e.target.name) {
                        const data = JSON.parse(localStorage.getItem('checkoutFormData') || '{}');
                        if (e.target.type === 'radio') {
                            if (e.target.checked) {
                                data[e.target.name] = e.target.value;
                            }
                        } else {
                            data[e.target.name] = e.target.value;
                        }
                        localStorage.setItem('checkoutFormData', JSON.stringify(data));
                    }
                });
            }
            
            // Trigger change event for initial payment method
            const initialPayment = document.querySelector('input[name="payment_method"]:checked');
            if (initialPayment) {
                initialPayment.dispatchEvent(new Event('change'));
            }
        });
    </script>
    
    <style>
        .checkout-container {
            padding: 2rem 0;
            min-height: 70vh;
        }
        
        .page-title {
            margin-bottom: 2rem;
            color: var(--primary-color);
            text-align: center;
        }
        
        .checkout-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        .checkout-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding: 1rem;
            background-color: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .progress-step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            position: relative;
        }
        
        .progress-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 100%;
            height: 2px;
            background-color: var(--border-color);
            z-index: 1;
        }
        
        .progress-step.active:not(:last-child)::after {
            background-color: var(--primary-color);
        }
        
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--light-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 2;
        }
        
        .progress-step.active .step-number {
            background-color: var(--primary-color);
            color: white;
        }
        
        .step-text {
            font-size: 0.9rem;
            color: var(--text-light);
        }
        
        .progress-step.active .step-text {
            color: var(--primary-color);
            font-weight: 500;
        }
        
        .checkout-form {
            background-color: white;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .checkout-form h2 {
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light-color);
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .payment-form .form-group {
            margin-bottom: 1.5rem;
        }
        
        .payment-methods {
            margin: 1.5rem 0;
        }
        
        .payment-option {
            margin-bottom: 0.8rem;
        }
        
        .payment-option input[type="radio"] {
            display: none;
        }
        
        .payment-option label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s;
            background-color: white;
        }
        
        .payment-option label:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .payment-option input[type="radio"]:checked + label {
            border-color: var(--primary-color);
            background-color: rgba(255, 107, 53, 0.1);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.2);
        }
        
        .payment-option i {
            font-size: 1.5rem;
            margin-right: 1rem;
            color: var(--primary-color);
        }
        
        .payment-icon {
            font-size: 1.2rem;
        }
        
        .payment-option strong {
            display: block;
            margin-bottom: 0.2rem;
            flex: 1;
        }
        
        .payment-option small {
            color: var(--text-light);
            font-size: 0.9rem;
            display: block;
        }
        
        .payment-details {
            margin: 2rem 0;
            padding: 1.5rem;
            background-color: var(--light-color);
            border-radius: var(--radius);
            border-left: 4px solid var(--primary-color);
        }
        
        .payment-details h3 {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .card-icons {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 1.5rem;
            color: var(--text-light);
        }
        
        .cvv-input {
            position: relative;
        }
        
        .cvv-input .toggle-cvv {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
        }
        
        .bank-info {
            padding: 1rem;
            background-color: white;
            border-radius: var(--radius);
        }
        
        .bank-info p {
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed var(--border-color);
        }
        
        .paypal-notice, .cod-info, .security-notice {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background-color: white;
            border-radius: var(--radius);
            margin-top: 1rem;
        }
        
        .security-notice {
            background-color: #E3F2FD;
            border-left: 4px solid #2196F3;
        }
        
        .paypal-notice {
            background-color: #FFF3E0;
            border-left: 4px solid #FF9800;
        }
        
        .cod-info {
            background-color: #F3E5F5;
            border-left: 4px solid #9C27B0;
        }
        
        .checkout-actions {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--light-color);
        }
        
        .btn-checkout {
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            min-width: 250px;
        }
        
        .order-summary {
            background-color: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            position: sticky;
            top: 100px;
            align-self: start;
        }
        
        .order-items {
            margin-bottom: 1.5rem;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .order-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem;
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
        }
        
        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .item-info {
            flex: 1;
        }
        
        .item-info h4 {
            margin-bottom: 0.3rem;
            font-size: 0.95rem;
        }
        
        .item-meta {
            display: flex;
            justify-content: space-between;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .item-total {
            font-weight: 600;
            color: var(--primary-color);
            min-width: 80px;
            text-align: right;
        }
        
        .order-totals {
            margin: 1.5rem 0;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.8rem;
            color: var(--text-light);
        }
        
        .total-row.grand-total {
            font-weight: 600;
            font-size: 1.2rem;
            color: var(--text-color);
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--border-color);
        }
        
        .total-amount {
            color: var(--primary-color);
        }
        
        .delivery-estimate, .payment-accepted, .money-back-guarantee {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background-color: var(--light-color);
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }
        
        .delivery-estimate i {
            font-size: 1.5rem;
            color: var(--primary-color);
        }
        
        .payment-icons {
            display: flex;
            gap: 0.8rem;
            margin-top: 0.5rem;
            font-size: 1.8rem;
            color: var(--text-light);
        }
        
        .money-back-guarantee {
            background-color: #E8F5E9;
            border-left: 4px solid #4CAF50;
        }
        
        /* Success Page Styles */
        .checkout-success {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .success-header {
            text-align: center;
            padding: 2rem 0;
            border-bottom: 2px solid var(--light-color);
        }
        
        .success-header i {
            font-size: 5rem;
            color: #4CAF50;
            margin-bottom: 1rem;
        }
        
        .success-header h2 {
            margin-bottom: 0.5rem;
            color: #4CAF50;
        }
        
        .order-summary .summary-card {
            background-color: #F9F9F9;
            padding: 1.5rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary-color);
            margin: 1.5rem 0;
        }
        
        .summary-card .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-card .summary-row:last-child {
            border-bottom: none;
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
        
        .status-success {
            background-color: #D4EDDA;
            color: #155724;
        }
        
        .payment-method {
            color: var(--primary-color);
            font-weight: 500;
        }
        
        .order-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin: 2rem 0;
            flex-wrap: wrap;
        }
        
        .delivery-info, .whatsapp-notification {
            background-color: var(--light-color);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-top: 1.5rem;
        }
        
        .delivery-info h3, .whatsapp-notification h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .whatsapp-notification {
            background-color: #E8F5E9;
            border-left: 4px solid #25D366;
        }
        
        /* Printable Receipt Styles */
        .printable-receipt {
            display: none;
        }
        
        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-error {
            background-color: #F8D7DA;
            color: #721C24;
            border-left: 4px solid #DC3545;
        }
        
        .alert-warning {
            background-color: #FFF3CD;
            color: #856404;
            border-left: 4px solid #FFC107;
        }
        
        .alert-info {
            background-color: #D1ECF1;
            color: #0C5460;
            border-left: 4px solid #17A2B8;
        }
        
        .alert-success {
            background-color: #D4EDDA;
            color: #155724;
            border-left: 4px solid #28A745;
        }
        
        @media (max-width: 992px) {
            .checkout-layout {
                grid-template-columns: 1fr;
            }
            
            .order-summary {
                position: static;
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .checkout-form, .order-summary {
                padding: 1.5rem;
            }
            
            .order-actions, .checkout-actions {
                flex-direction: column;
            }
            
            .order-actions .btn, .checkout-actions .btn {
                width: 100%;
            }
            
            .btn-checkout {
                min-width: auto;
            }
        }
        
        @media (max-width: 576px) {
            .payment-option label {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
            
            .payment-option i {
                margin-right: 0;
            }
            
            .progress-step .step-text {
                display: none;
            }
        }
    </style>
</body>
</html>