<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

// Require admin authentication
$auth->requireAdmin();

$db = Database::getInstance();

// Handle actions
$action = $_GET['action'] ?? '';
$meal_id = $_GET['id'] ?? 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_meal':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category_id = $_POST['category_id'] ?? 0;
            $price = $_POST['price'] ?? 0;
            $discount_price = $_POST['discount_price'] ?? null;
            $ingredients = trim($_POST['ingredients'] ?? '');
            $preparation_time = $_POST['preparation_time'] ?? 30;
            $is_vegetarian = isset($_POST['is_vegetarian']) ? 1 : 0;
            $is_spicy = isset($_POST['is_spicy']) ? 1 : 0;
            $is_available = isset($_POST['is_available']) ? 1 : 0;
            
            // Handle image upload
            $image_url = '';
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                $file_type = $_FILES['image']['type'];
                $file_size = $_FILES['image']['size'];
                
                if (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
                    $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $filename = 'meal_' . time() . '_' . uniqid() . '.' . $extension;
                    $upload_path = '../assets/uploads/meals/' . $filename;
                    
                    // Create directory if it doesn't exist
                    if (!file_exists('../assets/uploads/meals/')) {
                        mkdir('../assets/uploads/meals/', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        $image_url = 'assets/uploads/meals/' . $filename;
                    }
                }
            }
            
            $sql = "INSERT INTO meals (name, description, category_id, price, discount_price, 
                    image_url, ingredients, preparation_time, is_vegetarian, is_spicy, is_available) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ssiddssiiii", 
                $name, $description, $category_id, $price, $discount_price,
                $image_url, $ingredients, $preparation_time, $is_vegetarian, $is_spicy, $is_available
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Meal added successfully';
                header('Location: manage_meals.php');
                exit();
            }
            break;
            
        case 'update_meal':
            $meal_id = $_POST['meal_id'] ?? 0;
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category_id = $_POST['category_id'] ?? 0;
            $price = $_POST['price'] ?? 0;
            $discount_price = $_POST['discount_price'] ?? null;
            $ingredients = trim($_POST['ingredients'] ?? '');
            $preparation_time = $_POST['preparation_time'] ?? 30;
            $is_vegetarian = isset($_POST['is_vegetarian']) ? 1 : 0;
            $is_spicy = isset($_POST['is_spicy']) ? 1 : 0;
            $is_available = isset($_POST['is_available']) ? 1 : 0;
            
            // Handle image upload if new image provided
            $image_url = $_POST['current_image'] ?? '';
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                $file_type = $_FILES['image']['type'];
                $file_size = $_FILES['image']['size'];
                
                if (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
                    $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $filename = 'meal_' . time() . '_' . uniqid() . '.' . $extension;
                    $upload_path = '../assets/uploads/meals/' . $filename;
                    
                    // Create directory if it doesn't exist
                    if (!file_exists('../assets/uploads/meals/')) {
                        mkdir('../assets/uploads/meals/', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        // Delete old image if exists
                        if (!empty($image_url) && file_exists('../' . $image_url)) {
                            unlink('../' . $image_url);
                        }
                        $image_url = 'assets/uploads/meals/' . $filename;
                    }
                }
            }
            
            $sql = "UPDATE meals SET 
                    name = ?, description = ?, category_id = ?, price = ?, discount_price = ?,
                    image_url = ?, ingredients = ?, preparation_time = ?, is_vegetarian = ?,
                    is_spicy = ?, is_available = ?, updated_at = NOW() 
                    WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ssiddssiiiiis", 
                $name, $description, $category_id, $price, $discount_price,
                $image_url, $ingredients, $preparation_time, $is_vegetarian, $is_spicy, $is_available,
                $meal_id
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Meal updated successfully';
                header('Location: manage_meals.php');
                exit();
            }
            break;
            
        case 'delete_meal':
            $meal_id = $_POST['meal_id'] ?? 0;
            
            // Get image URL before deletion
            $sql = "SELECT image_url FROM meals WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $meal_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // Delete image file if exists
                if (!empty($row['image_url']) && file_exists('../' . $row['image_url'])) {
                    unlink('../' . $row['image_url']);
                }
            }
            
            // Delete meal from database
            $sql = "DELETE FROM meals WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $meal_id);
            $stmt->execute();
            
            $_SESSION['success_message'] = 'Meal deleted successfully';
            header('Location: manage_meals.php');
            exit();
            break;
            
        case 'bulk_action':
            $bulk_action = $_POST['bulk_action'] ?? '';
            $meal_ids = $_POST['meal_ids'] ?? [];
            
            if (!empty($meal_ids) && $bulk_action) {
                $placeholders = str_repeat('?,', count($meal_ids) - 1) . '?';
                
                switch ($bulk_action) {
                    case 'delete':
                        // Get image URLs before deletion
                        $sql = "SELECT image_url FROM meals WHERE id IN ($placeholders)";
                        $stmt = $db->prepare($sql);
                        $stmt->bind_param(str_repeat('i', count($meal_ids)), ...$meal_ids);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        while ($row = $result->fetch_assoc()) {
                            if (!empty($row['image_url']) && file_exists('../' . $row['image_url'])) {
                                unlink('../' . $row['image_url']);
                            }
                        }
                        
                        $sql = "DELETE FROM meals WHERE id IN ($placeholders)";
                        $stmt = $db->prepare($sql);
                        $stmt->bind_param(str_repeat('i', count($meal_ids)), ...$meal_ids);
                        $stmt->execute();
                        
                        $_SESSION['success_message'] = 'Selected meals deleted successfully';
                        break;
                        
                    case 'activate':
                        $sql = "UPDATE meals SET is_available = 1, updated_at = NOW() 
                                WHERE id IN ($placeholders)";
                        $stmt = $db->prepare($sql);
                        $stmt->bind_param(str_repeat('i', count($meal_ids)), ...$meal_ids);
                        $stmt->execute();
                        
                        $_SESSION['success_message'] = 'Selected meals activated successfully';
                        break;
                        
                    case 'deactivate':
                        $sql = "UPDATE meals SET is_available = 0, updated_at = NOW() 
                                WHERE id IN ($placeholders)";
                        $stmt = $db->prepare($sql);
                        $stmt->bind_param(str_repeat('i', count($meal_ids)), ...$meal_ids);
                        $stmt->execute();
                        
                        $_SESSION['success_message'] = 'Selected meals deactivated successfully';
                        break;
                }
                
                header('Location: manage_meals.php');
                exit();
            }
            break;
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? 'all';
$availability = $_GET['availability'] ?? 'all';
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Get categories for filter
$categories = [];
$category_stmt = $db->prepare("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name");
$category_stmt->execute();
$category_result = $category_stmt->get_result();
while ($row = $category_result->fetch_assoc()) {
    $categories[] = $row;
}

