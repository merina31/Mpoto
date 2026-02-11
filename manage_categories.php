<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

// Require admin authentication
$auth->requireAdmin();

$db = Database::getInstance();

function category_has_column($column) {
    global $db;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $escapedColumn = $db->escapeString($column);
    $result = $db->getConnection()->query("SHOW COLUMNS FROM categories LIKE '{$escapedColumn}'");
    return ($result && $result->num_rows > 0);
}

$hasParentIdColumn = category_has_column('parent_id');
$hasIsActiveColumn = category_has_column('is_active');
$hasShowInMenuColumn = category_has_column('show_in_menu');
$hasSortOrderColumn = category_has_column('sort_order');
$hasUpdatedAtColumn = category_has_column('updated_at');

// Handle actions
$action = $_GET['action'] ?? '';
$category_id = $_GET['id'] ?? 0;

// Accept JSON payloads for AJAX POST actions (e.g., reorder from fetch API)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $jsonBody = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonBody)) {
            $_POST = $jsonBody;
        }
    }
}

// AJAX/API actions handled on this same page
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $apiAction = $_GET['action'];

    if ($apiAction === 'get_category') {
        header('Content-Type: application/json');
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid category ID']);
            exit();
        }

        $sql = "SELECT
                    c.id,
                    c.name,
                    c.description,
                    c.image,
                    " . ($hasParentIdColumn ? "c.parent_id" : "NULL") . " AS parent_id,
                    " . ($hasSortOrderColumn ? "c.sort_order" : "0") . " AS sort_order,
                    " . ($hasIsActiveColumn ? "c.is_active" : "1") . " AS is_active,
                    " . ($hasShowInMenuColumn ? "c.show_in_menu" : "1") . " AS show_in_menu,
                    (SELECT COUNT(*) FROM meals WHERE category_id = c.id) AS meal_count
                FROM categories c
                WHERE c.id = ?
                LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $category = $stmt->get_result()->fetch_assoc();

        if (!$category) {
            echo json_encode(['error' => 'Category not found']);
            exit();
        }

        echo json_encode($category);
        exit();
    }

    if ($apiAction === 'get_category_meals') {
        header('Content-Type: application/json');
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode([]);
            exit();
        }

        $sql = "SELECT id, name, price, is_available
                FROM meals
                WHERE category_id = ?
                ORDER BY name ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $meals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode($meals);
        exit();
    }

    if ($apiAction === 'get_stats') {
        header('Content-Type: application/json');

        $distributionSql = "SELECT c.id, c.name, COUNT(m.id) AS meal_count
                            FROM categories c
                            LEFT JOIN meals m ON m.category_id = c.id
                            GROUP BY c.id, c.name
                            ORDER BY c.name ASC";
        $distributionStmt = $db->prepare($distributionSql);
        $distributionStmt->execute();
        $distributionRows = $distributionStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $topSql = "SELECT c.id, c.name, COUNT(m.id) AS meal_count
                   FROM categories c
                   LEFT JOIN meals m ON m.category_id = c.id
                   GROUP BY c.id, c.name
                   ORDER BY meal_count DESC, c.name ASC
                   LIMIT 5";
        $topStmt = $db->prepare($topSql);
        $topStmt->execute();
        $topRows = $topStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'category_distribution' => [
                'labels' => array_map(function ($row) { return $row['name']; }, $distributionRows),
                'values' => array_map(function ($row) { return (int) $row['meal_count']; }, $distributionRows),
            ],
            'top_categories' => $topRows
        ]);
        exit();
    }

    if ($apiAction === 'export') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=categories_export_' . date('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Name', 'Description', 'Parent ID', 'Sort Order', 'Is Active', 'Show In Menu', 'Meal Count']);

        $exportSql = "SELECT
                        c.id,
                        c.name,
                        c.description,
                        " . ($hasParentIdColumn ? "c.parent_id" : "NULL") . " AS parent_id,
                        " . ($hasSortOrderColumn ? "c.sort_order" : "0") . " AS sort_order,
                        " . ($hasIsActiveColumn ? "c.is_active" : "1") . " AS is_active,
                        " . ($hasShowInMenuColumn ? "c.show_in_menu" : "1") . " AS show_in_menu,
                        (SELECT COUNT(*) FROM meals WHERE category_id = c.id) AS meal_count
                      FROM categories c
                      ORDER BY " . ($hasSortOrderColumn ? "sort_order, " : "") . "name";
        $exportStmt = $db->prepare($exportSql);
        $exportStmt->execute();
        $rows = $exportStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['name'],
                $row['description'],
                $row['parent_id'],
                $row['sort_order'],
                $row['is_active'],
                $row['show_in_menu'],
                $row['meal_count']
            ]);
        }
        fclose($out);
        exit();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_category':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $parent_id = $_POST['parent_id'] ?? null;
            if ($parent_id === '' || $parent_id === '0') {
                $parent_id = null;
            }
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $show_in_menu = isset($_POST['show_in_menu']) ? 1 : 0;
            $sort_order = $_POST['sort_order'] ?? 0;
            
            // Handle image upload
            $image = '';
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
                $max_size = 200 * 1024 * 1024; // 2MB
                
                $file_type = $_FILES['image']['type'];
                $file_size = $_FILES['image']['size'];
                
                if (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
                    $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $filename = 'category_' . time() . '_' . uniqid() . '.' . $extension;
                    $upload_path = 'assets/uploads/categories/' . $filename;
                    
                    // Create directory if it doesn't exist
                    if (!file_exists('assets/uploads/categories/')) {
                        mkdir('assets/uploads/categories/', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        $image = 'assets/uploads/categories/' . $filename;
                    }
                }
            }
            
            $insertColumns = ['name', 'description', 'image'];
            $insertValues = [$name, $description, $image];
            $insertTypes = "sss";

            if ($hasParentIdColumn) {
                $insertColumns[] = 'parent_id';
                $insertValues[] = $parent_id;
                $insertTypes .= "i";
            }
            if ($hasIsActiveColumn) {
                $insertColumns[] = 'is_active';
                $insertValues[] = $is_active;
                $insertTypes .= "i";
            }
            if ($hasShowInMenuColumn) {
                $insertColumns[] = 'show_in_menu';
                $insertValues[] = $show_in_menu;
                $insertTypes .= "i";
            }
            if ($hasSortOrderColumn) {
                $insertColumns[] = 'sort_order';
                $insertValues[] = (int) $sort_order;
                $insertTypes .= "i";
            }

            $sql = "INSERT INTO categories (" . implode(', ', $insertColumns) . ")
                    VALUES (" . implode(', ', array_fill(0, count($insertColumns), '?')) . ")";

            $stmt = $db->prepare($sql);
            $stmt->bind_param($insertTypes, ...$insertValues);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Category added successfully';
                header('Location: manage_categories.php');
                exit();
            }
            break;
            
        case 'update_category':
            $category_id = $_POST['category_id'] ?? 0;
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $parent_id = $_POST['parent_id'] ?? null;
            if ($parent_id === '' || $parent_id === '0') {
                $parent_id = null;
            }
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $show_in_menu = isset($_POST['show_in_menu']) ? 1 : 0;
            $sort_order = $_POST['sort_order'] ?? 0;
            
            // Handle image upload if new image provided
            $image = $_POST['current_image'] ?? '';
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $max_size = 2 * 1024 * 1024; // 2MB
                
                $file_type = $_FILES['image']['type'];
                $file_size = $_FILES['image']['size'];
                
                if (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
                    $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $filename = 'category_' . time() . '_' . uniqid() . '.' . $extension;
                    $upload_path = '../assets/uploads/categories/' . $filename;
                    
                    // Create directory if it doesn't exist
                    if (!file_exists('../assets/uploads/categories/')) {
                        mkdir('../assets/uploads/categories/', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        // Delete old image if exists
                        if (!empty($image) && file_exists('../' . $image)) {
                            unlink('../' . $image);
                        }
                        $image = 'assets/uploads/categories/' . $filename;
                    }
                }
            }
            
            $setParts = ["name = ?", "description = ?", "image = ?"];
            $setValues = [$name, $description, $image];
            $setTypes = "sss";

            if ($hasParentIdColumn) {
                $setParts[] = "parent_id = ?";
                $setValues[] = $parent_id;
                $setTypes .= "i";
            }
            if ($hasIsActiveColumn) {
                $setParts[] = "is_active = ?";
                $setValues[] = $is_active;
                $setTypes .= "i";
            }
            if ($hasShowInMenuColumn) {
                $setParts[] = "show_in_menu = ?";
                $setValues[] = $show_in_menu;
                $setTypes .= "i";
            }
            if ($hasSortOrderColumn) {
                $setParts[] = "sort_order = ?";
                $setValues[] = (int) $sort_order;
                $setTypes .= "i";
            }
            if ($hasUpdatedAtColumn) {
                $setParts[] = "updated_at = NOW()";
            }

            $sql = "UPDATE categories SET " . implode(', ', $setParts) . " WHERE id = ?";
            $setValues[] = (int) $category_id;
            $setTypes .= "i";

            $stmt = $db->prepare($sql);
            $stmt->bind_param($setTypes, ...$setValues);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Category updated successfully';
                header('Location: manage_categories.php');
                exit();
            }
            break;
            
        case 'delete_category':
            $category_id = $_POST['category_id'] ?? 0;
            
            // Check if category has subcategories
            $result = ['subcount' => 0];
            if ($hasParentIdColumn) {
                $check_sql = "SELECT COUNT(*) as subcount FROM categories WHERE parent_id = ?";
                $check_stmt = $db->prepare($check_sql);
                $check_stmt->bind_param("i", $category_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result()->fetch_assoc();
            }
            
            if ($result['subcount'] > 0) {
                $_SESSION['error_message'] = 'Cannot delete category with subcategories';
            } else {
                // Check if category has meals
                $meal_sql = "SELECT COUNT(*) as mealcount FROM meals WHERE category_id = ?";
                $meal_stmt = $db->prepare($meal_sql);
                $meal_stmt->bind_param("i", $category_id);
                $meal_stmt->execute();
                $meal_result = $meal_stmt->get_result()->fetch_assoc();
                
                if ($meal_result['mealcount'] > 0) {
                    $_SESSION['error_message'] = 'Cannot delete category with meals';
                } else {
                    // Get image before deletion
                    $img_sql = "SELECT image FROM categories WHERE id = ?";
                    $img_stmt = $db->prepare($img_sql);
                    $img_stmt->bind_param("i", $category_id);
                    $img_stmt->execute();
                    $img_result = $img_stmt->get_result();
                    
                    if ($row = $img_result->fetch_assoc()) {
                        // Delete image file if exists
                        if (!empty($row['image']) && file_exists('../' . $row['image'])) {
                            unlink('../' . $row['image']);
                        }
                    }
                    
                    // Delete category
                    $delete_sql = "DELETE FROM categories WHERE id = ?";
                    $delete_stmt = $db->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $category_id);
                    $delete_stmt->execute();
                    
                    $_SESSION['success_message'] = 'Category deleted successfully';
                }
            }
            
            header('Location: manage_categories.php');
            exit();
            break;
            
        case 'reorder_categories':
            $order_data = $_POST['order'] ?? '[]';
            $order_array = json_decode($order_data, true);
            if (!is_array($order_array)) {
                $order_array = $_POST['order'] ?? [];
            }
            
            foreach ($order_array as $item) {
                $sql = "UPDATE categories SET ";
                $types = "";
                $values = [];

                if ($hasSortOrderColumn) {
                    $sql .= "sort_order = ?";
                    $types .= "i";
                    $values[] = (int) ($item['sort_order'] ?? 0);
                }

                if ($hasParentIdColumn) {
                    if ($hasSortOrderColumn) {
                        $sql .= ", ";
                    }
                    $sql .= "parent_id = ?";
                    $types .= "i";
                    $values[] = isset($item['parent_id']) ? (int) $item['parent_id'] : null;
                }

                if ($types === "") {
                    continue;
                }

                $sql .= " WHERE id = ?";
                $types .= "i";
                $values[] = (int) $item['id'];

                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
            }
            
            echo json_encode(['success' => true]);
            exit();
            break;

        case 'update_sort_order':
            $id = (int) ($_POST['category_id'] ?? 0);
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);

            if ($id <= 0 || !$hasSortOrderColumn) {
                echo json_encode(['success' => false, 'message' => 'Invalid request']);
                exit();
            }

            $stmt = $db->prepare("UPDATE categories SET sort_order = ? WHERE id = ?");
            $stmt->bind_param("ii", $sortOrder, $id);
            $ok = $stmt->execute();

            echo json_encode(['success' => (bool) $ok]);
            exit();
            break;
    }
}

