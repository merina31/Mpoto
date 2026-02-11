<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/auth_functions.php';

// Require login for cart access
$auth->requireLogin();

$cart = [];
$cartTotal = 0;
$deliveryFee = 5.00; // Fixed delivery fee
$taxRate = 0.08; // 8% tax

// Load cart items from database
$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

$sql = "SELECT c.*, m.name, m.price, m.discount_price, m.image_url 
        FROM cart c 
        JOIN meals m ON c.meal_id = m.id 
        WHERE c.user_id = ? AND m.is_available = 1";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $row['final_price'] = $row['discount_price'] ?: $row['price'];
    $row['subtotal'] = $row['final_price'] * $row['quantity'];
    $cart[] = $row;
    $cartTotal += $row['subtotal'];
}

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $meal_id = $_POST['meal_id'] ?? 0;
    $quantity = $_POST['quantity'] ?? 1;
    
    switch ($action) {
        case 'update':
            if ($quantity > 0) {
                $sql = "UPDATE cart SET quantity = ? WHERE user_id = ? AND meal_id = ?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param("iii", $quantity, $user_id, $meal_id);
                $stmt->execute();
            }
            break;
            
        case 'remove':
            $sql = "DELETE FROM cart WHERE user_id = ? AND meal_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ii", $user_id, $meal_id);
            $stmt->execute();
            break;
            
        case 'clear':
            $sql = "DELETE FROM cart WHERE user_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            break;
            
        case 'checkout':
            header('Location: checkout.php');
            exit();
    }
    
    // Reload page to show updated cart
    header('Location: cart.php');
    exit();
}