// Build query
$where = "WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $where .= " AND (m.name LIKE ? OR m.description LIKE ? OR m.ingredients LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
    $types .= "sss";
}

if ($category !== 'all') {
    $where .= " AND m.category_id = ?";
    $params[] = $category;
    $types .= "i";
}

if ($availability !== 'all') {
    $where .= " AND m.is_available = ?";
    $params[] = ($availability === 'available' ? 1 : 0);
    $types .= "i";
}

// Order by
$orderBy = "ORDER BY ";
switch ($sort) {
    case 'name':
        $orderBy .= "m.name ASC";
        break;
    case 'price_asc':
        $orderBy .= "m.price ASC";
        break;
    case 'price_desc':
        $orderBy .= "m.price DESC";
        break;
    case 'popular':
        $orderBy .= "m.total_orders DESC";
        break;
    case 'rating':
        $orderBy .= "m.rating DESC";
        break;
    default: // newest
        $orderBy .= "m.created_at DESC";
        break;
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM meals m {$where}";
$count_stmt = $db->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Get meals
$sql = "SELECT m.*, c.name as category_name, 
        (SELECT COUNT(*) FROM order_items WHERE meal_id = m.id) as total_ordered
        FROM meals m 
        LEFT JOIN categories c ON m.category_id = c.id 
        {$where} 
        {$orderBy} 
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$meals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get meal stats
$stats = [
    'total' => 0,
    'available' => 0,
    'vegetarian' => 0,
    'spicy' => 0,
    'discounted' => 0
];

$stats_stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN is_vegetarian = 1 THEN 1 ELSE 0 END) as vegetarian,
        SUM(CASE WHEN is_spicy = 1 THEN 1 ELSE 0 END) as spicy,
        SUM(CASE WHEN discount_price IS NOT NULL THEN 1 ELSE 0 END) as discounted
    FROM meals
