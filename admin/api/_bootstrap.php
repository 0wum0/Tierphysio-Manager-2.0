<?php
/**
 * Admin API Bootstrap
 * Provides common initialization, authentication, and helper functions
 */

// Include main application bootstrap
require_once __DIR__ . '/../../includes/bootstrap.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize authentication
$auth = new StandaloneAuth($pdo);

// Admin-specific session namespace
if (!isset($_SESSION['admin'])) {
    $_SESSION['admin'] = [];
}

/**
 * Check if user has admin role
 */
function isAdmin($userId = null) {
    global $pdo, $auth;
    
    if ($userId === null) {
        if (!$auth->isLoggedIn()) {
            return false;
        }
        $userId = $auth->getUserId();
    }
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tp_user_roles ur 
        JOIN tp_roles r ON ur.role_id = r.id 
        WHERE ur.user_id = ? AND r.name = 'admin'
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Check if user has specific permission
 */
function hasPermission($permission, $userId = null) {
    global $pdo, $auth;
    
    if ($userId === null) {
        if (!$auth->isLoggedIn()) {
            return false;
        }
        $userId = $auth->getUserId();
    }
    
    // Check for wildcard admin permission first
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tp_user_roles ur 
        JOIN tp_role_permissions rp ON ur.role_id = rp.role_id
        JOIN tp_permissions p ON rp.permission_id = p.id
        WHERE ur.user_id = ? AND (p.key = 'admin.*' OR p.key = ?)
    ");
    $stmt->execute([$userId, $permission]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Require admin authentication
 */
function requireAdmin() {
    global $auth;
    
    if (!$auth->isLoggedIn() || !isAdmin()) {
        api_error('Unauthorized: Admin access required', 401);
    }
}

/**
 * Require specific permission
 */
function requirePermission($permission) {
    global $auth;
    
    if (!$auth->isLoggedIn() || !hasPermission($permission)) {
        api_error('Forbidden: Missing required permission', 403);
    }
}

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (empty($_SESSION['admin']['csrf_token'])) {
        $_SESSION['admin']['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin']['csrf_token'];
}

/**
 * Get current CSRF token
 */
function getCsrfToken() {
    return $_SESSION['admin']['csrf_token'] ?? generateCsrfToken();
}

/**
 * Verify CSRF token
 */
function verifyCsrfToken($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    
    $sessionToken = $_SESSION['admin']['csrf_token'] ?? '';
    
    if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
        return false;
    }
    
    return true;
}

/**
 * Check CSRF for mutating requests
 */
function csrf_check() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
        if (!verifyCsrfToken()) {
            api_error('CSRF token validation failed', 403);
        }
    }
}

/**
 * Set JSON response header
 */
function json_header() {
    header('Content-Type: application/json; charset=utf-8');
}

/**
 * Send success API response
 */
function api_success($data = null, $message = null, $count = null) {
    json_header();
    
    $response = ['status' => 'success'];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    if ($message !== null) {
        $response['message'] = $message;
    }
    
    if ($count !== null) {
        $response['count'] = $count;
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Send error API response
 */
function api_error($message, $code = 400) {
    json_header();
    http_response_code($code);
    
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);
    exit;
}

/**
 * Get JSON input
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid JSON input', 400);
    }
    
    return $data;
}

/**
 * Sanitize input
 */
function sanitize($input, $type = 'string') {
    if ($input === null) {
        return null;
    }
    
    switch ($type) {
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT) !== false ? (int)$input : null;
        case 'float':
            return filter_var($input, FILTER_VALIDATE_FLOAT) !== false ? (float)$input : null;
        case 'email':
            return filter_var($input, FILTER_VALIDATE_EMAIL) !== false ? $input : null;
        case 'bool':
            return filter_var($input, FILTER_VALIDATE_BOOLEAN);
        case 'html':
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        default:
            return trim($input);
    }
}

// Set security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// For all API endpoints, require admin by default
// Individual endpoints can override this if needed
requireAdmin();