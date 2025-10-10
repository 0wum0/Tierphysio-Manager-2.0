<?php
/**
 * Tierphysio Manager 2.0
 * Standalone Auth Class (no Composer dependencies except Twig)
 */

class Auth {
    private $pdo;
    private $user = null;
    private $sessionName = 'tierphysio_session';
    
    public function __construct() {
        // Get PDO connection
        if (function_exists('get_pdo')) {
            $this->pdo = get_pdo();
        } else {
            require_once __DIR__ . '/db.php';
            $this->pdo = get_pdo();
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
    
    /**
     * Load user from database
     */
    private function loadUser($userId) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM tp_users WHERE id = ? AND is_active = 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                unset($user['password']); // Remove password from user object
                $this->user = $user;
                return true;
            }
        } catch (PDOException $e) {
            error_log("Auth loadUser error: " . $e->getMessage());
        }
        
        // User not found or error - logout
        $this->logout();
        return false;
    }
    
    /**
     * Check if user is logged in
     * Konsistent mit auth.php is_logged_in()
     */
    public function isLoggedIn() {
        // Prüfe sowohl das interne User-Objekt als auch die Session
        if ($this->user !== null) {
            return true;
        }
        // Falls kein User geladen, aber Session vorhanden
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            // Versuche User zu laden
            if ($this->loadUser($_SESSION['user_id'])) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get current user
     */
    public function getUser() {
        return $this->user;
    }
    
    /**
     * Get user ID
     */
    public function getUserId() {
        return $this->user ? $this->user['id'] : null;
    }
    
    /**
     * Get user role
     */
    public function getUserRole() {
        return $this->user ? $this->user['role'] : null;
    }
    
    /**
     * Check if user is admin
     */
    public function isAdmin() {
        return $this->user && $this->user['role'] === 'admin';
    }
    
    /**
     * Require login
     */
    public function requireLogin($redirect = true) {
        if (!$this->isLoggedIn()) {
            error_log("[AUTH DEBUG] StandaloneAuth::requireLogin() - User not logged in");
            if ($redirect) {
                // Speichere die ursprüngliche URL für Redirect nach Login
                $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
                
                // Prüfe ob wir im Admin-Bereich sind
                $isAdminArea = strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') !== false;
                if ($isAdminArea) {
                    header('Location: /admin/login.php');
                } else {
                    header('Location: /public/login.php');
                }
                exit;
            } else {
                http_response_code(401);
                die(json_encode(['error' => 'Nicht authentifiziert']));
            }
        }
    }
    
    /**
     * Require admin
     */
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
    
    /**
     * Login user
     */
    public function login($username, $password, $remember = false) {
        try {
            // Find user by username or email
            $stmt = $this->pdo->prepare("SELECT * FROM tp_users WHERE (username = ? OR email = ?) AND is_active = 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Benutzername oder Passwort falsch.'
                ];
            }
            
            // Check password
            if (!password_verify($password, $user['password'])) {
                return [
                    'success' => false,
                    'message' => 'Benutzername oder Passwort falsch.'
                ];
            }
            
            // Set session - konsistent mit auth.php login_user()
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // Speichere erweiterte User-Daten
            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'role' => $user['role'],
                'avatar' => $user['avatar'] ?? null
            ];
            $_SESSION['logged_in'] = true;
            
            error_log("[AUTH DEBUG] StandaloneAuth::login() - User " . $user['id'] . " logged in");
            
            // Update last login
            $stmt = $this->pdo->prepare("UPDATE tp_users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            // Load user
            unset($user['password']);
            $this->user = $user;
            
            return [
                'success' => true,
                'message' => 'Login erfolgreich.',
                'user' => $this->user
            ];
            
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ein Fehler ist aufgetreten.'
            ];
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        // Clear session
        $_SESSION = [];
        
        // Destroy session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        // Destroy session
        session_destroy();
        
        $this->user = null;
    }
    
    // FIRST DUPLICATE REMOVED - using the later improved definition
    // The getCSRFToken() and verifyCSRFToken() methods are defined below with better session handling
    
    /**
     * Check permission
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
    
    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Get CSRF Token (alias for generateCSRFToken for compatibility)
     */
    public function getCSRFToken() {
        return $this->generateCSRFToken();
    }
    
    /**
     * Verify CSRF token
     */
    public function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Alias für Kompatibilität
class StandaloneAuth extends Auth {}