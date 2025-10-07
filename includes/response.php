<?php
/**
 * Tierphysio Manager 2.0
 * Response Helper Functions for API
 */

/**
 * Send JSON success response
 */
function json_success($data = [], $message = '') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    
    echo json_encode([
        'status' => 'success',
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send JSON error response
 */
function json_error($message, $code = 400) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    
    echo json_encode([
        'status' => 'error',
        'data' => null,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send JSON response with custom status
 */
function json_response($status, $data = null, $message = '', $code = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    
    echo json_encode([
        'status' => $status,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Validate required fields
 */
function validate_required($data, $required_fields) {
    $missing = [];
    
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        json_error('Fehlende Pflichtfelder: ' . implode(', ', $missing), 400);
    }
    
    return true;
}

/**
 * Sanitize input data
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Get POST data as array
 */
function get_post_data() {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    
    // Handle JSON input
    if (strpos($content_type, 'application/json') !== false) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            json_error('Ungültiges JSON-Format', 400);
        }
        
        return $data;
    }
    
    // Handle form data
    return $_POST;
}