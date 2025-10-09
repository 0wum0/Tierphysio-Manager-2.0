<?php
/**
 * Tierphysio Manager 2.0
 * Authentication Helper Functions
 */

namespace TierphysioManager;

if (!class_exists('\TierphysioManager\Auth')) {
    // Simple Auth wrapper for non-Composer environments
    class Auth {
        private $pdo;
        private $user = null;
        private $sessionName = 'tierphysio_session';
        
        public function __construct() {
            // Get PDO connection
            if (function_exists('\get_pdo')) {
                $this->pdo = \get_pdo();
            } else {
                require_once __DIR__ . '/db.php';
                $this->pdo = \get_pdo();
            }
            
            // Start session if not started
            if (session_status() === PHP_SESSION_NONE) {
                session_name($this->sessionName);
                session_start();
            }
            
            // Load user from session
            if (isset($_SESSION['user_id'])) {
                $this->loadUser($_SESSION['user_id']);
            }
        }
        
        private function loadUser($userId) {
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM tp_users WHERE id = ? AND is_active = 1");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($user) {
                    unset($user['password']);
                    $this->user = $user;
                    return true;
                }
            } catch (\PDOException $e) {
                error_log("Auth loadUser error: " . $e->getMessage());
            }
            
            $this->logout();
            return false;
        }
        
        public function isLoggedIn() {
            return $this->user !== null;
        }
        
        public function getUser() {
            return $this->user;
        }
        
        public function getUserId() {
            return $this->user ? $this->user['id'] : null;
        }
        
        public function getUserRole() {
            return $this->user ? $this->user['role'] : null;
        }
        
        public function isAdmin() {
            return $this->user && $this->user['role'] === 'admin';
        }
        
        public function requireLogin($redirect = true) {
            if (!$this->isLoggedIn()) {
                if ($redirect) {
                    header('Location: /public/login.php');
                    exit;
                } else {
                    http_response_code(401);
                    die(json_encode(['error' => 'Nicht authentifiziert']));
                }
            }
        }
        
        public function requireAdmin($redirect = true) {
            $this->requireLogin($redirect);
            
            if (!$this->isAdmin()) {
                if ($redirect) {
                    $_SESSION['flash_error'] = 'Zugriff verweigert. Admin-Rechte erforderlich.';
                    header('Location: /public/index.php');
                    exit;
                } else {
                    http_response_code(403);
                    die(json_encode(['error' => 'Zugriff verweigert']));
                }
            }
        }
        
        public function login($username, $password, $remember = false) {
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM tp_users WHERE (username = ? OR email = ?) AND is_active = 1");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'Benutzername oder Passwort falsch.'
                    ];
                }
                
                if (!password_verify($password, $user['password'])) {
                    return [
                        'success' => false,
                        'message' => 'Benutzername oder Passwort falsch.'
                    ];
                }
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['login_time'] = time();
                
                $stmt = $this->pdo->prepare("UPDATE tp_users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                unset($user['password']);
                $this->user = $user;
                
                return [
                    'success' => true,
                    'message' => 'Login erfolgreich.',
                    'user' => $this->user
                ];
                
            } catch (\PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => 'Ein Fehler ist aufgetreten.'
                ];
            }
        }
        
        public function logout() {
            $_SESSION = [];
            
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            
            session_destroy();
            $this->user = null;
        }
        
        public function getCSRFToken() {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            return $_SESSION['csrf_token'];
        }
        
        public function getCSRFField(): string {
            return '<input type="hidden" name="csrf_token" value="'.$this->getCSRFToken().'">';
        }
        
        public function verifyCSRFToken($token) {
            return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
        }
        
        public function hasPermission($permission) {
            if (!$this->user) {
                return false;
            }
            
            if ($this->user['role'] === 'admin') {
                return true;
            }
            
            $permissions = [
                'employee' => [
                    'view_dashboard',
                    'view_patients',
                    'edit_patients',
                    'view_appointments',
                    'edit_appointments',
                    'view_treatments',
                    'edit_treatments',
                    'view_owners',
                    'edit_owners',
                    'view_notes',
                    'edit_notes',
                    'view_invoices'
                ],
                'guest' => [
                    'view_dashboard',
                    'view_patients',
                    'view_appointments',
                    'view_owners'
                ]
            ];
            
            $role = $this->user['role'];
            return isset($permissions[$role]) && in_array($permission, $permissions[$role]);
        }
        
        public function generateCSRFToken() {
            return $this->getCSRFToken();
        }
    }
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in, redirect to login if not
 */
function require_login() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user'])) {
        header('Location: /public/login.php');
        exit;
    }
}

/**
 * Check if user is admin, redirect to dashboard if not
 */
function require_admin() {
    require_login();
    
    if (!isset($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
        $_SESSION['error'] = 'Zugriff verweigert. Admin-Rechte erforderlich.';
        header('Location: /public/index.php');
        exit;
    }
}

/**
 * Get current logged in user
 */
function current_user() {
    if (isset($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    return null;
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user']);
}

/**
 * Check if user is admin
 */
function is_admin() {
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
}

/**
 * Login user
 */
function login_user($user_data) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['user_id'] = $user_data['id'];
    $_SESSION['user'] = [
        'id' => $user_data['id'],
        'username' => $user_data['username'],
        'email' => $user_data['email'],
        'first_name' => $user_data['first_name'],
        'last_name' => $user_data['last_name'],
        'role' => $user_data['role'],
        'avatar' => $user_data['avatar'] ?? null
    ];
    $_SESSION['logged_in'] = true;
    
    // Regenerate session ID for security
    session_regenerate_id(true);
}

/**
 * Logout user
 */
function logout_user() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Clear session data
    $_SESSION = [];
    
    // Destroy session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy session
    session_destroy();
}

// checkApiAuth() function is now defined in db.php to avoid duplicate definition