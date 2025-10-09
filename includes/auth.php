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

/**
 * Check API authentication
 * Returns JSON error if not authenticated
 */
function checkApiAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user'])) {
        // Check if this is an API call
        $is_api = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
        
        if ($is_api) {
            // Check if response.php is loaded
            if (function_exists('json_error')) {
                json_error('Nicht authentifiziert. Bitte einloggen.', 401);
            } else {
                // Fallback to direct JSON output
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(401);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Nicht authentifiziert. Bitte einloggen.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            // Redirect to login for non-API calls
            header('Location: /public/login.php');
            exit;
        }
    }
}