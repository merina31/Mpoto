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

$errors = [];
$formData = [];

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? '',
        'full_name' => trim($_POST['full_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'role' => $_POST['role'] ?? 'user'
    ];
    
    // Basic validation
    if (empty($formData['username'])) {
        $errors['username'] = 'Username is required';
    } elseif (strlen($formData['username']) < 3) {
        $errors['username'] = 'Username must be at least 3 characters';
    }
    
    if (empty($formData['email'])) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    }
    
    if (empty($formData['password'])) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($formData['password']) < 6) {
        $errors['password'] = 'Password must be at least 6 characters';
    }
    
    if ($formData['password'] !== $formData['confirm_password']) {
        $errors['confirm_password'] = 'Passwords do not match';
    }
    
    if (empty($formData['full_name'])) {
        $errors['full_name'] = 'Full name is required';
    }
    
    if (empty($formData['phone'])) {
        $errors['phone'] = 'Phone number is required';
    }
    
    // Validate role
    $validRoles = ['user', 'admin'];
    if (!in_array($formData['role'], $validRoles)) {
        $formData['role'] = 'user';
    }
    
    // If no errors, attempt registration
    if (empty($errors)) {
        $result = $auth->register($formData);
        
        if ($result['success']) {
            $_SESSION['registration_success'] = true;
            header('Location: registration_success.php');
            exit();
        } else {
            $errors = array_merge($errors, $result['errors']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Registration Form -->
    <div class="form-container">
        <h2 class="text-center">Create Your Account</h2>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> Please fix the following errors
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" class="auth-form" id="registrationForm">
            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">
                        <i class="fas fa-user-circle"></i> Full Name *
                    </label>
                    <input type="text" 
                           id="full_name" 
                           name="full_name" 
                           class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                           value="<?php echo htmlspecialchars($formData['full_name'] ?? ''); ?>"
                           required 
                           placeholder="Enter your full name">
                    <?php if (isset($errors['full_name'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['full_name']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i> Username *
                    </label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           class="form-control <?php echo isset($errors['username']) ? 'is-invalid' : ''; ?>" 
                           value="<?php echo htmlspecialchars($formData['username'] ?? ''); ?>"
                           required 
                           placeholder="Choose a username">
                    <?php if (isset($errors['username'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['username']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i> Email Address *
                </label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                       value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>"
                       required 
                       placeholder="Enter your email">
                <?php if (isset($errors['email'])): ?>
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email']); ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> Password *
                    </label>
                    <div class="password-input">
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                               required 
                               placeholder="Create a password">
                        <button type="button" class="toggle-password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password']); ?></div>
                    <?php endif; ?>
                    <small class="form-text">Minimum 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">
                        <i class="fas fa-lock"></i> Confirm Password *
                    </label>
                    <div class="password-input">
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                               required 
                               placeholder="Confirm your password">
                        <button type="button" class="toggle-password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['confirm_password'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['confirm_password']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="phone">
                        <i class="fas fa-phone"></i> Phone Number *
                    </label>
                    <input type="tel" 
                           id="phone" 
                           name="phone" 
                           class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>" 
                           value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>"
                           required 
                           placeholder="Enter your phone number">
                    <?php if (isset($errors['phone'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['phone']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="role">
                        <i class="fas fa-user-tag"></i> Account Type *
                    </label>
                    <select id="role" 
                            name="role" 
                            class="form-control <?php echo isset($errors['role']) ? 'is-invalid' : ''; ?>" 
                            required>
                        <option value="user" <?php echo ($formData['role'] ?? 'user') === 'user' ? 'selected' : ''; ?>>
                            Regular User
                        </option>
                        <option value="admin" <?php echo ($formData['role'] ?? 'user') === 'admin' ? 'selected' : ''; ?>>
                            Admin
                        </option>
                    </select>
                    <?php if (isset($errors['role'])): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['role']); ?></div>
                    <?php endif; ?>
                    <small class="form-text">Select your account type</small>
                </div>
            </div>
            
            <div class="form-group">
                <label for="address">
                    <i class="fas fa-map-marker-alt"></i> Delivery Address *
                </label>
                <textarea id="address" 
                          name="address" 
                          class="form-control <?php echo isset($errors['address']) ? 'is-invalid' : ''; ?>" 
                          rows="3" 
                          required 
                          placeholder="Enter your delivery address"><?php echo htmlspecialchars($formData['address'] ?? ''); ?></textarea>
                <?php if (isset($errors['address'])): ?>
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['address']); ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="terms" required>
                    <span>I agree to the <a href="terms.php" target="_blank">Terms of Service</a> and <a href="privacy.php" target="_blank">Privacy Policy</a></span>
                </label>
                <?php if (isset($errors['terms'])): ?>
                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['terms']); ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="newsletter" checked>
                    <span>Subscribe to our newsletter for updates and offers</span>
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
            
            <div class="form-footer">
                <p>Already have an account? <a href="login.php">Login here</a></p>
                <p><a href="index.php"><i class="fas fa-home"></i> Back to Home</a></p>
            </div>
        </form>
        
        <div class="social-register">
            <p class="divider">Or register with</p>
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
        // Toggle password visibility for all password fields
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const input = this.parentElement.querySelector('input');
                const icon = this.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });

        // Password strength checker
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthText = document.createElement('small');
            strengthText.className = 'strength-text';
            
            let strength = 0;
            let message = '';
            let color = '#F44336';
            
            // Check password strength
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            switch(strength) {
                case 0:
                case 1:
                    message = 'Very Weak';
                    color = '#F44336';
                    break;
                case 2:
                    message = 'Weak';
                    color = '#FF9800';
                    break;
                case 3:
                    message = 'Medium';
                    color = '#FFC107';
                    break;
                case 4:
                    message = 'Strong';
                    color = '#4CAF50';
                    break;
                case 5:
                    message = 'Very Strong';
                    color = '#2E7D32';
                    break;
            }
            
            // Update or create strength indicator
            let indicator = this.parentElement.parentElement.querySelector('.strength-indicator');
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.className = 'strength-indicator';
                this.parentElement.parentElement.appendChild(indicator);
            }
            
            indicator.innerHTML = `
                <div class="strength-bar">
                    <div class="strength-fill" style="width: ${strength * 20}%; background-color: ${color};"></div>
                </div>
                <span style="color: ${color};">${message}</span>
            `;
        });

        // Form validation
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (!document.querySelector('input[name="terms"]').checked) {
                e.preventDefault();
                alert('You must agree to the Terms of Service and Privacy Policy');
                return false;
            }
            
            return true;
        });

        // Phone number formatting
        document.getElementById('phone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 10) value = value.substring(0, 10);
            
            if (value.length > 6) {
                value = value.substring(0, 3) + '-' + value.substring(3, 6) + '-' + value.substring(6);
            } else if (value.length > 3) {
                value = value.substring(0, 3) + '-' + value.substring(3);
            }
            
            e.target.value = value;
        });
    </script>
    
    <style>
        .strength-indicator {
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .strength-bar {
            flex: 1;
            height: 5px;
            background-color: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>
</html>