// Get all categories for tree view
function getCategoriesTree($parent_id = null, $level = 0) {
    global $db, $hasParentIdColumn, $hasSortOrderColumn, $hasIsActiveColumn, $hasShowInMenuColumn;

    $sql = "SELECT c.*,
            " . ($hasParentIdColumn ? "c.parent_id" : "NULL") . " AS parent_id,
            " . ($hasIsActiveColumn ? "c.is_active" : "1") . " AS is_active,
            " . ($hasShowInMenuColumn ? "c.show_in_menu" : "1") . " AS show_in_menu,
            " . ($hasSortOrderColumn ? "c.sort_order" : "0") . " AS sort_order,
            (SELECT COUNT(*) FROM meals WHERE category_id = c.id) as meal_count,
            " . ($hasParentIdColumn ? "(SELECT COUNT(*) FROM categories WHERE parent_id = c.id)" : "0") . " as subcategory_count
            FROM categories c";

    if ($hasParentIdColumn) {
        $sql .= " WHERE c.parent_id " . ($parent_id === null ? "IS NULL" : "= ?");
    } elseif ($parent_id !== null) {
        return [];
    }

    $sql .= " ORDER BY " . ($hasSortOrderColumn ? "sort_order, " : "") . "name";

    $stmt = $db->prepare($sql);
    if ($hasParentIdColumn && $parent_id !== null) {
        $stmt->bind_param("i", $parent_id);
    }
    $stmt->execute();
    $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $result = [];
    foreach ($categories as $category) {
        $category['level'] = $level;
        $category['children'] = $hasParentIdColumn ? getCategoriesTree($category['id'], $level + 1) : [];
        $result[] = $category;
    }
    
    return $result;
}

