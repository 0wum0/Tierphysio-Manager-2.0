<?php
/**
 * Simple Auth for Tierphysio Manager 2.0
 * Simplified authentication without external dependencies
 */

namespace TierphysioManager;

class SimpleAuth {
    private static $instance = null;
    private $user = null;
    
    private function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user_id'])) {
            $this->user = $_SESSION['user'] ?? null;
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: /public/login.php');
            exit;
        }
    }
    
    public function requirePermission($permission) {
        // For now, allow all permissions if logged in
        $this->requireLogin();
        return true;
    }
    
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            $_SESSION['error'] = 'Admin-Rechte erforderlich';
            header('Location: /public/index.php');
            exit;
        }
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
    }
    
    public function isAdmin() {
        return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
    }
    
    public function hasPermission($permission) {
        // Simplified: admins have all permissions
        if ($this->isAdmin()) return true;
        
        // For now, allow basic permissions for all logged in users
        return $this->isLoggedIn();
    }
    
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    public function getUser() {
        return $this->user;
    }
    
    public function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public function logActivity($action, $entityType = null, $entityId = null) {
        // Log activity to database or file
        error_log(sprintf('[ACTIVITY] User %d: %s on %s:%s', 
            $this->getUserId() ?? 0, 
            $action, 
            $entityType ?? 'system', 
            $entityId ?? 0
        ));
    }
    
    public function login($username, $password) {
        // This would check against database
        // For now, simulate successful login
        $_SESSION['user_id'] = 1;
        $_SESSION['user'] = [
            'id' => 1,
            'username' => $username,
            'email' => 'admin@example.com',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'role' => 'admin'
        ];
        $this->user = $_SESSION['user'];
        return true;
    }
    
    public function logout() {
        $_SESSION = [];
        session_destroy();
        $this->user = null;
    }
}