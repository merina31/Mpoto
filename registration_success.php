<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/auth_functions.php';

// Only show this page immediately after a successful registration
if (empty($_SESSION['registration_success'])) {
    header('Location: index.php');
    exit();
}

// Fetch user info from session (set by Auth::register)
$full_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : '';
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$email = isset($_SESSION['email']) ? $_SESSION['email'] : '';

// Clear the one-time registration flag so refreshing doesn't repeat
unset($_SESSION['registration_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registration Successful - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container" style="padding:3rem 0; text-align:center;">
        <div style="max-width:700px;margin:0 auto;background:#fff;padding:2rem;border-radius:12px;box-shadow:var(--shadow);">
            <i class="fas fa-check-circle" style="font-size:4rem;color:var(--primary-color);"></i>
            <h1 style="margin-top:1rem;">Registration Successful</h1>
            <p style="color:var(--text-light);">Welcome <?php echo htmlspecialchars($full_name ?: $username); ?> — your account has been created.</p>

            <div style="margin-top:1rem;text-align:left;">
                <?php if ($full_name): ?>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($full_name); ?></p>
                <?php endif; ?>
                <?php if ($username): ?>
                    <p><strong>Username:</strong> <?php echo htmlspecialchars($username); ?></p>
                <?php endif; ?>
                <?php if ($email): ?>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($email); ?></p>
                <?php endif; ?>
            </div>

            <div style="margin-top:1.5rem;display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;">
                <a href="menu.php" class="btn btn-primary"><i class="fas fa-burger"></i> Browse Menu</a>
                <a href="profile.php" class="btn btn-secondary"><i class="fas fa-user"></i> View Profile</a>
                <a href="index.php" class="btn"><i class="fas fa-home"></i> Home</a>
            </div>

            <p style="margin-top:1rem;color:var(--text-light);font-size:0.9rem;">You will remain logged in. To logout, use the logout link in the menu.</p>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script>
        // Optional: auto-redirect to menu after 8 seconds
        setTimeout(function(){
            window.location.href = 'menu.php';
        }, 8000);
    </script>
</body>
</html>