");
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
if ($row = $stats_result->fetch_assoc()) {
    $stats = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Meals - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Dropify/0.2.2/css/dropify.min.css">
</head>
<body>
    <!-- Admin Navbar -->
    <?php include '../admin/includes/admin_navbar.php'; ?>

    <div class="admin-container">
        <!-- Admin Sidebar -->
       
        <!-- Main Content -->
        <main class="admin-main">
            <header class="admin-header">
                <div class="header-left">
                    <h1><i class="fas fa-utensils"></i> Meal Management</h1>
                    <p class="page-description">Manage your menu items, prices, and availability</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-primary" onclick="showMealModal('add')">
                        <i class="fas fa-plus-circle"></i> Add New Meal
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="exportMeals()">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <button type="button" class="btn btn-info" onclick="showImportModal()">
                        <i class="fas fa-upload"></i> Import
                    </button>
                </div>
            </header>

            <!-- Success Message -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #667eea;">
                        <i class="fas fa-utensils"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total']; ?></h3>
                        <p>Total Meals</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #4CAF50;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['available']; ?></h3>
                        <p>Available</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #FF9800;">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['vegetarian']; ?></h3>
                        <p>Vegetarian</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #F44336;">
                        <i class="fas fa-pepper-hot"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['spicy']; ?></h3>
                        <p>Spicy</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9C27B0;">
                        <i class="fas fa-tag"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['discounted']; ?></h3>
                        <p>On Discount</p>
                    </div>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="admin-filters">
                <form method="GET" class="filter-form" id="filterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <input type="text" 
                                   name="search" 
                                   placeholder="Search meals..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                        
                        <div class="filter-group">
                            <select name="category">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" 
                                            <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <select name="availability">
                                <option value="all" <?php echo $availability === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="available" <?php echo $availability === 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="unavailable" <?php echo $availability === 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <select name="sort">
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name A-Z</option>
                                <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                                <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                                <option value="popular" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                                <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Highest Rated</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        
                        <?php if (!empty($search) || $category !== 'all' || $availability !== 'all'): ?>
                            <a href="manage_meals.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <div class="bulk-actions" id="bulkActions" style="display: none;">
                <form method="POST" id="bulkActionForm">
                    <input type="hidden" name="action" value="bulk_action">
                    <div class="bulk-action-content">
                        <span id="selectedCount">0 meals selected</span>
                        <select name="bulk_action" required>
                            <option value="">Select Action</option>
                            <option value="activate">Activate Selected</option>
                            <option value="deactivate">Deactivate Selected</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-play"></i> Apply
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearSelection()">
                            <i class="fas fa-times"></i> Clear Selection
                        </button>
                    </div>
                </form>
            </div>

            <!-- Meals Table -->
            <div class="admin-card">
                <div class="card-header">
                    <h3>Meals (<?php echo $total_rows; ?>)</h3>
                    <div class="card-actions">
                        <button type="button" class="btn-action" onclick="toggleSelectAll()" id="selectAllBtn">
                            <i class="far fa-square"></i> Select All
                        </button>
                        <button type="button" class="btn-action" onclick="refreshTable()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <button type="button" class="btn-action" onclick="printTable()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($meals)): ?>
                        <div class="no-data">
                            <i class="fas fa-utensils"></i>
                            <p>No meals found</p>
                            <button type="button" class="btn btn-primary" onclick="showMealModal('add')">
                                <i class="fas fa-plus"></i> Add Your First Meal
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="admin-table" id="mealsTable">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="masterCheckbox" onchange="toggleSelectAll()">
                                        </th>
                                        <th width="80">Image</th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th>Orders</th>
                                        <th>Rating</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($meals as $meal): ?>
                                        <tr data-meal-id="<?php echo $meal['id']; ?>">
                                            <td>
                                                <input type="checkbox" class="meal-checkbox" 
                                                       value="<?php echo $meal['id']; ?>" 
                                                       onchange="updateSelection()">
                                            </td>
                                            <td>
                                                <div class="meal-image">
                                                    <?php if (!empty($meal['image_url'])): ?>
                                                        <img src="../<?php echo htmlspecialchars($meal['image_url']); ?>" 
                                                             alt="<?php echo htmlspecialchars($meal['name']); ?>">
                                                    <?php else: ?>
                                                        <div class="no-image">
                                                            <i class="fas fa-utensils"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="meal-info">
                                                    <strong><?php echo htmlspecialchars($meal['name']); ?></strong>
                                                    <div class="meal-tags">
                                                        <?php if ($meal['is_vegetarian']): ?>
                                                            <span class="tag vegetarian" title="Vegetarian">
                                                                <i class="fas fa-leaf"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($meal['is_spicy']): ?>
                                                            <span class="tag spicy" title="Spicy">
                                                                <i class="fas fa-pepper-hot"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($meal['discount_price']): ?>
                                                            <span class="tag discount" title="Discounted">
                                                                <i class="fas fa-tag"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <small class="meal-description">
                                                        <?php echo htmlspecialchars(substr($meal['description'], 0, 60)); ?>...
                                                    </small>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($meal['category_name'] ?? 'Uncategorized'); ?></td>
                                            <td>
                                                <div class="price-info">
                                                    <?php if ($meal['discount_price']): ?>
                                                        <span class="original-price">
                                                            $<?php echo number_format($meal['price'], 2); ?>
                                                        </span>
                                                        <span class="current-price">
                                                            $<?php echo number_format($meal['discount_price'], 2); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="current-price">
                                                            $<?php echo number_format($meal['price'], 2); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $meal['is_available'] ? 'available' : 'unavailable'; ?>">
                                                    <?php echo $meal['is_available'] ? 'Available' : 'Unavailable'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="order-count">
                                                    <i class="fas fa-shopping-cart"></i>
                                                    <span><?php echo $meal['total_ordered'] ?? 0; ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="rating">
                                                    <div class="stars">
                                                        <?php
                                                        $rating = $meal['rating'] ?? 0;
                                                        $full_stars = floor($rating);
                                                        $half_star = $rating - $full_stars >= 0.5;
                                                        ?>
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <?php if ($i <= $full_stars): ?>
                                                                <i class="fas fa-star"></i>
                                                            <?php elseif ($i == $full_stars + 1 && $half_star): ?>
                                                                <i class="fas fa-star-half-alt"></i>
                                                            <?php else: ?>
                                                                <i class="far fa-star"></i>
                                                            <?php endif; ?>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <small><?php echo number_format($rating, 1); ?>/5</small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" 
                                                            class="btn-action btn-view" 
                                                            onclick="viewMeal(<?php echo $meal['id']; ?>)"
                                                            title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-edit" 
                                                            onclick="editMeal(<?php echo $meal['id']; ?>)"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-duplicate" 
                                                            onclick="duplicateMeal(<?php echo $meal['id']; ?>)"
                                                            title="Duplicate">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                    
                                                    <button type="button" 
                                                            class="btn-action btn-delete" 
                                                            onclick="deleteMeal(<?php echo $meal['id']; ?>, '<?php echo addslashes($meal['name']); ?>')"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>
                                
                                <div class="page-numbers">
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($total_pages, $page + 2);
                                    
                                    for ($i = $start; $i <= $end; $i++):
                                    ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                </div>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="quick-stats-grid">
                <div class="quick-stat">
                    <div class="stat-header">
                        <h4><i class="fas fa-fire"></i> Top Selling Meals</h4>
                        <a href="reports.php?type=best_sellers">View All</a>
                    </div>
                    <div class="stat-list" id="topSellingMeals">
                        <!-- Will be loaded via JavaScript -->
                    </div>
                </div>
                
                <div class="quick-stat">
                    <div class="stat-header">
                        <h4><i class="fas fa-star"></i> Top Rated Meals</h4>
                        <a href="reports.php?type=top_rated">View All</a>
                    </div>
                    <div class="stat-list" id="topRatedMeals">
                        <!-- Will be loaded via JavaScript -->
                    </div>
                </div>
                
                <div class="quick-stat">
                    <div class="stat-header">
                        <h4><i class="fas fa-exclamation-triangle"></i> Out of Stock</h4>
                        <a href="manage_meals.php?availability=unavailable">View All</a>
                    </div>
                    <div class="stat-list" id="outOfStockMeals">
                        <!-- Will be loaded via JavaScript -->
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Meal Modal (Add/Edit) -->
    <div class="modal" id="mealModal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h2 id="modalTitle">Add New Meal</h2>
                <button type="button" onclick="closeMealModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="mealForm" enctype="multipart/form-data">
                    <input type="hidden" id="actionType" name="action" value="add_meal">
                    <input type="hidden" id="mealId" name="meal_id" value="">
                    <input type="hidden" id="currentImage" name="current_image" value="">
                    
                    <div class="form-tabs">
                        <button type="button" class="tab-btn active" data-tab="basic">Basic Info</button>
                        <button type="button" class="tab-btn" data-tab="details">Details</button>
                        <button type="button" class="tab-btn" data-tab="nutrition">Nutrition</button>
                        <button type="button" class="tab-btn" data-tab="seo">SEO</button>
                    </div>
                    
                    <div class="tab-content active" id="basicTab">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name">Meal Name *</label>
                                <input type="text" id="name" name="name" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="category_id">Category *</label>
                                <select id="category_id" name="category_id" class="form-control" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>">
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description *</label>
                            <textarea id="description" name="description" class="form-control" rows="4" required></textarea>
                            <small class="form-text">Brief description of the meal</small>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="price">Price ($) *</label>
                                <input type="number" id="price" name="price" class="form-control" step="0.01" min="0" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="discount_price">Discount Price ($)</label>
                                <input type="number" id="discount_price" name="discount_price" class="form-control" step="0.01" min="0">
                                <small class="form-text">Leave empty if no discount</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="preparation_time">Prep Time (minutes)</label>
                                <input type="number" id="preparation_time" name="preparation_time" class="form-control" min="1" value="30">
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-content" id="detailsTab">
                        <div class="form-group">
                            <label for="ingredients">Ingredients</label>
                            <textarea id="ingredients" name="ingredients" class="form-control" rows="5"></textarea>
                            <small class="form-text">List ingredients separated by commas</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="image">Meal Image</label>
                            <input type="file" id="image" name="image" class="dropify" data-height="200">
                            <small class="form-text">Recommended size: 800x600px, Max: 5MB</small>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="is_vegetarian" name="is_vegetarian" value="1">
                                    <span>Vegetarian</span>
                                </label>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="is_spicy" name="is_spicy" value="1">
                                    <span>Spicy</span>
                                </label>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="is_available" name="is_available" value="1" checked>
                                    <span>Available for Order</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-content" id="nutritionTab">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="calories">Calories</label>
                                <input type="number" id="calories" name="calories" class="form-control" min="0">
                                <small class="form-text">Calories per serving</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="protein">Protein (g)</label>
                                <input type="number" id="protein" name="protein" class="form-control" step="0.1" min="0">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="carbs">Carbs (g)</label>
                                <input type="number" id="carbs" name="carbs" class="form-control" step="0.1" min="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="fat">Fat (g)</label>
                                <input type="number" id="fat" name="fat" class="form-control" step="0.1" min="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-content" id="seoTab">
                        <div class="form-group">
                            <label for="meta_title">Meta Title</label>
                            <input type="text" id="meta_title" name="meta_title" class="form-control">
                            <small class="form-text">SEO title for search engines</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="meta_description">Meta Description</label>
                            <textarea id="meta_description" name="meta_description" class="form-control" rows="3"></textarea>
                            <small class="form-text">SEO description for search engines</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="slug">URL Slug</label>
                            <input type="text" id="slug" name="slug" class="form-control">
                            <small class="form-text">Leave empty to auto-generate</small>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeMealModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Save Meal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Import Modal -->
    <div class="modal" id="importModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-upload"></i> Import Meals</h2>
                <button type="button" onclick="closeImportModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="import-options">
                    <div class="import-option active" onclick="selectImportOption('csv')">
                        <i class="fas fa-file-csv"></i>
                        <span>CSV File</span>
                    </div>
                    <div class="import-option" onclick="selectImportOption('excel')">
                        <i class="fas fa-file-excel"></i>
                        <span>Excel File</span>
                    </div>
                    <div class="import-option" onclick="selectImportOption('json')">
                        <i class="fas fa-file-code"></i>
                        <span>JSON File</span>
                    </div>
                </div>
                
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <input type="hidden" name="action" value="import">
                    <input type="hidden" id="importType" name="import_type" value="csv">
                    
                    <div class="form-group">
                        <label for="importFile">Choose File</label>
                        <input type="file" id="importFile" name="import_file" class="form-control" accept=".csv,.xlsx,.json" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="update_existing" value="1">
                            <span>Update existing meals</span>
                        </label>
                    </div>
                    
                    <div class="import-preview" id="importPreview">
                        <!-- Preview will be shown here -->
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeImportModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i> Import
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
                <button type="button" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="warning-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Are you sure you want to delete <strong id="deleteMealName"></strong>?</p>
                    <p class="text-danger">This action cannot be undone!</p>
                </div>
                
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="delete_meal">
                    <input type="hidden" id="deleteMealId" name="meal_id" value="">
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Meal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Admin Footer -->
    <?php include 'includes/admin_footer.php'; ?>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Dropify/0.2.2/js/dropify.min.js"></script>
    <script src="https://cdn.ckeditor.com/4.16.2/standard/ckeditor.js"></script>

    <script>
        // Initialize CKEditor for description
        CKEDITOR.replace('description', {
            toolbar: [
                { name: 'basicstyles', items: ['Bold', 'Italic', 'Underline'] },
                { name: 'paragraph', items: ['NumberedList', 'BulletedList'] },
                { name: 'links', items: ['Link', 'Unlink'] },
                { name: 'tools', items: ['Maximize'] }
            ],
            height: 150
        });

        // Initialize dropify for image upload
        $('.dropify').dropify({
            messages: {
                'default': 'Drag and drop an image here or click',
                'replace': 'Drag and drop or click to replace',
                'remove': 'Remove',
                'error': 'Oops, something went wrong.'
            },
            error: {
                'fileSize': 'The file size is too big ({{ value }} max).',
                'fileFormat': 'The file format is not allowed ({{ value }} only).'
            }
        });

        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tab = this.dataset.tab;
                
                // Update active tab button
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Show selected tab content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(tab + 'Tab').classList.add('active');
            });
        });

        // Show meal modal
        function showMealModal(action, mealId = null) {
            if (action === 'add') {
                document.getElementById('modalTitle').textContent = 'Add New Meal';
                document.getElementById('actionType').value = 'add_meal';
                document.getElementById('mealId').value = '';
                document.getElementById('currentImage').value = '';
                document.getElementById('mealForm').reset();
                CKEDITOR.instances.description.setData('');
                
                // Reset dropify
                $('.dropify').dropify();
            }
            
            document.getElementById('mealModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Close meal modal
        function closeMealModal() {
            document.getElementById('mealModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Edit meal
        async function editMeal(mealId) {
            try {
                const response = await fetch(`api/meals.php?action=get_meal&id=${mealId}`);
                const meal = await response.json();
                
                document.getElementById('modalTitle').textContent = 'Edit Meal';
                document.getElementById('actionType').value = 'update_meal';
                document.getElementById('mealId').value = meal.id;
                document.getElementById('currentImage').value = meal.image_url || '';
                document.getElementById('name').value = meal.name;
                document.getElementById('category_id').value = meal.category_id;
                CKEDITOR.instances.description.setData(meal.description);
                document.getElementById('price').value = meal.price;
                document.getElementById('discount_price').value = meal.discount_price || '';
                document.getElementById('preparation_time').value = meal.preparation_time;
                document.getElementById('ingredients').value = meal.ingredients;
                document.getElementById('is_vegetarian').checked = meal.is_vegetarian == 1;
                document.getElementById('is_spicy').checked = meal.is_spicy == 1;
                document.getElementById('is_available').checked = meal.is_available == 1;
                
                // Set image preview if exists
                if (meal.image_url) {
                    const dropify = $('.dropify').data('dropify');
                    dropify.resetPreview();
                    dropify.clearElement();
                    dropify.settings.defaultFile = '../' + meal.image_url;
                    dropify.destroy();
                    dropify.init();
                }
                
                document.getElementById('mealModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            } catch (error) {
                console.error('Error:', error);
                showNotification('Failed to load meal data', 'error');
            }
        }

        // View meal details
        async function viewMeal(mealId) {
            try {
                const response = await fetch(`api/meals.php?action=get_meal&id=${mealId}`);
                const meal = await response.json();
                
                // Create modal for viewing
                const modalContent = `
                    <div class="meal-view-modal">
                        <div class="meal-view-header">
                            <h3>${meal.name}</h3>
                            <span class="category">${meal.category_name}</span>
                        </div>
                        <div class="meal-view-body">
                            ${meal.image_url ? `
                                <div class="meal-view-image">
                                    <img src="../${meal.image_url}" alt="${meal.name}">
                                </div>
                            ` : ''}
                            <div class="meal-view-details">
                                <p><strong>Description:</strong> ${meal.description}</p>
                                <p><strong>Ingredients:</strong> ${meal.ingredients || 'Not specified'}</p>
                                <div class="meal-view-stats">
                                    <div class="stat">
                                        <i class="fas fa-clock"></i>
                                        <span>${meal.preparation_time} min</span>
                                    </div>
                                    <div class="stat">
                                        <i class="fas fa-dollar-sign"></i>
                                        <span>$${meal.price}</span>
                                    </div>
                                    ${meal.discount_price ? `
                                        <div class="stat discount">
                                            <i class="fas fa-tag"></i>
                                            <span>$${meal.discount_price}</span>
                                        </div>
                                    ` : ''}
                                    <div class="stat">
                                        <i class="fas fa-shopping-cart"></i>
                                        <span>${meal.total_orders || 0} orders</span>
                                    </div>
                                </div>
                                <div class="meal-view-tags">
                                    ${meal.is_vegetarian ? '<span class="tag vegetarian"><i class="fas fa-leaf"></i> Vegetarian</span>' : ''}
                                    ${meal.is_spicy ? '<span class="tag spicy"><i class="fas fa-pepper-hot"></i> Spicy</span>' : ''}
                                    ${meal.is_available ? '<span class="tag available">Available</span>' : '<span class="tag unavailable">Unavailable</span>'}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                showCustomModal('Meal Details', modalContent);
            } catch (error) {
                console.error('Error:', error);
                showNotification('Failed to load meal details', 'error');
            }
        }

        // Duplicate meal
        function duplicateMeal(mealId) {
            if (confirm('Create a duplicate of this meal?')) {
                fetch('api/meals.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'duplicate',
                        meal_id: mealId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Meal duplicated successfully', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification(data.message || 'Failed to duplicate meal', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
            }
        }

        // Delete meal confirmation
        function deleteMeal(mealId, mealName) {
            document.getElementById('deleteMealName').textContent = mealName;
            document.getElementById('deleteMealId').value = mealId;
            document.getElementById('deleteModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Bulk selection functions
        let selectedMeals = [];

        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('.meal-checkbox');
            const masterCheckbox = document.getElementById('masterCheckbox');
            const selectAllBtn = document.getElementById('selectAllBtn');
            
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
                cb.dispatchEvent(new Event('change'));
            });
            
            masterCheckbox.checked = !allChecked;
            selectAllBtn.innerHTML = !allChecked ? 
                '<i class="far fa-check-square"></i> Deselect All' : 
                '<i class="far fa-square"></i> Select All';
        }

        function updateSelection() {
            selectedMeals = Array.from(document.querySelectorAll('.meal-checkbox:checked'))
                .map(cb => cb.value);
            
            const selectedCount = selectedMeals.length;
            const bulkActions = document.getElementById('bulkActions');
            const selectedCountSpan = document.getElementById('selectedCount');
            
            if (selectedCount > 0) {
                bulkActions.style.display = 'block';
                selectedCountSpan.textContent = `${selectedCount} meal${selectedCount > 1 ? 's' : ''} selected`;
            } else {
                bulkActions.style.display = 'none';
            }
        }

        function clearSelection() {
            document.querySelectorAll('.meal-checkbox').forEach(cb => {
                cb.checked = false;
            });
            document.getElementById('masterCheckbox').checked = false;
            updateSelection();
        }

        // Import functions
        function showImportModal() {
            document.getElementById('importModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function selectImportOption(type) {
            document.querySelectorAll('.import-option').forEach(opt => {
                opt.classList.remove('active');
            });
            event.target.closest('.import-option').classList.add('active');
            document.getElementById('importType').value = type;
        }

        // Export meals
        function exportMeals() {
            const params = new URLSearchParams(window.location.search);
            params.set('action', 'export');
            window.location.href = `api/meals.php?${params.toString()}`;
        }

        // Refresh table
        function refreshTable() {
            window.location.reload();
        }

        // Print table
        function printTable() {
            window.print();
        }

        // Load quick stats
        async function loadQuickStats() {
            try {
                // Top selling meals
                const sellingResponse = await fetch('api/meals.php?action=top_selling&limit=5');
                const sellingMeals = await sellingResponse.json();
                
                const sellingList = document.getElementById('topSellingMeals');
                if (sellingList) {
                    sellingList.innerHTML = sellingMeals.map(meal => `
                        <div class="stat-item">
                            <div class="stat-item-info">
                                <strong>${meal.name}</strong>
                                <small>${meal.category_name}</small>
                            </div>
                            <div class="stat-item-value">
                                <span class="badge">${meal.total_orders} orders</span>
                            </div>
                        </div>
                    `).join('');
                }
                
                // Top rated meals
                const ratedResponse = await fetch('api/meals.php?action=top_rated&limit=5');
                const ratedMeals = await ratedResponse.json();
                
                const ratedList = document.getElementById('topRatedMeals');
                if (ratedList) {
                    ratedList.innerHTML = ratedMeals.map(meal => `
                        <div class="stat-item">
                            <div class="stat-item-info">
                                <strong>${meal.name}</strong>
                                <small>${meal.category_name}</small>
                            </div>
                            <div class="stat-item-value">
                                <span class="rating">${meal.rating}/5</span>
                            </div>
                        </div>
                    `).join('');
                }
                
                // Out of stock meals
                const stockResponse = await fetch('api/meals.php?action=out_of_stock&limit=5');
                const stockMeals = await stockResponse.json();
                
                const stockList = document.getElementById('outOfStockMeals');
                if (stockList) {
                    stockList.innerHTML = stockMeals.map(meal => `
                        <div class="stat-item">
                            <div class="stat-item-info">
                                <strong>${meal.name}</strong>
                                <small>${meal.category_name}</small>
                            </div>
                            <div class="stat-item-value">
                                <span class="badge unavailable">Out of Stock</span>
                            </div>
                        </div>
                    `).join('');
                }
            } catch (error) {
                console.error('Error loading quick stats:', error);
            }
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                ${message}
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Show custom modal
        function showCustomModal(title, content) {
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.display = 'block';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>${title}</h3>
                        <button type="button" onclick="this.closest('.modal').remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        ${content}
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            
            // Close when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.remove();
                    document.body.style.overflow = 'auto';
                }
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['mealModal', 'importModal', 'deleteModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal && event.target === modal) {
                    if (modalId === 'mealModal') closeMealModal();
                    if (modalId === 'importModal') closeImportModal();
                    if (modalId === 'deleteModal') closeDeleteModal();
                }
            });
        };

        // Initialize DataTable
        $(document).ready(function() {
            $('#mealsTable').DataTable({
                paging: false,
                searching: false,
                info: false,
                order: [],
                columnDefs: [
                    { orderable: false, targets: [0, 1, 8] }
                ],
                language: {
                    emptyTable: "No meals found"
                }
            });
            
            // Load quick stats
            loadQuickStats();
        });
    </script>

    <style>
        /* Additional Styles for Meal Management */
        .large-modal {
            max-width: 800px;
        }
        
        .form-tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }
        
        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: #4a5568;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .tab-btn.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-btn:hover:not(.active) {
            color: #2d3748;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .meal-image {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .meal-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .no-image {
            width: 100%;
            height: 100%;
            background: #f7fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #cbd5e0;
        }
        
        .meal-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .meal-tags {
            display: flex;
            gap: 0.25rem;
        }
        
        .tag {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .tag.vegetarian {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .tag.spicy {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .tag.discount {
            background: #e9d8fd;
            color: #44337a;
        }
        
        .tag.available {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .tag.unavailable {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .meal-description {
            color: #718096;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        
        .price-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .original-price {
            text-decoration: line-through;
            color: #a0aec0;
            font-size: 0.85rem;
        }
        
        .current-price {
            font-weight: 600;
            color: #2d3748;
        }
        
        .status-badge {
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-available {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .status-unavailable {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .order-count {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .order-count i {
            color: #667eea;
        }
        
        .rating {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .stars {
            color: #f6ad55;
        }
        
        .rating small {
            color: #718096;
            font-size: 0.85rem;
        }
        
        .bulk-actions {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .bulk-action-content {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .bulk-action-content select {
            min-width: 200px;
        }
        
        .quick-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        
        .quick-stat {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .stat-header h4 {
            margin: 0;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-header a {
            font-size: 0.85rem;
            color: #667eea;
            text-decoration: none;
        }
        
        .stat-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #f7fafc;
            border-radius: 6px;
        }
        
        .stat-item-info {
            flex: 1;
        }
        
        .stat-item-info strong {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .stat-item-info small {
            color: #718096;
            font-size: 0.8rem;
        }
        
        .stat-item-value .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .badge.unavailable {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .import-options {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .import-option {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .import-option.active {
            border-color: #667eea;
            background: #f7fafc;
        }
        
        .import-option i {
            font-size: 2rem;
            color: #667eea;
        }
        
        .import-option span {
            font-weight: 500;
        }
        
        .import-preview {
            margin: 1rem 0;
            padding: 1rem;
            background: #f7fafc;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .meal-view-modal {
            max-width: 600px;
        }
        
        .meal-view-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .meal-view-header h3 {
            margin-bottom: 0.5rem;
        }
        
        .meal-view-header .category {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        .meal-view-image {
            width: 100%;
            height: 300px;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        
        .meal-view-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .meal-view-details p {
            margin-bottom: 1rem;
            line-height: 1.6;
        }
        
        .meal-view-stats {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin: 1.5rem 0;
        }
        
        .meal-view-stats .stat {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        .meal-view-stats .stat.discount {
            background: #e9d8fd;
        }
        
        .meal-view-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .large-modal {
                width: 95%;
            }
            
            .form-tabs {
                flex-wrap: wrap;
            }
            
            .tab-btn {
                flex: 1;
                text-align: center;
                padding: 0.5rem;
            }
            
            .bulk-action-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .bulk-action-content select {
                min-width: 100%;
            }
            
            .quick-stats-grid {
                grid-template-columns: 1fr;
            }
            
            .import-options {
                flex-direction: column;
            }
            
            .meal-view-image {
                height: 200px;
            }
        }
    </style>
</body>
</html>