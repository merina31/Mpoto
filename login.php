<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/auth_functions.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        $result = $auth->login($username, $password);
        
        if ($result['success']) {
            // Set remember me cookie if checked
            if ($remember) {
                setcookie('remember_user', $username, time() + (30 * 24 * 60 * 60), '/');
            }
            
            // Redirect based on role
            if ($result['role'] === 'admin') {
                header('Location: admin/index.php');
            } else {
                header('Location: index.php');
            }
            exit();
        } else {
            $error = $result['errors']['general'] ?? 'Login failed';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Login Form -->
    <div class="form-container">
        <h2 class="text-center">
            <i class="fas fa-sign-in-alt"></i> Login to Your Account
        </h2>
      
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" class="auth-form">
            <div class="form-group">
                <label for="username">
                    <i class="fas fa-user"></i> Username or Email
                </label>
                <input type="text" 
                       id="username" 
                       name="username" 
                       class="form-control" 
                       value="<?php echo htmlspecialchars($_COOKIE['remember_user'] ?? ''); ?>"
                       required 
                       placeholder="Enter your username or email">
            </div>
            
            <div class="form-group">
                <label for="password">
                    <i class="fas fa-lock"></i> Password
                </label>
                <div class="password-input">
                    <input type="password" 
                           id="password" 
                           name="password" 
                           class="form-control" 
                           required 
                           placeholder="Enter your password">
                    <button type="button" class="toggle-password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group remember-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember" <?php echo isset($_COOKIE['remember_user']) ? 'checked' : ''; ?>>
                    <span>Remember me</span>
                </label>
                <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
            
            <div class="form-footer">
                <p>Don't have an account? <a href="register.php">Register here</a></p>
                <p><a href="index.php"><i class="fas fa-home"></i> Back to Home</a></p>
            </div>
        </form>
        
        <div class="social-login">
            <p class="divider">Or login with</p>
            <div class="social-buttons">
                <button type="button" class="btn-social btn-facebook">
                    <i class="fab fa-facebook"></i> Facebook
                </button>
                <button type="button" class="btn-social btn-google">
                    <i class="fab fa-google"></i> Google
                </button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <!-- WhatsApp Float -->
    <a href="https://wa.me/<?php echo WHATSAPP_NUMBER; ?>" class="whatsapp-float" target="_blank">
        <i class="fab fa-whatsapp"></i>
    </a>

    <script>
        // Toggle password visibility
        document.querySelector('.toggle-password').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Social login buttons
        document.querySelector('.btn-facebook').addEventListener('click', function() {
            alert('Facebook login would be implemented here');
        });

        document.querySelector('.btn-google').addEventListener('click', function() {
            alert('Google login would be implemented here');
        });

        // Enter key submits form
        document.getElementById('username').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('form').submit();
            }
        });
        
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('form').submit();
            }
        });
    </script>

    <style>
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @media (max-width: 576px) {
            .login-tabs {
                gap: 0.5rem;
            }

            .tab-button {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }

            .tab-button i {
                display: none;
            }
        }
    </style>
</body>
</html>