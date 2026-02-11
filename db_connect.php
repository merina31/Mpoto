<?php
require_once 'config.php';

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($this->connection->connect_error) {
            die("Connection failed: " . $this->connection->connect_error);
        }
        
        $this->connection->set_charset("utf8mb4");
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Prepared statement helper exposed for callers like Auth
    public function prepare($sql) {
        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Database prepare failed: " . $this->connection->error);
        }
        return $stmt;
    }
    
    public function query($sql, $params = [], $types = '') {
        $stmt = $this->prepare($sql);
        
        if (!empty($params)) {
            if (empty($types)) {
                $types = str_repeat('s', count($params));
            }
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        return $stmt;
    }
    
    public function getLastInsertId() {
        return $this->connection->insert_id;
    }
    
    public function escapeString($string) {
        return $this->connection->real_escape_string($string);
    }
}

// Create global database instance
$db = Database::getInstance();
$conn = $db->getConnection();
