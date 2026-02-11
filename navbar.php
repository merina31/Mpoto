<?php
// Navbar Include File
// Include this at the beginning of your body section: <?php include 'includes/navbar.php'; 
if (!isset($_SESSION)) {
    session_start();
}
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/config.php';
}
?>

<!-- Floating WhatsApp Button -->
<a href="https://wa.me/<?php echo isset($whatsappNumber) ? $whatsappNumber : '1234567890'; ?>" class="whatsapp-float" target="_blank" title="Contact us on WhatsApp">
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
            <li><a href="index.php"><i class="fas fa-home"></i> <span>Home</span></a></li>
            <li><a href="menu.php"><i class="fas fa-burger"></i> <span>Menu</span></a></li>
            <li><a href="orders.php"><i class="fas fa-receipt"></i> <span>My Orders</span></a></li>
            
            <?php if(isset($_SESSION['user_id'])): ?>
                <li class="nav-cart">
                    <a href="cart.php" title="Shopping Cart">
                        <i class="fas fa-shopping-cart"></i>
                        <span class="cart-count" id="cartCount">0</span>
                    </a>
                </li>
                
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle" title="Account Menu">
                        <i class="fas fa-user-circle"></i>
                        <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Account'); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
                        <li><a href="orders.php"><i class="fas fa-box"></i> Order History</a></li>
                        <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <li><a href="admin/index.php"><i class="fas fa-cogs"></i> Admin Panel</a></li>
                        <?php endif; ?>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </li>
            <?php else: ?>
                <li><a href="login.php" class="btn-nav"><i class="fas fa-sign-in-alt"></i> <span>Login</span></a></li>
                <li><a href="register.php" class="btn-nav btn-nav-primary"><i class="fas fa-user-plus"></i> <span>Register</span></a></li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<script>
// Mobile Menu Toggle
document.addEventListener('DOMContentLoaded', function() {
    const navToggle = document.getElementById('navToggle');
    const navMenu = document.getElementById('navMenu');
    
    if (navToggle) {
        navToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
        });
    }
    
    // Close menu when clicking on a link
    const navLinks = navMenu?.querySelectorAll('a');
    navLinks?.forEach(link => {
        link.addEventListener('click', function() {
            navMenu.classList.remove('active');
        });
    });
});
</script>
