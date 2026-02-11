<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/auth_functions.php';

// Require login
$auth->requireLogin();

$user_id = $_SESSION['user_id'];
$db = Database::getInstance();

// Get user data
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// If user not found (possible stale session), end session and redirect to login
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: login.php?error=user_not_found');
    exit();
}
$_SESSION['profile_image'] = $user['profile_image'] ?? '';

// Initialize variables
$errors = [];
$success = '';
$formData = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_profile':
            $formData = [
                'full_name' => trim($_POST['full_name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'phone' => trim($_POST['phone'] ?? ''),
                'address' => trim($_POST['address'] ?? ''),
                'social_facebook' => trim($_POST['social_facebook'] ?? ''),
                'social_instagram' => trim($_POST['social_instagram'] ?? ''),
                'social_tiktok' => trim($_POST['social_tiktok'] ?? '')
            ];
            
            // Validate
            if (empty($formData['full_name'])) {
                $errors['full_name'] = 'Full name is required';
            }
            
            if (empty($formData['email'])) {
                $errors['email'] = 'Email is required';
            } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            }
            
            if (empty($errors)) {
                // Check if email is already used by another user
                $checkSql = "SELECT id FROM users WHERE email = ? AND id != ?";
                $checkStmt = $db->prepare($checkSql);
                $checkStmt->bind_param("si", $formData['email'], $user_id);
                $checkStmt->execute();
                
                if ($checkStmt->get_result()->num_rows > 0) {
                    $errors['email'] = 'Email already in use by another account';
                } else {
                    // Update user
                    $updateSql = "UPDATE users SET 
                                  full_name = ?, email = ?, phone = ?, address = ?,
                                  social_facebook = ?, social_instagram = ?, social_tiktok = ?,
                                  updated_at = NOW() 
                                  WHERE id = ?";
                    
                    $updateStmt = $db->prepare($updateSql);
                    $updateStmt->bind_param("sssssssi", 
                        $formData['full_name'],
                        $formData['email'],
                        $formData['phone'],
                        $formData['address'],
                        $formData['social_facebook'],
                        $formData['social_instagram'],
                        $formData['social_tiktok'],
                        $user_id
                    );
                    
                    if ($updateStmt->execute()) {
                        // Update session
                        $_SESSION['full_name'] = $formData['full_name'];
                        $_SESSION['email'] = $formData['email'];
                        
                        $success = 'Profile updated successfully';
                        $user = array_merge($user, $formData);
                    } else {
                        $errors['general'] = 'Failed to update profile';
                    }
                }
            }
            break;
            
        case 'change_password':
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validate
            if (empty($current_password)) {
                $errors['current_password'] = 'Current password is required';
            }
            
            if (empty($new_password)) {
                $errors['new_password'] = 'New password is required';
            } elseif (strlen($new_password) < 6) {
                $errors['new_password'] = 'New password must be at least 6 characters';
            }
            
            if ($new_password !== $confirm_password) {
                $errors['confirm_password'] = 'Passwords do not match';
            }
            
            if (empty($errors)) {
                // Verify current password
                $checkSql = "SELECT password FROM users WHERE id = ?";
                $checkStmt = $db->prepare($checkSql);
                $checkStmt->bind_param("i", $user_id);
                $checkStmt->execute();
                $result = $checkStmt->get_result()->fetch_assoc();
                
                if (password_verify($current_password, $result['password'])) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                    $updateSql = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
                    $updateStmt = $db->prepare($updateSql);
                    $updateStmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($updateStmt->execute()) {
                        $success = 'Password changed successfully';
                    } else {
                        $errors['general'] = 'Failed to change password';
                    }
                } else {
                    $errors['current_password'] = 'Current password is incorrect';
                }
            }
            break;
            
        case 'upload_avatar':
            // Handle image upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $max_size = 50 * 1024 * 1024; // 2MB
                
                $file_type = $_FILES['avatar']['type'];
                $file_size = $_FILES['avatar']['size'];
                
                if (!in_array($file_type, $allowed_types)) {
                    $errors['avatar'] = 'Only JPG, PNG and GIF images are allowed';
                } elseif ($file_size > $max_size) {
                    $errors['avatar'] = 'Image size must be less than 2MB';
                } else {
                    // Generate unique filename
                    $extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                    $filename = 'avatar_' . $user_id . '_' . time() . '.' . $extension;
                    $upload_path = 'assets/uploads/avatars/' . $filename;
                    
                    // Create directory if it doesn't exist
                    if (!file_exists('assets/uploads/avatars/')) {
                        mkdir('assets/uploads/avatars/', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_path)) {
                        // Update database
                        $updateSql = "UPDATE users SET profile_image = ?, updated_at = NOW() WHERE id = ?";
                        $updateStmt = $db->prepare($updateSql);
                        $updateStmt->bind_param("si", $upload_path, $user_id);
                        
                        if ($updateStmt->execute()) {
                            $success = 'Profile picture updated successfully';
                            $user['profile_image'] = $upload_path;
                            $_SESSION['profile_image'] = $upload_path;
                        } else {
                            $errors['general'] = 'Failed to update profile picture';
                        }
                    } else {
                        $errors['avatar'] = 'Failed to upload image';
                    }
                }
            }
            break;
            
        case 'delete_account':
            if ($_POST['confirm_delete'] === 'DELETE') {
                // Delete user account
                $deleteSql = "DELETE FROM users WHERE id = ?";
                $deleteStmt = $db->prepare($deleteSql);
                $deleteStmt->bind_param("i", $user_id);
                
                if ($deleteStmt->execute()) {
                    session_destroy();
                    header('Location: index.php?account_deleted=1');
                    exit();
                } else {
                    $errors['general'] = 'Failed to delete account';
                }
            } else {
                $errors['confirm_delete'] = 'Please type DELETE to confirm';
            }
            break;
    }
}