$categories_tree = getCategoriesTree();

// Get category statistics
$stats_sql = "SELECT
                COUNT(*) as total_categories,
                " . ($hasIsActiveColumn ? "SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END)" : "COUNT(*)") . " as active_categories,
                " . ($hasShowInMenuColumn ? "SUM(CASE WHEN show_in_menu = 1 THEN 1 ELSE 0 END)" : "COUNT(*)") . " as menu_categories,
                " . ($hasParentIdColumn ? "SUM(CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END)" : "COUNT(*)") . " as main_categories,
                (SELECT COUNT(*) FROM meals) as total_meals
              FROM categories";
$stats_stmt = $db->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.3.12/themes/default/style.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Dropify/0.2.2/css/dropify.min.css">
</head>
<body>
    <!-- Admin Navbar -->
    <?php include 'includes/admin_navbar.php'; ?>

    <div class="admin-container">
        <!-- Main Content -->
        <main class="admin-main">
            <header class="admin-header">
                <div class="header-left">
                    <h1><i class="fas fa-tags"></i> Category Management</h1>
                    <p class="page-description">Organize your menu with categories and subcategories</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-primary" onclick="showCategoryModal('add')">
                        <i class="fas fa-plus-circle"></i> Add Category
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="toggleView()" id="toggleViewBtn">
                        <i class="fas fa-list"></i> List View
                    </button>
                    <button type="button" class="btn btn-info" onclick="exportCategories()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </header>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                    <?php unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #667eea;">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_categories'] ?? 0; ?></h3>
                        <p>Total Categories</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #4CAF50;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['active_categories'] ?? 0; ?></h3>
                        <p>Active</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #FF9800;">
                        <i class="fas fa-bars"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['menu_categories'] ?? 0; ?></h3>
                        <p>In Menu</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9C27B0;">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['main_categories'] ?? 0; ?></h3>
                        <p>Main Categories</p>
                    </div>
                </div>
            </div>

            <!-- Tree View -->
            <div class="admin-card" id="treeViewCard">
                <div class="card-header">
                    <h3>Category Hierarchy</h3>
                    <div class="card-actions">
                        <button type="button" class="btn-action" onclick="expandAll()">
                            <i class="fas fa-expand"></i> Expand All
                        </button>
                        <button type="button" class="btn-action" onclick="collapseAll()">
                            <i class="fas fa-compress"></i> Collapse All
                        </button>
                        <button type="button" class="btn-action" onclick="saveCategoryOrder()">
                            <i class="fas fa-save"></i> Save Order
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="category-tree" id="categoryTree">
                        <!-- Tree will be loaded via JavaScript -->
                    </div>
                </div>
            </div>

            <!-- List View -->
            <div class="admin-card" id="listViewCard" style="display: none;">
                <div class="card-header">
                    <h3>Categories List</h3>
                    <div class="card-actions">
                        <button type="button" class="btn-action" onclick="refreshList()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <button type="button" class="btn-action" onclick="printList()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="admin-table" id="categoriesTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Category</th>
                                    <th>Meals</th>
                                    <th>Subcategories</th>
                                    <th>Status</th>
                                    <th>Menu</th>
                                    <th>Order</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                function renderCategoryList($categories, $level = 0) {
                                    foreach ($categories as $category): 
                                        $padding = $level * 20;
                                ?>
                                    <tr data-category-id="<?php echo $category['id']; ?>">
                                        <td>#<?php echo $category['id']; ?></td>
                                        <td>
                                            <div class="category-info" style="padding-left: <?php echo $padding; ?>px;">
                                                <?php if ($level > 0): ?>
                                                    <i class="fas fa-level-up-alt fa-rotate-90" style="margin-right: 5px;"></i>
                                                <?php endif; ?>
                                                <?php if ($category['image']): ?>
                                                    <div class="category-image">
                                                        <img src="../<?php echo htmlspecialchars($category['image']); ?>" 
                                                             alt="<?php echo htmlspecialchars($category['name']); ?>">
                                                    </div>
                                                <?php endif; ?>
                                                <div class="category-details">
                                                    <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                                                    <small><?php echo htmlspecialchars(substr($category['description'], 0, 60)); ?>...</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge meal-count"><?php echo $category['meal_count']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge subcategory-count"><?php echo $category['subcategory_count']; ?></span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $category['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge menu-badge <?php echo $category['show_in_menu'] ? 'in-menu' : 'not-in-menu'; ?>">
                                                <?php echo $category['show_in_menu'] ? 'In Menu' : 'Hidden'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   class="sort-input" 
                                                   value="<?php echo $category['sort_order']; ?>" 
                                                   data-id="<?php echo $category['id']; ?>"
                                                   min="0" 
                                                   style="width: 60px;">
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" 
                                                        class="btn-action btn-view" 
                                                        onclick="viewCategory(<?php echo $category['id']; ?>)"
                                                        title="View">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button type="button" 
                                                        class="btn-action btn-edit" 
                                                        onclick="editCategory(<?php echo $category['id']; ?>)"
                                                        title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <button type="button" 
                                                        class="btn-action btn-add-child" 
                                                        onclick="addChildCategory(<?php echo $category['id']; ?>)"
                                                        title="Add Subcategory">
                                                    <i class="fas fa-plus-square"></i>
                                                </button>
                                                
                                                <button type="button" 
                                                        class="btn-action btn-delete" 
                                                        onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo addslashes($category['name']); ?>')"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php 
                                    // Render child categories recursively
                                    if (!empty($category['children'])) {
                                        renderCategoryList($category['children'], $level + 1);
                                    }
                                    ?>
                                <?php endforeach; 
                                } 
                                
                                renderCategoryList($categories_tree);
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Category Stats -->
            <div class="category-stats">
                <div class="stat-box">
                    <h4><i class="fas fa-chart-pie"></i> Category Distribution</h4>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
                
                <div class="stat-box">
                    <h4><i class="fas fa-star"></i> Top Categories</h4>
                    <div class="top-categories" id="topCategories">
                        <!-- Will be loaded via JavaScript -->
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Category Modal (Add/Edit) -->
    <div class="modal" id="categoryModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add New Category</h2>
                <button type="button" onclick="closeCategoryModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="categoryForm" enctype="multipart/form-data">
                    <input type="hidden" id="actionType" name="action" value="add_category">
                    <input type="hidden" id="categoryId" name="category_id" value="">
                    <input type="hidden" id="currentImage" name="current_image" value="">
                    
                    <div class="form-group">
                        <label for="name">Category Name *</label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="parent_id">Parent Category</label>
                            <select id="parent_id" name="parent_id" class="form-control">
                                <option value="">-- No Parent (Main Category) --</option>
                                <?php 
                                function renderCategoryOptions($categories, $level = 0, $exclude_id = null) {
                                    foreach ($categories as $category): 
                                        if ($category['id'] == $exclude_id) continue;
                                        $prefix = str_repeat('— ', $level);
                                ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo $prefix . htmlspecialchars($category['name']); ?>
                                    </option>
                                    <?php renderCategoryOptions($category['children'], $level + 1, $exclude_id); ?>
                                <?php endforeach; 
                                }
                                
                                renderCategoryOptions($categories_tree);
                                ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="sort_order">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" class="form-control" min="0" value="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="image">Category Image</label>
                        <input type="file" id="image" name="image" class="dropify" data-height="150">
                        <small class="form-text">Recommended size: 400x300px, Max: 2MB</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                                <span>Active</span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="show_in_menu" name="show_in_menu" value="1" checked>
                                <span>Show in Menu</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCategoryModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Category
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
                    <p>Are you sure you want to delete <strong id="deleteCategoryName"></strong>?</p>
                    <p class="text-danger">This action cannot be undone!</p>
                </div>
                
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" id="deleteCategoryId" name="category_id" value="">
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Category
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.3.12/jstree.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Dropify/0.2.2/js/dropify.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Tree view state
        let isTreeView = true;
        let treeData = <?php echo json_encode($categories_tree); ?>;

        // Initialize dropify for image upload
        $('.dropify').dropify({
            messages: {
                'default': 'Drag and drop an image here or click',
                'replace': 'Drag and drop or click to replace',
                'remove': 'Remove',
                'error': 'Oops, something wrong happened.'
            }
        });

        // Toggle between tree and list view
        function toggleView() {
            isTreeView = !isTreeView;
            const treeViewCard = document.getElementById('treeViewCard');
            const listViewCard = document.getElementById('listViewCard');
            const toggleBtn = document.getElementById('toggleViewBtn');
            
            if (isTreeView) {
                treeViewCard.style.display = 'block';
                listViewCard.style.display = 'none';
                toggleBtn.innerHTML = '<i class="fas fa-list"></i> List View';
            } else {
                treeViewCard.style.display = 'none';
                listViewCard.style.display = 'block';
                toggleBtn.innerHTML = '<i class="fas fa-sitemap"></i> Tree View';
            }
        }

        // Initialize tree view
        function initCategoryTree() {
            const treeData = formatTreeData(<?php echo json_encode($categories_tree); ?>);
            
            $('#categoryTree').jstree({
                'core': {
                    'data': treeData,
                    'check_callback': true,
                    'themes': {
                        'responsive': false,
                        'variant': 'large',
                        'stripes': true
                    }
                },
                'types': {
                    'default': {
                        'icon': 'fas fa-folder'
                    },
                    'active': {
                        'icon': 'fas fa-folder text-success'
                    },
                    'inactive': {
                        'icon': 'fas fa-folder text-muted'
                    }
                },
                'plugins': ['types', 'dnd', 'contextmenu', 'state']
            }).on('ready.jstree', function() {
                $(this).jstree('open_all');
            }).on('move_node.jstree', function(e, data) {
                // Handle node movement
                console.log('Node moved:', data);
            });
        }

        // Format data for jstree
        function formatTreeData(categories) {
            return categories.map(category => {
                const node = {
                    id: category.id.toString(),
                    text: `${category.name} <span class="badge badge-info ml-2">${category.meal_count} meals</span>`,
                    icon: category.is_active ? 'fas fa-folder text-success' : 'fas fa-folder text-muted',
                    state: {
                        opened: true,
                        selected: false
                    },
                    data: category
                };
                
                if (category.children && category.children.length > 0) {
                    node.children = formatTreeData(category.children);
                }
                
                return node;
            });
        }

        // Expand all nodes
        function expandAll() {
            $('#categoryTree').jstree('open_all');
        }

        // Collapse all nodes
        function collapseAll() {
            $('#categoryTree').jstree('close_all');
        }

        // Save category order
        function saveCategoryOrder() {
            const tree = $('#categoryTree').jstree(true);
            const nodes = tree.get_json('#', {flat: true});
            const orderData = nodes.map(node => ({
                id: parseInt(node.id),
                parent_id: node.parent === '#' ? null : parseInt(node.parent),
                sort_order: node.data.sort_order || 0
            }));
            
            fetch('manage_categories.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'reorder_categories',
                    order: orderData
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Category order saved successfully', 'success');
                } else {
                    showNotification('Failed to save category order', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        }

        // Show category modal
        function showCategoryModal(action, parentId = null) {
            if (action === 'add') {
                document.getElementById('modalTitle').textContent = 'Add New Category';
                document.getElementById('actionType').value = 'add_category';
                document.getElementById('categoryId').value = '';
                document.getElementById('currentImage').value = '';
                document.getElementById('categoryForm').reset();
                
                // Set parent ID if provided
                if (parentId) {
                    document.getElementById('parent_id').value = parentId;
                } else {
                    document.getElementById('parent_id').value = '';
                }
                
                // Reset dropify
                $('.dropify').dropify();
            }
            
            document.getElementById('categoryModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Close category modal
        function closeCategoryModal() {
            document.getElementById('categoryModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Edit category
        async function editCategory(categoryId) {
            try {
                const response = await fetch(`manage_categories.php?action=get_category&id=${categoryId}`);
                const category = await response.json();
                
                document.getElementById('modalTitle').textContent = 'Edit Category';
                document.getElementById('actionType').value = 'update_category';
                document.getElementById('categoryId').value = category.id;
                document.getElementById('currentImage').value = category.image || '';
                document.getElementById('name').value = category.name;
                document.getElementById('description').value = category.description;
                document.getElementById('parent_id').value = category.parent_id || '';
                document.getElementById('sort_order').value = category.sort_order;
                document.getElementById('is_active').checked = category.is_active == 1;
                document.getElementById('show_in_menu').checked = category.show_in_menu == 1;
                
                // Set image preview if exists
                if (category.image) {
                    const dropify = $('.dropify').data('dropify');
                    dropify.resetPreview();
                    dropify.clearElement();
                    dropify.settings.defaultFile = '../' + category.image;
                    dropify.destroy();
                    dropify.init();
                }
                
                document.getElementById('categoryModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            } catch (error) {
                console.error('Error:', error);
                showNotification('Failed to load category data', 'error');
            }
        }

        // Add child category
        function addChildCategory(parentId) {
            showCategoryModal('add', parentId);
        }

        // View category details
        async function viewCategory(categoryId) {
            try {
                const response = await fetch(`manage_categories.php?action=get_category&id=${categoryId}`);
                const category = await response.json();
                
                // Get category meals
                const mealsResponse = await fetch(`manage_categories.php?action=get_category_meals&id=${categoryId}`);
                const meals = await mealsResponse.json();
                
                // Create modal for viewing
                const modalContent = `
                    <div class="category-view-modal">
                        <div class="category-view-header">
                            <h3>${category.name}</h3>
                            <span class="badge">${category.meal_count || 0} meals</span>
                        </div>
                        <div class="category-view-body">
                            ${category.image ? `
                                <div class="category-view-image">
                                    <img src="../${category.image}" alt="${category.name}">
                                </div>
                            ` : ''}
                            
                            <div class="category-view-details">
                                <p><strong>Description:</strong> ${category.description || 'No description'}</p>
                                
                                <div class="category-view-stats">
                                    <div class="stat">
                                        <i class="fas fa-layer-group"></i>
                                        <div>
                                            <small>Type</small>
                                            <strong>${category.parent_id ? 'Subcategory' : 'Main Category'}</strong>
                                        </div>
                                    </div>
                                    <div class="stat">
                                        <i class="fas fa-toggle-${category.is_active ? 'on' : 'off'}"></i>
                                        <div>
                                            <small>Status</small>
                                            <strong>${category.is_active ? 'Active' : 'Inactive'}</strong>
                                        </div>
                                    </div>
                                    <div class="stat">
                                        <i class="fas fa-bars"></i>
                                        <div>
                                            <small>Menu</small>
                                            <strong>${category.show_in_menu ? 'Visible' : 'Hidden'}</strong>
                                        </div>
                                    </div>
                                    <div class="stat">
                                        <i class="fas fa-sort-numeric-down"></i>
                                        <div>
                                            <small>Order</small>
                                            <strong>${category.sort_order}</strong>
                                        </div>
                                    </div>
                                </div>
                                
                                ${meals.length > 0 ? `
                                    <div class="category-meals">
                                        <h4><i class="fas fa-utensils"></i> Meals in this Category</h4>
                                        <div class="meals-list">
                                            ${meals.slice(0, 5).map(meal => `
                                                <div class="meal-item">
                                                    <div class="meal-info">
                                                        <strong>${meal.name}</strong>
                                                        <small>$${meal.price}</small>
                                                    </div>
                                                    <span class="badge ${meal.is_available ? 'available' : 'unavailable'}">
                                                        ${meal.is_available ? 'Available' : 'Unavailable'}
                                                    </span>
                                                </div>
                                            `).join('')}
                                        </div>
                                        ${meals.length > 5 ? `
                                            <p class="text-muted">+ ${meals.length - 5} more meals</p>
                                        ` : ''}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
                
                showCustomModal('Category Details', modalContent);
            } catch (error) {
                console.error('Error:', error);
                showNotification('Failed to load category details', 'error');
            }
        }

        // Delete category confirmation
        function deleteCategory(categoryId, categoryName) {
            document.getElementById('deleteCategoryName').textContent = categoryName;
            document.getElementById('deleteCategoryId').value = categoryId;
            document.getElementById('deleteModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Load category stats
        function loadCategoryStats() {
            fetch('manage_categories.php?action=get_stats')
                .then(response => response.json())
                .then(data => {
                    // Pie chart
                    if (data.category_distribution) {
                        const ctx = document.getElementById('categoryChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'pie',
                            data: {
                                labels: data.category_distribution.labels,
                                datasets: [{
                                    data: data.category_distribution.values,
                                    backgroundColor: [
                                        '#667eea', '#4CAF50', '#FF9800', '#F44336', '#9C27B0',
                                        '#2196F3', '#FFC107', '#795548', '#607D8B', '#E91E63'
                                    ]
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'bottom'
                                    }
                                }
                            }
                        });
                    }
                    
                    // Top categories
                    if (data.top_categories) {
                        const container = document.getElementById('topCategories');
                        container.innerHTML = data.top_categories.map((cat, index) => `
                            <div class="top-category-item">
                                <div class="rank">${index + 1}</div>
                                <div class="category-info">
                                    <strong>${cat.name}</strong>
                                    <small>${cat.meal_count} meals</small>
                                </div>
                                <div class="category-actions">
                                    <button class="btn-action btn-view" onclick="viewCategory(${cat.id})">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        `).join('');
                    }
                })
                .catch(error => console.error('Error loading category stats:', error));
        }

        // Export categories
        function exportCategories() {
            window.location.href = 'manage_categories.php?action=export';
        }

        // Refresh list
        function refreshList() {
            window.location.reload();
        }

        // Print list
        function printList() {
            window.print();
        }

        // Update sort order
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('sort-input')) {
                const categoryId = e.target.dataset.id;
                const sortOrder = e.target.value;
                
                // Save sort order immediately
                fetch('manage_categories.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'update_sort_order',
                        category_id: categoryId,
                        sort_order: sortOrder
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Sort order updated', 'success');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Failed to update sort order', 'error');
                });
            }
        });

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

        // Close modals when clicking outside
        window.onclick = function(event) {
            const categoryModal = document.getElementById('categoryModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target === categoryModal) {
                closeCategoryModal();
            }
            
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        };

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tree view
            initCategoryTree();
            
            // Load category stats
            loadCategoryStats();
            
            // Set up context menu for tree
            setupContextMenu();
        });

        // Setup context menu for tree
        function setupContextMenu() {
            // This would be implemented with jstree contextmenu plugin
            // For simplicity, we'll add click handlers to the tree nodes
            $('#categoryTree').on('select_node.jstree', function(e, data) {
                const node = data.node;
                const categoryId = node.id;
                
                // Create context menu
                const menu = document.createElement('div');
                menu.className = 'context-menu';
                menu.innerHTML = `
                    <button onclick="viewCategory(${categoryId})">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <button onclick="editCategory(${categoryId})">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <button onclick="addChildCategory(${categoryId})">
                        <i class="fas fa-plus-square"></i> Add Subcategory
                    </button>
                    <hr>
                    <button onclick="deleteCategory(${categoryId}, '${node.text.replace(/<[^>]*>/g, '')}')" class="text-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                `;
                
                // Position menu near mouse click
                menu.style.position = 'absolute';
                menu.style.left = e.clientX + 'px';
                menu.style.top = e.clientY + 'px';
                document.body.appendChild(menu);
                
                // Remove menu on click outside
                setTimeout(() => {
                    document.addEventListener('click', function removeMenu() {
                        menu.remove();
                        document.removeEventListener('click', removeMenu);
                    });
                }, 100);
            });
        }
    </script>

    <style>
        /* Additional Styles for Category Management */
        .category-tree {
            min-height: 400px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            background: #f7fafc;
        }
        
        .category-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .category-image {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .category-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .category-details {
            flex: 1;
            min-width: 0;
        }
        
        .category-details strong {
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .category-details small {
            color: #718096;
            font-size: 0.85rem;
            line-height: 1.4;
            display: block;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .badge.meal-count {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .badge.subcategory-count {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge.menu-badge {
            background: #fed7d7;
            color: #c53030;
        }
        
        .badge.menu-badge.in-menu {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge.available {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge.unavailable {
            background: #fed7d7;
            color: #c53030;
        }
        
        .status-badge {
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .status-inactive {
            background: #fed7d7;
            color: #c53030;
        }
        
        .sort-input {
            width: 60px;
            padding: 0.25rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            text-align: center;
        }
        
        .category-stats {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .stat-box {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-box h4 {
            margin-bottom: 1rem;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .chart-container {
            position: relative;
            height: 250px;
        }
        
        .top-categories {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .top-category-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        .top-category-item .rank {
            width: 30px;
            height: 30px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .top-category-item .category-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .category-view-modal {
            max-width: 600px;
        }
        
        .category-view-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .category-view-image {
            width: 100%;
            height: 200px;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        
        .category-view-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .category-view-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .category-view-stats .stat {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        .category-view-stats .stat i {
            font-size: 1.5rem;
            color: #667eea;
        }
        
        .category-view-stats .stat div {
            display: flex;
            flex-direction: column;
        }
        
        .category-view-stats .stat small {
            color: #718096;
            font-size: 0.8rem;
        }
        
        .category-meals {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .category-meals h4 {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .meals-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .meal-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #f7fafc;
            border-radius: 6px;
        }
        
        .meal-item .meal-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .meal-item .meal-info small {
            color: #718096;
        }
        
        .context-menu {
            background: white;
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            padding: 0.5rem;
            min-width: 200px;
            z-index: 1000;
        }
        
        .context-menu button {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            padding: 0.5rem 1rem;
            background: none;
            border: none;
            text-align: left;
            cursor: pointer;
            color: #4a5568;
            border-radius: 4px;
            transition: background 0.3s;
        }
        
        .context-menu button:hover {
            background: #f7fafc;
        }
        
        .context-menu hr {
            margin: 0.5rem 0;
            border: none;
            border-top: 1px solid #e2e8f0;
        }
        
        .context-menu button.text-danger {
            color: #c53030;
        }
        
        @media (max-width: 1200px) {
            .category-stats {
                grid-template-columns: 1fr;
            }
            
            .category-view-stats {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .category-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .category-image {
                width: 40px;
                height: 40px;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
        }
        
        @media (max-width: 576px) {
            .category-view-stats .stat {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
        }
    </style>
</body>
</html>
