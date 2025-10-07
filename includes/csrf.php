<?php
/**
 * Tierphysio Manager 2.0
 * CSRF Protection Functions
 */

/**
 * Generate CSRF token
 */
function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Get CSRF token field HTML
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * Validate CSRF token
 */
function csrf_validate($token = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Skip CSRF validation for GET requests
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        return true;
    }
    
    // Get token from request
    if ($token === null) {
        // Try to get from POST
        $token = $_POST['csrf_token'] ?? null;
        
        // Try to get from headers
        if (!$token) {
            $headers = getallheaders();
            $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? null;
        }
        
        // Try to get from JSON body
        if (!$token) {
            $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($content_type, 'application/json') !== false) {
                $json = file_get_contents('php://input');
                $data = json_decode($json, true);
                $token = $data['csrf_token'] ?? null;
            }
        }
    }
    
    // Check if token exists in session
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    
    // Validate token
    return hash_equals($_SESSION['csrf_token'], $token ?? '');
}

/**
 * Require valid CSRF token or exit
 */
function require_csrf() {
    if (!csrf_validate()) {
        // Check if it's an API call
        if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
            require_once __DIR__ . '/response.php';
            json_error('CSRF-Token ungültig oder fehlt', 403);
        } else {
            http_response_code(403);
            die('CSRF-Token ungültig oder fehlt');
        }
    }
}

/**
 * Regenerate CSRF token
 */
function csrf_regenerate() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}