<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/auth_functions.php';

// Redirect admins to admin dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: admin/index.php');
    exit();
}

if (isset($_SESSION['user_id']) && empty($_SESSION['profile_image'])) {
    $userSql = "SELECT username, profile_image FROM users WHERE id = ? LIMIT 1";
    $userStmt = $db->prepare($userSql);
    $userStmt->bind_param("i", $_SESSION['user_id']);
    $userStmt->execute();
    $sessionUser = $userStmt->get_result()->fetch_assoc();

    if ($sessionUser) {
        $_SESSION['username'] = $sessionUser['username'] ?? ($_SESSION['username'] ?? '');
        $_SESSION['profile_image'] = $sessionUser['profile_image'] ?? '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Delicious Food Delivery</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Floating WhatsApp Button -->
    <a href="https://wa.me/<?php echo WHATSAPP_NUMBER; ?>" class="whatsapp-float" target="_blank">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="logo">
                <i class="fas fa-utensils"></i>
                <span><?php echo SITE_NAME; ?></span>
            </a>
            
            <div class="nav-toggle" id="navToggle">
                <span></span>
                <span></span>
                <span></span>
            </div>
            
            <ul class="nav-menu" id="navMenu">
                <li><a href="index.php" class="active"><i class="fas fa-home"></i> Home</a></li>
                <li><a href="menu.php"><i class="fas fa-burger"></i> Menu</a></li>
                <li><a href="orders.php"><i class="fas fa-receipt"></i> My Orders</a></li>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <li class="nav-cart">
                        <a href="cart.php">
                            <i class="fas fa-shopping-cart"></i>
                            <span class="cart-count" id="cartCount">0</span>
                        </a>
                    </li>
                    <li class="dropdown">
                        <a href="#" class="dropdown-toggle">
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($_SESSION['username']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a href="profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <li><a href="admin/index.php"><i class="fas fa-cog"></i> Admin Dashboard</a></li>
                            <?php endif; ?>
                            <li><hr></li>
                            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li><a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                    <li><a href="register.php"><i class="fas fa-user-plus"></i> Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <h1>Delicious Food Delivered to Your Doorstep</h1>
                <p>Order from our wide selection of meals prepared by expert chefs. Fast delivery guaranteed!</p>
                <div class="hero-buttons">
                    <a href="menu.php" class="btn btn-primary">Order Now</a>
                    <a href="#features" class="btn btn-secondary">Learn More</a>
                </div>
            </div>
            <div class="hero-image">
                <img src="images/Flat-lay of Turkish family eating traditional Middle Eastern breakfast.jpg" alt="Delicious Food">
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="features">
        <div class="container">
            <h2 class="section-title">Why Choose Us</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <i class="fas fa-bolt"></i>
                    <h3>Fast Delivery</h3>
                    <p>Get your food delivered in under 30 minutes</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-star"></i>
                    <h3>Quality Food</h3>
                    <p>Fresh ingredients prepared by expert chefs</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>Best Price</h3>
                    <p>Competitive prices with regular discounts</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-headset"></i>
                    <h3>24/7 Support</h3>
                    <p>Round-the-clock customer support</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Popular Meals Section -->
    <section class="popular-meals">
        <div class="container">
            <h2 class="section-title">Popular Meals</h2>
            <div class="meals-grid" id="popularMeals">
                <!-- Meals will be loaded via JavaScript -->
            </div>
            <div class="text-center">
                <a href="menu.php" class="btn btn-primary">View Full Menu</a>
            </div>
        </div>
    </section>

<?php include 'includes/footer.php'; ?>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/cart.js"></script>
    <script>
        function formatCurrencyTZS(value) {
            return `TSh ${Number(value || 0).toLocaleString('en-TZ', { maximumFractionDigits: 0 })}`;
        }

        // Check if user is logged in (for the cart system)
        const isUserLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        
        // Load popular meals
        document.addEventListener('DOMContentLoaded', function() {
            loadPopularMeals();
            updateCartCount();
        });

        async function loadPopularMeals() {
            try {
                const response = await fetch('api/meals.php?action=get_popular');
                const meals = await response.json();
                
                const container = document.getElementById('popularMeals');
                container.innerHTML = meals.map(meal => `
                    <div class="meal-card">
                        <div class="meal-image">
                            <img src="assets/images/Homemade Fresh Orange Juice Recipe.jpg" alt="${meal.name}">
                            ${meal.discount_price ? `<span class="discount-badge">-${Math.round((1 - meal.discount_price/meal.price) * 100)}%</span>` : ''}
                        </div>
                        <div class="meal-info">
                            <h3>${meal.name}</h3>
                            <p>${meal.description.substring(0, 60)}...</p>
                            <div class="meal-footer">
                                <div class="price">
                                    ${meal.discount_price ? 
                                        `<span class="old-price">${formatCurrencyTZS(meal.price)}</span>
                                         <span class="current-price">${formatCurrencyTZS(meal.discount_price)}</span>` :
                                        `<span class="current-price">${formatCurrencyTZS(meal.price)}</span>`
                                    }
                                </div>
                                <button class="btn-add-cart" data-action="add-to-cart" data-meal-id="${meal.id}" data-quantity="1">
                                    <i class="fas fa-shopping-cart"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `).join('');
                
                // Re-initialize cart buttons for dynamically loaded content
                initializeCartButtons();
            } catch (error) {
                console.error('Error loading meals:', error);
            }
        }
    </script>
</body>
</html>
