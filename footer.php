<?php
// Footer Include File
// Include this at the end of your body section, before closing body tag: <?php include 'includes/footer.php'; 
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/config.php';
}

$currentYear = date('Y');
?>

<!-- Footer -->
<footer class="footer">
    <div class="footer-content">
        <div class="container">
            <div class="footer-grid">
                <!-- About Section -->
                <div class="footer-section">
                    <h3><i class="fas fa-utensils"></i> <?php echo SITE_NAME; ?></h3>
                    <p>Delivering delicious food to your doorstep with quality and care. Your favorite meals, now closer than ever.</p>
                    <div class="footer-socials">
                        <a href="#" title="Facebook" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" title="Twitter" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" title="Instagram" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" title="LinkedIn" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="index.php">Home</a></li>
                        <li><a href="menu.php">Menu</a></li>
                        <li><a href="orders.php">My Orders</a></li>
                        <li><a href="cart.php">Cart</a></li>
                    </ul>
                </div>

                <!-- Help & Support -->
                <div class="footer-section">
                    <h4>Help & Support</h4>
                    <ul>
                        <li><a href="#">FAQ</a></li>
                        <li><a href="#">Contact Us</a></li>
                        <li><a href="#">Track Order</a></li>
                        <li><a href="#">Delivery Info</a></li>
                    </ul>
                </div>

                <!-- Contact Info -->
                <div class="footer-section">
                    <h4>Contact Us</h4>
                    <div class="contact-info">
                        <p>
                            <i class="fas fa-map-marker-alt"></i>
                            <span>123 Food Street, Restaurant City, FC 12345</span>
                        </p>
                        <p>
                            <i class="fas fa-phone"></i>
                            <span><a href="tel:+225758851705">+225 75 88 51 705</a></span>
                        </p>
                        <p>
                            <i class="fas fa-envelope"></i>
                            <span><a href="mailto:info@foodorder.com">info@foodorder.com</a></span>
                        </p>
                        <p>
                            <i class="fab fa-whatsapp"></i>
                            <span><a href="https://wa.me/1234567890" target="_blank">WhatsApp Us</a></span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="footer-bottom">
                <div class="footer-bottom-content">
                    <p>&copy; <?php echo $currentYear; ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
                    <div class="footer-links">
                        <a href="#">Privacy Policy</a>
                        <span class="divider">|</span>
                        <a href="#">Terms & Conditions</a>
                        <span class="divider">|</span>
                        <a href="#">Refund Policy</a>
                    </div>
                </div>

                <!-- Payment Methods -->
                <div class="payment-methods">
                    <span>We Accept:</span>
                    <i class="fab fa-cc-visa"></i>
                    <i class="fab fa-cc-mastercard"></i>
                    <i class="fab fa-cc-paypal"></i>
                </div>
            </div>
        </div>
    </div>
</footer>
