<?php
session_start();
require_once 'includes/auth_functions.php';

// Clear all session data
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Clear remember me cookie
setcookie('remember_user', '', time() - 3600, '/');

// Redirect to home page
header('Location: index.php?logged_out=1');
exit();
?>