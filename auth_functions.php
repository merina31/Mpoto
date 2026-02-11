<?php
require_once 'db_connect.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function register($data) {
        // Validate input
        $errors = $this->validateRegistration($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        // Hash password
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
        
        // Set role (default to 'user', allow 'admin' only if explicitly set)
        $role = isset($data['role']) && $data['role'] === 'admin' ? 'admin' : 'user';
        
        // Prepare SQL with prepared statement
        $sql = "INSERT INTO users (username, email, password, full_name, phone, address, role, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("sssssss", 
            $data['username'],
            $data['email'],
            $hashedPassword,
            $data['full_name'],
            $data['phone'],
            $data['address'],
            $role
        );
        
        if ($stmt->execute()) {
            $user_id = $this->db->getLastInsertId();
            
            // Start session
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $data['username'];
            $_SESSION['email'] = $data['email'];
            $_SESSION['role'] = $role;
            $_SESSION['full_name'] = $data['full_name'];
            
            return ['success' => true, 'user_id' => $user_id, 'role' => $role];
        }
        
        return ['success' => false, 'errors' => ['general' => 'Registration failed. Please try again.']];
    }
    
    public function login($username, $password) {
        // Prepare SQL with prepared statement
        $sql = "SELECT id, username, email, password, role, full_name, profile_image FROM users 
                WHERE (username = ? OR email = ?) AND role IN ('user', 'admin')";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                // Start session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['profile_image'] = $user['profile_image'] ?? '';
                
                // Update last login
                $this->updateLastLogin($user['id']);
                
                return ['success' => true, 'role' => $user['role']];
            }
        }
        
        return ['success' => false, 'errors' => ['general' => 'Invalid username/email or password']];
    }
    
    private function validateRegistration($data) {
        $errors = [];
        
        // Check if username exists
        $sql = "SELECT id FROM users WHERE username = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $data['username']);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $errors['username'] = 'Username already exists';
        }
        
        // Check if email exists
        $sql = "SELECT id FROM users WHERE email = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $data['email']);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $errors['email'] = 'Email already registered';
        }
        
        // Validate password strength
        if (strlen($data['password']) < 6) {
            $errors['password'] = 'Password must be at least 6 characters';
        }
        
        if ($data['password'] !== $data['confirm_password']) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
        
        return $errors;
    }
    
    private function updateLastLogin($user_id) {
        // You can implement last login tracking here
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
    }
    
    public function requireAdmin() {
        $this->requireLogin();
        
        if (!$this->isAdmin()) {
            header('Location: index.php');
            exit();
        }
    }
    
    public function logout() {
        session_destroy();
        header('Location: index.php');
        exit();
    }
}

$auth = new Auth();
