<?php
/**
 * Tierphysio Manager 2.0
 * Authentication Helper Functions
 */

// Include the standalone Auth class
require_once __DIR__ . '/StandaloneAuth.php';

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
    
    // Konsistent mit is_logged_in() - nur user_id prüfen
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log("[AUTH DEBUG] require_login() - No user_id, redirecting to login");
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
    // Prüfe user_id zuerst
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        // Versuche User-Daten aus der Session zu holen
        if (isset($_SESSION['user'])) {
            return $_SESSION['user'];
        }
        // Falls keine User-Daten in Session, lade sie aus der Datenbank
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, role, avatar FROM tp_users WHERE id = ? AND is_active = 1");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $_SESSION['user'] = $user;
                    return $user;
                }
            } catch (PDOException $e) {
                error_log("[AUTH DEBUG] current_user() error: " . $e->getMessage());
            }
        }
    }
    return null;
}

/**
 * Check if user is logged in
 * Synchronisiert mit StandaloneAuth - prüft nur user_id
 */
function is_logged_in() {
    // Konsistent mit StandaloneAuth::isLoggedIn()
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user is admin
 */
function is_admin() {
    // Check multiple locations for admin role
    if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin') {
        return true;
    }
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        return true;
    }
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        return true;
    }
    return false;
}

/**
 * Login user
 * Synchronisiert mit StandaloneAuth::login()
 */
function login_user($user_data) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Setze die gleichen Session-Variablen wie StandaloneAuth
    $_SESSION['user_id'] = $user_data['id'];
    $_SESSION['user_role'] = $user_data['role'] ?? 'guest';
    $_SESSION['role'] = $user_data['role'] ?? 'guest'; // Add direct role for template access
    $_SESSION['login_time'] = time();
    
    // Speichere auch erweiterte User-Daten für Kompatibilität
    $_SESSION['user'] = [
        'id' => $user_data['id'],
        'username' => $user_data['username'] ?? '',
        'email' => $user_data['email'] ?? '',
        'first_name' => $user_data['first_name'] ?? '',
        'last_name' => $user_data['last_name'] ?? '',
        'role' => $user_data['role'] ?? 'guest',
        'avatar' => $user_data['avatar'] ?? null
    ];
    $_SESSION['logged_in'] = true;
    
    error_log("[AUTH DEBUG] login_user() - User " . $user_data['id'] . " logged in with role " . $user_data['role']);
    
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