// Calculate totals
$taxAmount = $cartTotal * $taxRate;
$grandTotal = $cartTotal + $deliveryFee + $taxAmount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Cart Page -->
    <div class="cart-container container">
        <h1 class="page-title">Your Shopping Cart</h1>
        
        <?php if (empty($cart)): ?>
            <div class="empty-cart">
                <i class="fas fa-shopping-cart"></i>
                <h2>Your cart is empty</h2>
                <p>Add some delicious meals to your cart!</p>
                <a href="menu.php" class="btn btn-primary">
                    <i class="fas fa-utensils"></i> Browse Menu
                </a>
            </div>
        <?php else: ?>
            <div class="cart-layout">
                <div class="cart-items">
                    <div class="cart-header">
                        <h3>Items (<?php echo count($cart); ?>)</h3>
                        <form method="POST" onsubmit="return confirm('Clear all items from cart?');">
                            <input type="hidden" name="action" value="clear">
                            <button type="submit" class="btn-clear-cart">
                                <i class="fas fa-trash"></i> Clear Cart
                            </button>
                        </form>
                    </div>
                    
                    <?php foreach ($cart as $item): ?>
                        <div class="cart-item" id="cart-item-<?php echo $item['meal_id']; ?>">
                            <div class="cart-item-image">
                                <img src="<?php echo htmlspecialchars($item['image_url'] ?: 'assets/images/default-food.jpg'); ?>" 
                                     alt="<?php echo htmlspecialchars($item['name']); ?>">
                            </div>
                            
                            <div class="cart-item-details">
                                <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                <div class="cart-item-price">
                                    <span class="price"><?php echo format_currency($item['final_price']); ?></span>
                                    <?php if ($item['discount_price']): ?>
                                        <span class="original-price"><?php echo format_currency($item['price']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="cart-item-quantity">
                                <form method="POST" class="quantity-form">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="meal_id" value="<?php echo $item['meal_id']; ?>">
                                    <button type="button" class="quantity-btn minus" onclick="updateQuantity(<?php echo $item['meal_id']; ?>, -1)">-</button>
                                    <input type="number" 
                                           name="quantity" 
                                           class="quantity-input" 
                                           value="<?php echo $item['quantity']; ?>" 
                                           min="1" 
                                           max="10"
                                           onchange="updateQuantityInput(<?php echo $item['meal_id']; ?>, this.value)">
                                    <button type="button" class="quantity-btn plus" onclick="updateQuantity(<?php echo $item['meal_id']; ?>, 1)">+</button>
                                </form>
                            </div>
                            
                            <div class="cart-item-subtotal">
                                <span class="subtotal"><?php echo format_currency($item['subtotal']); ?></span>
                            </div>
                            
                            <div class="cart-item-remove">
                                <form method="POST" onsubmit="return confirm('Remove this item from cart?');">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="meal_id" value="<?php echo $item['meal_id']; ?>">
                                    <button type="submit" class="btn-remove">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="cart-summary">
                    <h3>Order Summary</h3>
                    
                    <div class="summary-details">
                        <div class="summary-row">
                            <span>Subtotal</span>
                            <span><?php echo format_currency($cartTotal); ?></span>
                        </div>
                        
                        <div class="summary-row">
                            <span>Delivery Fee</span>
                            <span><?php echo format_currency($deliveryFee); ?></span>
                        </div>
                        
                        <div class="summary-row">
                            <span>Tax (<?php echo ($taxRate * 100); ?>%)</span>
                            <span><?php echo format_currency($taxAmount); ?></span>
                        </div>
                        
                        <div class="summary-row total">
                            <span>Total</span>
                            <span class="grand-total"><?php echo format_currency($grandTotal); ?></span>
                        </div>
                    </div>
                    
                    <div class="delivery-time">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>Estimated Delivery Time</strong>
                            <p>30-45 minutes</p>
                        </div>
                    </div>
                    
                    <form method="POST" action="checkout.php">
                        <button type="submit" class="btn btn-primary btn-block btn-checkout">
                            <i class="fas fa-shopping-bag"></i> Proceed to Checkout
                        </button>
                    </form>
                    
                    <div class="cart-actions">
                        <a href="menu.php" class="btn-continue">
                            <i class="fas fa-arrow-left"></i> Continue Shopping
                        </a>
                    </div>
                    
                    <div class="payment-methods">
                        <p>We accept:</p>
                        <div class="payment-icons">
                            <i class="fab fa-cc-visa" title="Visa"></i>
                            <i class="fab fa-cc-mastercard" title="Mastercard"></i>
                            <i class="fab fa-cc-amex" title="American Express"></i>
                            <i class="fab fa-cc-paypal" title="PayPal"></i>
                            <i class="fas fa-money-bill-wave" title="Cash"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recommended Items -->
            <div class="recommended-items">
                <h3>You might also like</h3>
                <div class="meals-grid" id="recommendedMeals">
                    <!-- Recommended meals will be loaded via JavaScript -->
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
        function formatCurrencyTZS(value) {
            return `TSh ${Number(value || 0).toLocaleString('en-TZ', { maximumFractionDigits: 0 })}`;
        }

        // Update quantity with buttons
        function updateQuantity(mealId, change) {
            const input = document.querySelector(`#cart-item-${mealId} .quantity-input`);
            let newValue = parseInt(input.value) + change;
            
            if (newValue < 1) newValue = 1;
            if (newValue > 10) newValue = 10;
            
            input.value = newValue;
            
            // Submit form
            const form = input.closest('.quantity-form');
            form.submit();
        }
        
        // Update quantity with input field
        function updateQuantityInput(mealId, value) {
            if (value < 1) value = 1;
            if (value > 10) value = 10;
            
            const form = document.querySelector(`#cart-item-${mealId} .quantity-form`);
            form.querySelector('input[name="quantity"]').value = value;
            form.submit();
        }
        
        // Load recommended meals
        async function loadRecommendedMeals() {
            try {
                const response = await fetch('api/meals.php?action=get_popular');
                const meals = await response.json();
                
                // Take first 4 meals
                const recommended = meals.slice(0, 4);
                const container = document.getElementById('recommendedMeals');
                
                if (container && recommended.length > 0) {
                    container.innerHTML = recommended.map(meal => `
                        <div class="meal-card">
                            <div class="meal-image">
                                <img src="${meal.image_url || 'assets/images/default-food.jpg'}" alt="${meal.name}">
                            </div>
                            <div class="meal-info">
                                <h4>${meal.name}</h4>
                                <div class="meal-footer">
                                    <div class="price">
                                        ${meal.discount_price ? 
                                            `<span class="old-price">${formatCurrencyTZS(meal.price)}</span>
                                             <span class="current-price">${formatCurrencyTZS(meal.discount_price)}</span>` :
                                            `<span class="current-price">${formatCurrencyTZS(meal.price)}</span>`
                                        }
                                    </div>
                                    <form method="POST" action="cart.php" style="display: inline;">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="meal_id" value="${meal.id}">
                                        <button type="submit" class="btn-add-cart">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    `).join('');
                }
            } catch (error) {
                console.error('Error loading recommended meals:', error);
            }
        }
        
        // Auto-update cart count in navbar
        function updateCartCount() {
            const cartCount = document.querySelector('.cart-count');
            if (cartCount) {
                cartCount.textContent = <?php echo count($cart); ?>;
            }
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateCartCount();
            loadRecommendedMeals();
            
            // Add animation to cart items
            document.querySelectorAll('.cart-item').forEach(item => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    item.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateX(0)';
                }, 100);
            });
        });
    </script>
    
    <style>
        .cart-container {
            padding: 2rem 0;
        }
        
        .page-title {
            margin-bottom: 2rem;
            color: var(--primary-color);
        }
        
        .empty-cart {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-cart i {
            font-size: 5rem;
            color: #e0e0e0;
            margin-bottom: 1rem;
        }
        
        .cart-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        .cart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .btn-clear-cart {
            background: none;
            border: none;
            color: var(--danger-color);
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .cart-item {
            display: grid;
            grid-template-columns: 100px 2fr 150px 100px 50px;
            gap: 1rem;
            align-items: center;
            padding: 1rem;
            background-color: white;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            box-shadow: var(--shadow);
        }
        
        .cart-item-image {
            width: 100px;
            height: 100px;
            border-radius: var(--radius);
            overflow: hidden;
        }
        
        .cart-item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .cart-item-details h4 {
            margin-bottom: 0.5rem;
        }
        
        .cart-item-price .price {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }
        
        .cart-item-price .original-price {
            text-decoration: line-through;
            color: var(--text-light);
            margin-left: 0.5rem;
            font-size: 0.9rem;
        }
        
        .quantity-form {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .quantity-btn {
            width: 30px;
            height: 30px;
            border: 1px solid var(--border-color);
            background-color: white;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .quantity-btn:hover {
            background-color: var(--gray-color);
        }
        
        .quantity-input {
            width: 50px;
            text-align: center;
            padding: 5px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        
        .cart-item-subtotal .subtotal {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .btn-remove {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        .btn-remove:hover {
            color: var(--danger-color);
        }
        
        .cart-summary {
            background-color: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            position: sticky;
            top: 100px;
        }
        
        .summary-details {
            margin-bottom: 1.5rem;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.8rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-row.total {
            border-top: 2px solid var(--border-color);
            border-bottom: none;
            padding-top: 0.8rem;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .grand-total {
            color: var(--primary-color);
        }
        
        .delivery-time {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background-color: var(--light-color);
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
        }
        
        .delivery-time i {
            font-size: 1.5rem;
            color: var(--primary-color);
        }
        
        .btn-checkout {
            margin-bottom: 1rem;
        }
        
        .btn-continue {
            display: block;
            text-align: center;
            color: var(--primary-color);
            text-decoration: none;
            padding: 0.5rem;
        }
        
        .btn-continue:hover {
            text-decoration: underline;
        }
        
        .payment-methods {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        
        .payment-icons {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            font-size: 1.5rem;
            color: var(--text-light);
        }
        
        .recommended-items {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid var(--border-color);
        }
        
        @media (max-width: 992px) {
            .cart-layout {
                grid-template-columns: 1fr;
            }
            
            .cart-summary {
                position: static;
            }
        }
        
        @media (max-width: 768px) {
            .cart-item {
                grid-template-columns: 80px 1fr;
                grid-template-rows: auto auto;
                gap: 0.5rem;
            }
            
            .cart-item-image {
                grid-row: span 2;
            }
            
            .cart-item-quantity,
            .cart-item-subtotal,
            .cart-item-remove {
                grid-column: 2;
            }
            
            .cart-item-quantity {
                grid-row: 2;
            }
            
            .cart-item-subtotal {
                grid-row: 2;
                justify-self: end;
            }
            
            .cart-item-remove {
                grid-row: 1;
                justify-self: end;
            }
        }
    </style>
</body>
</html>
