<?php
namespace TierphysioManager;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

/**
 * Authentication Manager
 * Handles user authentication, sessions, and JWT tokens
 */
class Auth {
    private static $instance = null;
    private $db;
    private $user = null;
    private $sessionName;
    private $jwtSecret;
    
    /**
     * Private constructor - Singleton pattern
     */
    private function __construct() {
        $this->db = Database::getInstance();
        
        if (file_exists(__DIR__ . '/config.php')) {
            require_once __DIR__ . '/config.php';
            $this->sessionName = defined('SESSION_NAME') ? SESSION_NAME : 'tierphysio_session';
            $this->jwtSecret = defined('JWT_SECRET') ? JWT_SECRET : bin2hex(random_bytes(32));
        }
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->sessionName);
            session_start();
        }
        
        // Check if user is logged in
        $this->checkAuth();
    }
    
    /**
     * Get Auth instance
     * @return Auth
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Check if user is authenticated
     */
    private function checkAuth() {
        // Check session
        if (isset($_SESSION['user_id'])) {
            $this->loadUser($_SESSION['user_id']);
            return;
        }
        
        // Check JWT token in header
        $token = $this->getTokenFromHeader();
        if ($token) {
            try {
                $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
                $this->loadUser($decoded->user_id);
                $_SESSION['user_id'] = $decoded->user_id;
            } catch (Exception $e) {
                // Invalid token
                $this->logout();
            }
        }
    }
    
    /**
     * Get JWT token from Authorization header
     * @return string|null
     */
    private function getTokenFromHeader() {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $matches = [];
            if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    /**
     * Load user data
     * @param int $userId
     */
    private function loadUser($userId) {
        $user = $this->db->selectOne('tp_users', ['id' => $userId]);
        if ($user && $user['is_active']) {
            unset($user['password']); // Remove password from user object
            $this->user = $user;
            
            // Update last login
            $this->db->update('tp_users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $userId]);
        } else {
            $this->logout();
        }
    }
    
    /**
     * Login user
     * @param string $username
     * @param string $password
     * @param bool $remember
     * @return array
     */
    public function login($username, $password, $remember = false) {
        // Check login attempts
        $user = $this->db->selectOne('tp_users', ['username' => $username]);
        
        if (!$user) {
            // Try with email
            $user = $this->db->selectOne('tp_users', ['email' => $username]);
        }
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Benutzername oder Passwort falsch.'
            ];
        }
        
        // Check if account is locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remainingTime = ceil((strtotime($user['locked_until']) - time()) / 60);
            return [
                'success' => false,
                'message' => "Ihr Konto ist für {$remainingTime} Minuten gesperrt."
            ];
        }
        
        // Check if account is active
        if (!$user['is_active']) {
            return [
                'success' => false,
                'message' => 'Ihr Konto ist deaktiviert. Bitte kontaktieren Sie den Administrator.'
            ];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Increment login attempts
            $attempts = $user['login_attempts'] + 1;
            $updateData = ['login_attempts' => $attempts];
            
            // Lock account if max attempts reached
            if (defined('MAX_LOGIN_ATTEMPTS') && $attempts >= MAX_LOGIN_ATTEMPTS) {
                $lockoutTime = defined('LOGIN_LOCKOUT_TIME') ? LOGIN_LOCKOUT_TIME : 900;
                $updateData['locked_until'] = date('Y-m-d H:i:s', time() + $lockoutTime);
            }
            
            $this->db->update('tp_users', $updateData, ['id' => $user['id']]);
            
            return [
                'success' => false,
                'message' => 'Benutzername oder Passwort falsch.'
            ];
        }
        
        // Login successful - reset attempts
        $this->db->update('tp_users', [
            'login_attempts' => 0,
            'locked_until' => null,
            'last_login' => date('Y-m-d H:i:s')
        ], ['id' => $user['id']]);
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_time'] = time();
        
        // Set remember me cookie
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', true, true);
            // Store token hash in database (not implemented in this version)
        }
        
        // Generate JWT token
        $token = $this->generateJWT($user['id']);
        
        // Load user
        unset($user['password']);
        $this->user = $user;
        
        // Log activity
        $this->logActivity('login', 'users', $user['id']);
        
        return [
            'success' => true,
            'message' => 'Login erfolgreich.',
            'token' => $token,
            'user' => $this->user
        ];
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if ($this->user) {
            $this->logActivity('logout', 'users', $this->user['id']);
        }
        
        // Clear session
        $_SESSION = [];
        session_destroy();
        
        // Clear remember cookie
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
        }
        
        $this->user = null;
    }
    
    /**
     * Generate JWT token
     * @param int $userId
     * @return string
     */
    private function generateJWT($userId) {
        $payload = [
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600)
        ];
        
        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }
    
    /**
     * Check if user is logged in
     * @return bool
     */
    public function isLoggedIn() {
        return $this->user !== null;
    }
    
    /**
     * Get current user
     * @return array|null
     */
    public function getUser() {
        return $this->user;
    }
    
    /**
     * Get user ID
     * @return int|null
     */
    public function getUserId() {
        return $this->user ? $this->user['id'] : null;
    }
    
    /**
     * Get user role
     * @return string|null
     */
    public function getUserRole() {
        return $this->user ? $this->user['role'] : null;
    }
    
    /**
     * Check if user has permission
     * @param string $permission
     * @return bool
     */
    public function hasPermission($permission) {
        if (!$this->user) {
            return false;
        }
        
        // Admin has all permissions
        if ($this->user['role'] === 'admin') {
            return true;
        }
        
        // Define permissions per role
        $permissions = [
            'employee' => [
                'view_dashboard',
                'view_patients',
                'edit_patients',
                'view_appointments',
                'edit_appointments',
                'view_treatments',
                'edit_treatments',
                'view_notes',
                'edit_notes',
                'view_invoices'
            ],
            'guest' => [
                'view_dashboard',
                'view_patients',
                'view_appointments'
            ]
        ];
        
        $role = $this->user['role'];
        return isset($permissions[$role]) && in_array($permission, $permissions[$role]);
    }
    
    /**
     * Check if user is admin
     * @return bool
     */
    public function isAdmin() {
        return $this->user && $this->user['role'] === 'admin';
    }
    
    /**
     * Require login
     * @param bool $redirect
     */
    public function requireLogin($redirect = true) {
        if (!$this->isLoggedIn()) {
            if ($redirect) {
                header('Location: /public/login.php');
                exit;
            } else {
                http_response_code(401);
                die(json_encode(['error' => 'Unauthorized']));
            }
        }
    }
    
    /**
     * Require admin
     * @param bool $redirect
     */
    public function requireAdmin($redirect = true) {
        $this->requireLogin($redirect);
        
        if (!$this->isAdmin()) {
            if ($redirect) {
                header('Location: /public/index.php');
                exit;
            } else {
                http_response_code(403);
                die(json_encode(['error' => 'Forbidden']));
            }
        }
    }
    
    /**
     * Require permission
     * @param string $permission
     * @param bool $redirect
     */
    public function requirePermission($permission, $redirect = true) {
        $this->requireLogin($redirect);
        
        if (!$this->hasPermission($permission)) {
            if ($redirect) {
                header('Location: /public/index.php');
                exit;
            } else {
                http_response_code(403);
                die(json_encode(['error' => 'Permission denied']));
            }
        }
    }
    
    /**
     * Generate CSRF token
     * @return string
     */
    public function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Get CSRF Token (alias for generateCSRFToken for compatibility)
     * @return string
     */
    public function getCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF token
     * @param string $token
     * @return bool
     */
    public function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Log user activity
     * @param string $action
     * @param string $entityType
     * @param int $entityId
     * @param array $oldValues
     * @param array $newValues
     */
    public function logActivity($action, $entityType = null, $entityId = null, $oldValues = null, $newValues = null) {
        $data = [
            'user_id' => $this->getUserId(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];
        
        try {
            $this->db->insert('tp_activity_log', $data);
        } catch (Exception $e) {
            error_log('Failed to log activity: ' . $e->getMessage());
        }
    }
    
    /**
     * Reset password request
     * @param string $email
     * @return array
     */
    public function requestPasswordReset($email) {
        $user = $this->db->selectOne('tp_users', ['email' => $email]);
        
        if (!$user) {
            // Don't reveal if email exists
            return [
                'success' => true,
                'message' => 'Wenn die E-Mail-Adresse existiert, wurde ein Link zum Zurücksetzen gesendet.'
            ];
        }
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
        
        $this->db->update('tp_users', [
            'reset_token' => $token,
            'reset_token_expires' => $expires
        ], ['id' => $user['id']]);
        
        // Send email (not implemented in this version)
        // TODO: Implement email sending
        
        return [
            'success' => true,
            'message' => 'Wenn die E-Mail-Adresse existiert, wurde ein Link zum Zurücksetzen gesendet.'
        ];
    }
    
    /**
     * Reset password
     * @param string $token
     * @param string $newPassword
     * @return array
     */
    public function resetPassword($token, $newPassword) {
        $user = $this->db->selectOne('tp_users', ['reset_token' => $token]);
        
        if (!$user || strtotime($user['reset_token_expires']) < time()) {
            return [
                'success' => false,
                'message' => 'Ungültiger oder abgelaufener Token.'
            ];
        }
        
        // Update password
        $this->db->update('tp_users', [
            'password' => password_hash($newPassword, PASSWORD_BCRYPT),
            'reset_token' => null,
            'reset_token_expires' => null
        ], ['id' => $user['id']]);
        
        $this->logActivity('password_reset', 'users', $user['id']);
        
        return [
            'success' => true,
            'message' => 'Passwort wurde erfolgreich zurückgesetzt.'
        ];
    }
}