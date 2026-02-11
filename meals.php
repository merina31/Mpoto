<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_popular':
        getPopularMeals();
        break;
    case 'get_by_category':
        getMealsByCategory();
        break;
    case 'search':
        searchMeals();
        break;
    case 'get_details':
        getMealDetails();
        break;
    case 'add_meal':
        addMeal();
        break;
    case 'update_meal':
        updateMeal();
        break;
    case 'delete_meal':
        deleteMeal();
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}

function getPopularMeals() {
    global $db;
    
    $sql = "SELECT m.*, c.name as category_name 
            FROM meals m 
            LEFT JOIN categories c ON m.category_id = c.id 
            WHERE m.is_available = 1 
            ORDER BY m.total_orders DESC 
            LIMIT 8";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $meals = [];
    while ($row = $result->fetch_assoc()) {
        $meals[] = $row;
    }
    
    echo json_encode($meals);
}

function getMealsByCategory() {
    global $db;
    
    $category_id = $_GET['category_id'] ?? 0;
    
    $sql = "SELECT m.*, c.name as category_name 
            FROM meals m 
            LEFT JOIN categories c ON m.category_id = c.id 
            WHERE m.is_available = 1 
            AND (m.category_id = ? OR ? = 0)
            ORDER BY m.name";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ii", $category_id, $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $meals = [];
    while ($row = $result->fetch_assoc()) {
        $meals[] = $row;
    }
    
    echo json_encode($meals);
}

function searchMeals() {
    global $db;
    
    $search = $_GET['q'] ?? '';
    $search = "%" . $db->escapeString($search) . "%";
    
    $sql = "SELECT m.*, c.name as category_name 
            FROM meals m 
            LEFT JOIN categories c ON m.category_id = c.id 
            WHERE m.is_available = 1 
            AND (m.name LIKE ? OR m.description LIKE ? OR m.ingredients LIKE ?)
            ORDER BY m.name";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $meals = [];
    while ($row = $result->fetch_assoc()) {
        $meals[] = $row;
    }
    
    echo json_encode($meals);
}

function getMealDetails() {
    global $db;
    
    $meal_id = $_GET['meal_id'] ?? 0;
    
    $sql = "SELECT m.*, c.name as category_name 
            FROM meals m 
            LEFT JOIN categories c ON m.category_id = c.id 
            WHERE m.id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $meal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Get reviews
        $review_sql = "SELECT r.*, u.username, u.full_name 
                      FROM reviews r 
                      JOIN users u ON r.user_id = u.id 
                      WHERE r.meal_id = ? AND r.is_approved = 1 
                      ORDER BY r.created_at DESC";
        
        $review_stmt = $db->prepare($review_sql);
        $review_stmt->bind_param("i", $meal_id);
        $review_stmt->execute();
        $reviews = $review_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $row['reviews'] = $reviews;
        
        echo json_encode(['success' => true, 'meal' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Meal not found']);
    }
}

function addMeal() {
    global $db;
    
    // Check admin authentication
    session_start();
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $sql = "INSERT INTO meals (name, description, category_id, price, discount_price, 
            image_url, ingredients, preparation_time, is_vegetarian, is_spicy, is_available) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ssiddssiiii", 
        $data['name'],
        $data['description'],
        $data['category_id'],
        $data['price'],
        $data['discount_price'],
        $data['image_url'],
        $data['ingredients'],
        $data['preparation_time'],
        $data['is_vegetarian'],
        $data['is_spicy'],
        $data['is_available']
    );
    
    if ($stmt->execute()) {
        // Log admin action
        logAdminAction('add_meal', "Added meal: {$data['name']}");
        
        echo json_encode(['success' => true, 'meal_id' => $db->getLastInsertId()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add meal']);
    }
}

function updateMeal() {
    global $db;
    
    // Check admin authentication
    session_start();
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $sql = "UPDATE meals SET 
            name = ?, description = ?, category_id = ?, price = ?, discount_price = ?, 
            image_url = ?, ingredients = ?, preparation_time = ?, is_vegetarian = ?, 
            is_spicy = ?, is_available = ?, updated_at = NOW() 
            WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ssiddssiiiii", 
        $data['name'],
        $data['description'],
        $data['category_id'],
        $data['price'],
        $data['discount_price'],
        $data['image_url'],
        $data['ingredients'],
        $data['preparation_time'],
        $data['is_vegetarian'],
        $data['is_spicy'],
        $data['is_available'],
        $data['meal_id']
    );
    
    if ($stmt->execute()) {
        // Log admin action
        logAdminAction('update_meal', "Updated meal ID: {$data['meal_id']}");
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update meal']);
    }
}

function deleteMeal() {
    global $db;
    
    // Check admin authentication
    session_start();
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        return;
    }
    
    $meal_id = $_GET['meal_id'] ?? 0;
    
    $sql = "DELETE FROM meals WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $meal_id);
    
    if ($stmt->execute()) {
        // Log admin action
        logAdminAction('delete_meal', "Deleted meal ID: {$meal_id}");
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete meal']);
    }
}

function logAdminAction($action, $details) {
    global $db;
    
    $admin_id = $_SESSION['user_id'] ?? 0;
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    
    $sql = "INSERT INTO admin_logs (admin_id, action, details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("issss", $admin_id, $action, $details, $ip_address, $user_agent);
    $stmt->execute();
}