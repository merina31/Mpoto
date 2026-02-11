<?php
// Website Configuration
define('SITE_NAME', 'goodtaSTE');
define('SITE_URL', 'http://localhost/food-order-system');
define('ADMIN_EMAIL', 'admin@goodtaste.com');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'food_order_system');

// Session Configuration
define('SESSION_TIMEOUT', 3600); // 1 hour

// API Configuration
define('API_KEY', 'food_order_' . md5('secret_key_2024'));

// Path Configuration
define('UPLOAD_PATH', 'assets/uploads/');
define('MAX_UPLOAD_SIZE', 5242880); // 5MB

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');

// Social Media Links
define('FACEBOOK_URL', 'https://facebook.com/goodtaste');
define('INSTAGRAM_URL', 'https://instagram.com/goodtaste');
define('TIKTOK_URL', 'https://tiktok.com/@goodtaste');
define('WHATSAPP_NUMBER', '+1234567890');

// Color Scheme - Violet Theme
define('PRIMARY_COLOR', '#7C3AED');
define('SECONDARY_COLOR', '#A78BFA');
define('ACCENT_COLOR', '#6366F1');
define('DARK_COLOR', '#1F1F3D');
define('LIGHT_COLOR', '#F8F7FF');
define('SUCCESS_COLOR', '#4CAF50');
define('WARNING_COLOR', '#FFC107');
define('DANGER_COLOR', '#F44336');

// Currency Configuration
define('CURRENCY_CODE', 'TZS');
define('CURRENCY_SYMBOL', 'TSh');

if (!function_exists('format_currency')) {
    function format_currency($amount, $decimals = 0) {
        $value = is_numeric($amount) ? (float) $amount : 0;
        return CURRENCY_SYMBOL . ' ' . number_format($value, $decimals);
    }
}