// Get user's order stats
$statsSql = "SELECT 
             COUNT(*) as total_orders,
             SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
             SUM(final_amount) as total_spent
             FROM orders 
             WHERE user_id = ?";
$statsStmt = $db->prepare($statsSql);
$statsStmt->bind_param("i", $user_id);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Profile Page -->
    <div class="profile-container container">
        <div class="profile-header">
            <h1 class="page-title">My Profile</h1>
            <p class="welcome-message">
                Welcome back, <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>!
            </p>
        </div>

        <div class="profile-layout">
            <!-- Left Sidebar -->
            <div class="profile-sidebar">
                <div class="user-card">
                    <div class="avatar-section">
                        <div class="avatar-preview" id="avatarPreview">
                            <?php if (!empty($user['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($user['full_name']); ?>">
                            <?php else: ?>
                                <div class="avatar-default">
                                    <i class="fas fa-user-circle"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" class="avatar-form">
                            <input type="hidden" name="action" value="upload_avatar">
                            <input type="file" 
                                   id="avatarInput" 
                                   name="avatar" 
                                   accept="image/*" 
                                   style="display: none;">
                            <label for="avatarInput" class="btn-change-avatar">
                                <i class="fas fa-camera"></i> Change Photo
                            </label>
                            <button type="submit" class="btn-save-avatar" style="display: none;">
                                Save
                            </button>
                        </form>
                        
                        <?php if (isset($errors['avatar'])): ?>
                            <div class="alert alert-error">
                                <?php echo htmlspecialchars($errors['avatar']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="user-info">
                        <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                        <p class="user-email">
                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?>
                        </p>
                        <p class="user-phone">
                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone'] ?: 'Not set'); ?>
                        </p>
                        <p class="user-role">
                            <i class="fas fa-user-tag"></i> 
                            <?php echo ucfirst($user['role']); ?> Account
                        </p>
                    </div>
                </div>
                
                <nav class="profile-nav">
                    <a href="#profile-info" class="nav-link active" onclick="showSection('profile-info')">
                        <i class="fas fa-user-circle"></i> Profile Info
                    </a>
                    <a href="#order-history" class="nav-link" onclick="showSection('order-history')">
                        <i class="fas fa-history"></i> Order History
                    </a>
                    <a href="#security" class="nav-link" onclick="showSection('security')">
                        <i class="fas fa-shield-alt"></i> Security
                    </a>
                    <a href="#preferences" class="nav-link" onclick="showSection('preferences')">
                        <i class="fas fa-cog"></i> Preferences
                    </a>
                    <a href="#social" class="nav-link" onclick="showSection('social')">
                        <i class="fas fa-share-alt"></i> Social Links
                    </a>
                </nav>
                
                <div class="user-stats">
                    <h3>Your Stats</h3>
                    <div class="stats-list">
                        <div class="stat-item">
                            <i class="fas fa-shopping-bag"></i>
                            <div>
                                <strong><?php echo $stats['total_orders'] ?? 0; ?></strong>
                                <span>Total Orders</span>
                            </div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong><?php echo $stats['delivered_orders'] ?? 0; ?></strong>
                                <span>Delivered</span>
                            </div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-money-bill-wave"></i>
                            <div>
                                <strong><?php echo format_currency($stats['total_spent'] ?? 0); ?></strong>
                                <span>Total Spent</span>
                            </div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-calendar-alt"></i>
                            <div>
                                <strong><?php echo date('M Y', strtotime($user['created_at'])); ?></strong>
                                <span>Member Since</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="profile-content">
                <!-- Success Message -->
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Error Message -->
                <?php if (isset($errors['general'])): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['general']); ?>
                    </div>
                <?php endif; ?>

                <!-- Profile Info Section -->
                <section id="profile-info" class="profile-section active">
                    <div class="section-header">
                        <h2><i class="fas fa-user-circle"></i> Profile Information</h2>
                        <p>Update your personal information</p>
                    </div>
                    
                    <form method="POST" class="profile-form">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="full_name">
                                    <i class="fas fa-user"></i> Full Name *
                                </label>
                                <input type="text" 
                                       id="full_name" 
                                       name="full_name" 
                                       class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>"
                                       required>
                                <?php if (isset($errors['full_name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['full_name']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label for="username">
                                    <i class="fas fa-at"></i> Username
                                </label>
                                <input type="text" 
                                       id="username" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($user['username']); ?>"
                                       disabled>
                                <small class="form-text">Username cannot be changed</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">
                                    <i class="fas fa-envelope"></i> Email Address *
                                </label>
                                <input type="email" 
                                       id="email" 
                                       name="email" 
                                       class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>"
                                       required>
                                <?php if (isset($errors['email'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">
                                    <i class="fas fa-phone"></i> Phone Number
                                </label>
                                <input type="tel" 
                                       id="phone" 
                                       name="phone" 
                                       class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                <?php if (isset($errors['phone'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">
                                <i class="fas fa-map-marker-alt"></i> Delivery Address
                            </label>
                            <textarea id="address" 
                                      name="address" 
                                      class="form-control <?php echo isset($errors['address']) ? 'is-invalid' : ''; ?>" 
                                      rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                            <?php if (isset($errors['address'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['address']); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>
                </section>

                <!-- Order History Section -->
                <section id="order-history" class="profile-section">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> Order History</h2>
                        <p>View your past orders and track current ones</p>
                    </div>
                    
                    <div class="recent-orders">
                        <h3>Recent Orders</h3>
                        <?php
                        // Get recent orders
                        $recentSql = "SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
                        $recentStmt = $db->prepare($recentSql);
                        $recentStmt->bind_param("i", $user_id);
                        $recentStmt->execute();
                        $recentOrders = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        ?>
                        
                        <?php if (empty($recentOrders)): ?>
                            <div class="no-orders">
                                <p>You haven't placed any orders yet.</p>
                                <a href="menu.php" class="btn btn-primary">
                                    <i class="fas fa-utensils"></i> Start Ordering
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="orders-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Total</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentOrders as $order): ?>
                                            <tr>
                                                <td><?php echo $order['order_number']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $order['status']; ?>">
                                                        <?php echo ucfirst($order['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo format_currency($order['final_amount']); ?></td>
                                                <td>
                                                    <a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn-action">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="view-all-orders">
                                <a href="orders.php" class="btn btn-secondary">
                                    <i class="fas fa-list"></i> View All Orders
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Security Section -->
                <section id="security" class="profile-section">
                    <div class="section-header">
                        <h2><i class="fas fa-shield-alt"></i> Security</h2>
                        <p>Change your password and manage security settings</p>
                    </div>
                    
                    <form method="POST" class="security-form">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password">
                                <i class="fas fa-lock"></i> Current Password *
                            </label>
                            <div class="password-input">
                                <input type="password" 
                                       id="current_password" 
                                       name="current_password" 
                                       class="form-control <?php echo isset($errors['current_password']) ? 'is-invalid' : ''; ?>" 
                                       required>
                                <button type="button" class="toggle-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['current_password'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['current_password']); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">
                                <i class="fas fa-key"></i> New Password *
                            </label>
                            <div class="password-input">
                                <input type="password" 
                                       id="new_password" 
                                       name="new_password" 
                                       class="form-control <?php echo isset($errors['new_password']) ? 'is-invalid' : ''; ?>" 
                                       required>
                                <button type="button" class="toggle-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['new_password'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['new_password']); ?></div>
                            <?php endif; ?>
                            <small class="form-text">Minimum 6 characters</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">
                                <i class="fas fa-key"></i> Confirm New Password *
                            </label>
                            <div class="password-input">
                                <input type="password" 
                                       id="confirm_password" 
                                       name="confirm_password" 
                                       class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                                       required>
                                <button type="button" class="toggle-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['confirm_password'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['confirm_password']); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Change Password
                        </button>
                    </form>
                    
                    <div class="security-options">
                        <h3>Additional Security</h3>
                        <div class="security-item">
                            <div class="security-info">
                                <h4>Two-Factor Authentication</h4>
                                <p>Add an extra layer of security to your account</p>
                            </div>
                            <div class="security-action">
                                <label class="switch">
                                    <input type="checkbox">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="security-item">
                            <div class="security-info">
                                <h4>Login Activity</h4>
                                <p>View your recent login history</p>
                            </div>
                            <div class="security-action">
                                <button type="button" class="btn btn-secondary">
                                    View Logs
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Preferences Section -->
                <section id="preferences" class="profile-section">
                    <div class="section-header">
                        <h2><i class="fas fa-cog"></i> Preferences</h2>
                        <p>Customize your experience</p>
                    </div>
                    
                    <form method="POST" class="preferences-form">
                        <div class="form-group">
                            <h4>Notification Preferences</h4>
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="notify_orders" checked>
                                    <span>Order status updates</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="notify_promotions" checked>
                                    <span>Promotions and offers</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="notify_newsletter" checked>
                                    <span>Newsletter updates</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <h4>Preferred Delivery Time</h4>
                            <select name="preferred_time" class="form-control">
                                <option value="anytime">Anytime</option>
                                <option value="lunch">Lunch (11 AM - 2 PM)</option>
                                <option value="dinner">Dinner (6 PM - 9 PM)</option>
                                <option value="morning">Morning (8 AM - 11 AM)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <h4>Dietary Preferences</h4>
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="vegetarian">
                                    <span>Show vegetarian options first</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="spicy">
                                    <span>Show spicy options first</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <h4>Theme Preference</h4>
                            <div class="theme-selector">
                                <label class="theme-option">
                                    <input type="radio" name="theme" value="light" checked>
                                    <span class="theme-preview light">
                                        <i class="fas fa-sun"></i> Light
                                    </span>
                                </label>
                                <label class="theme-option">
                                    <input type="radio" name="theme" value="dark">
                                    <span class="theme-preview dark">
                                        <i class="fas fa-moon"></i> Dark
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Preferences
                        </button>
                    </form>
                </section>

                <!-- Social Links Section -->
                <section id="social" class="profile-section">
                    <div class="section-header">
                        <h2><i class="fas fa-share-alt"></i> Social Links</h2>
                        <p>Connect your social media accounts</p>
                    </div>
                    
                    <form method="POST" class="social-form">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="social-inputs">
                            <div class="form-group">
                                <label for="social_facebook">
                                    <i class="fab fa-facebook" style="color: #1877F2;"></i> Facebook
                                </label>
                                <input type="url" 
                                       id="social_facebook" 
                                       name="social_facebook" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($user['social_facebook'] ?? ''); ?>"
                                       placeholder="https://facebook.com/yourusername">
                            </div>
                            
                            <div class="form-group">
                                <label for="social_instagram">
                                    <i class="fab fa-instagram" style="color: #E4405F;"></i> Instagram
                                </label>
                                <input type="url" 
                                       id="social_instagram" 
                                       name="social_instagram" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($user['social_instagram'] ?? ''); ?>"
                                       placeholder="https://instagram.com/yourusername">
                            </div>
                            
                            <div class="form-group">
                                <label for="social_tiktok">
                                    <i class="fab fa-tiktok" style="color: #000000;"></i> TikTok
                                </label>
                                <input type="url" 
                                       id="social_tiktok" 
                                       name="social_tiktok" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($user['social_tiktok'] ?? ''); ?>"
                                       placeholder="https://tiktok.com/@yourusername">
                            </div>
                        </div>
                        
                        <div class="social-preview">
                            <h4>Preview</h4>
                            <div class="social-icons">
                                <?php if (!empty($user['social_facebook'])): ?>
                                    <a href="<?php echo htmlspecialchars($user['social_facebook']); ?>" 
                                       target="_blank" 
                                       class="social-icon facebook">
                                        <i class="fab fa-facebook"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if (!empty($user['social_instagram'])): ?>
                                    <a href="<?php echo htmlspecialchars($user['social_instagram']); ?>" 
                                       target="_blank" 
                                       class="social-icon instagram">
                                        <i class="fab fa-instagram"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if (!empty($user['social_tiktok'])): ?>
                                    <a href="<?php echo htmlspecialchars($user['social_tiktok']); ?>" 
                                       target="_blank" 
                                       class="social-icon tiktok">
                                        <i class="fab fa-tiktok"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Social Links
                        </button>
                    </form>
                </section>

                <!-- Delete Account Section -->
                <section id="danger-zone" class="profile-section danger-zone">
                    <div class="section-header">
                        <h2><i class="fas fa-exclamation-triangle"></i> Danger Zone</h2>
                        <p>Irreversible actions - proceed with caution</p>
                    </div>
                    
                    <div class="danger-actions">
                        <div class="danger-item">
                            <div class="danger-info">
                                <h4>Delete Account</h4>
                                <p>Permanently delete your account and all associated data</p>
                            </div>
                            <div class="danger-action">
                                <button type="button" 
                                        class="btn btn-danger" 
                                        onclick="showDeleteModal()">
                                    <i class="fas fa-trash"></i> Delete Account
                                </button>
                            </div>
                        </div>
                        
                        <div class="danger-item">
                            <div class="danger-info">
                                <h4>Export Data</h4>
                                <p>Download all your personal data</p>
                            </div>
                            <div class="danger-action">
                                <button type="button" class="btn btn-secondary">
                                    <i class="fas fa-download"></i> Export Data
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-exclamation-triangle"></i> Delete Account</h2>
                <button type="button" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="warning-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <p><strong>This action cannot be undone!</strong></p>
                    <p>All your data, including orders, reviews, and personal information will be permanently deleted.</p>
                </div>
                
                <form method="POST" class="delete-form">
                    <input type="hidden" name="action" value="delete_account">
                    
                    <div class="form-group">
                        <label for="confirm_delete">
                            To confirm, please type <strong>DELETE</strong> below:
                        </label>
                        <input type="text" 
                               id="confirm_delete" 
                               name="confirm_delete" 
                               class="form-control <?php echo isset($errors['confirm_delete']) ? 'is-invalid' : ''; ?>">
                        <?php if (isset($errors['confirm_delete'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['confirm_delete']); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Permanently Delete Account
                        </button>
                    </div>
                </form>
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
        // Show specific section
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.profile-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Show selected section
            document.getElementById(sectionId).classList.add('active');
            
            // Update active nav link
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            document.querySelector(`.nav-link[href="#${sectionId}"]`).classList.add('active');
            
            // Update URL hash
            window.location.hash = sectionId;
        }
        
        // Handle avatar upload preview
        document.getElementById('avatarInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('avatarPreview');
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                    document.querySelector('.btn-save-avatar').style.display = 'inline-block';
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Save avatar
        document.querySelector('.avatar-form').addEventListener('submit', function(e) {
            if (!document.getElementById('avatarInput').files.length) {
                e.preventDefault();
                alert('Please select a file first');
            }
        });
        
        // Toggle password visibility
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
        
        // Show delete modal
        function showDeleteModal() {
            document.getElementById('deleteModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Validate delete form
        document.querySelector('.delete-form').addEventListener('submit', function(e) {
            const confirmInput = document.getElementById('confirm_delete');
            if (confirmInput.value !== 'DELETE') {
                e.preventDefault();
                alert('Please type DELETE to confirm');
            }
        });
        
        // Initialize based on URL hash
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash.substring(1);
            if (hash) {
                showSection(hash);
            }
            
            // Initialize tooltips
            tippy('[data-tooltip]', {
                content: (reference) => reference.getAttribute('data-tooltip'),
                placement: 'top'
            });
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        };
        
        // Password strength checker
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthIndicator = document.createElement('div');
            strengthIndicator.className = 'strength-indicator';
            
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
            
            // Update strength indicator
            let indicator = document.querySelector('.strength-indicator');
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.className = 'strength-indicator';
                this.parentElement.appendChild(indicator);
            }
            
            indicator.innerHTML = `
                <div class="strength-bar">
                    <div class="strength-fill" style="width: ${strength * 20}%; background-color: ${color};"></div>
                </div>
                <small style="color: ${color};">${message}</small>
            `;
        });
    </script>
    
    <style>
        .profile-container {
            padding: 2rem 0;
        }
        
        .profile-header {
            margin-bottom: 2rem;
        }
        
        .page-title {
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .welcome-message {
            color: var(--text-light);
            font-size: 1.1rem;
        }
        
        .profile-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
        }
        
        .profile-sidebar {
            position: sticky;
            top: 100px;
            height: fit-content;
        }
        
        .user-card {
            background-color: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }
        
        .avatar-section {
            margin-bottom: 1.5rem;
        }
        
        .avatar-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 1rem;
            border: 4px solid var(--light-color);
        }
        
        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-default {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--light-color);
            color: var(--primary-color);
            font-size: 5rem;
        }
        
        .btn-change-avatar {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.9rem;
            transition: background-color 0.2s;
        }
        
        .btn-change-avatar:hover {
            background-color: #e05a2b;
        }
        
        .btn-save-avatar {
            background-color: var(--accent-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.9rem;
            margin-left: 0.5rem;
        }
        
        .user-info h2 {
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
        }
        
        .user-email,
        .user-phone,
        .user-role {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .profile-nav {
            background-color: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            color: var(--text-color);
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background-color: var(--light-color);
            border-left-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        .user-stats {
            background-color: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .user-stats h3 {
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .stats-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem;
            background-color: var(--light-color);
            border-radius: var(--radius);
        }
        
        .stat-item i {
            font-size: 1.2rem;
            color: var(--primary-color);
        }
        
        .stat-item strong {
            display: block;
            font-size: 1.1rem;
        }
        
        .stat-item span {
            font-size: 0.8rem;
            color: var(--text-light);
        }
        
        .profile-content {
            background-color: white;
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
        }
        
        .profile-section {
            display: none;
        }
        
        .profile-section.active {
            display: block;
        }
        
        .section-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-color);
        }
        
        .section-header h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .section-header p {
            color: var(--text-light);
            margin-bottom: 0;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .profile-form,
        .security-form,
        .preferences-form,
        .social-form {
            margin-bottom: 2rem;
        }
        
        .recent-orders {
            margin-bottom: 2rem;
        }
        
        .recent-orders h3 {
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .no-orders {
            text-align: center;
            padding: 2rem;
            background-color: var(--light-color);
            border-radius: var(--radius);
        }
        
        .orders-table {
            overflow-x: auto;
        }
        
        .orders-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .orders-table th,
        .orders-table td {
            padding: 0.8rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .orders-table th {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .orders-table tr:hover {
            background-color: var(--light-color);
        }
        
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.3rem 0.8rem;
            background-color: var(--primary-color);
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .view-all-orders {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .security-options {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }
        
        .security-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background-color: var(--light-color);
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }
        
        .security-info h4 {
            margin-bottom: 0.3rem;
            font-size: 1rem;
        }
        
        .security-info p {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .preferences-form .form-group {
            margin-bottom: 2rem;
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            margin-top: 0.5rem;
        }
        
        .theme-selector {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .theme-option {
            cursor: pointer;
        }
        
        .theme-option input {
            display: none;
        }
        
        .theme-preview {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            transition: all 0.2s;
        }
        
        .theme-option input:checked + .theme-preview {
            border-color: var(--primary-color);
            background-color: var(--light-color);
        }
        
        .theme-preview i {
            font-size: 1.5rem;
        }
        
        .theme-preview.light {
            color: var(--text-color);
        }
        
        .theme-preview.dark {
            background-color: #333;
            color: white;
        }
        
        .social-inputs {
            margin-bottom: 2rem;
        }
        
        .social-preview {
            padding: 1.5rem;
            background-color: var(--light-color);
            border-radius: var(--radius);
            margin-bottom: 2rem;
        }
        
        .social-icons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .social-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            text-decoration: none;
            transition: transform 0.2s;
        }
        
        .social-icon:hover {
            transform: scale(1.1);
        }
        
        .social-icon.facebook {
            background-color: #1877F2;
        }
        
        .social-icon.instagram {
            background: linear-gradient(45deg, #405DE6, #5851DB, #833AB4, #C13584, #E1306C, #FD1D1D);
        }
        
        .social-icon.tiktok {
            background-color: #000000;
        }
        
        .danger-zone {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid var(--danger-color);
        }
        
        .danger-zone .section-header {
            border-bottom-color: rgba(244, 67, 54, 0.2);
        }
        
        .danger-actions {
            margin-top: 1.5rem;
        }
        
        .danger-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background-color: #FFEBEE;
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }
        
        .danger-info h4 {
            margin-bottom: 0.3rem;
            color: #C62828;
        }
        
        .danger-info p {
            color: #666;
            margin-bottom: 0;
        }
        
        /* Delete Modal Styles */
        .warning-message {
            background-color: #FFF3CD;
            border: 1px solid #FFEAA7;
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .warning-message i {
            font-size: 2rem;
            color: #FFC107;
            margin-bottom: 0.5rem;
        }
        
        .warning-message p {
            margin-bottom: 0.5rem;
        }
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        @media (max-width: 992px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
            
            .profile-sidebar {
                position: static;
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .profile-content {
                padding: 1.5rem;
            }
            
            .security-item,
            .danger-item {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }
            
            .security-action,
            .danger-action {
                text-align: center;
            }
        }
        
        @media (max-width: 576px) {
            .theme-selector {
                flex-direction: column;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-actions .btn {
                width: 100%;
            }
        }
    </style>
</body>
</html>
