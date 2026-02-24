<?php
/**
 * Tierphysio Manager 2.0
 * Helper Functions
 */

/**
 * Generate a unique customer number
 */
function generateCustomerNumber($pdo) {
    $prefix = 'K';
    $year = date('Y');
    
    // Get the highest customer number for current year
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(customer_number, 6) AS UNSIGNED)) as max_num 
        FROM tp_owners 
        WHERE customer_number LIKE '$prefix$year%'
    ");
    $result = $stmt->fetch();
    $nextNum = ($result['max_num'] ?? 0) + 1;
    
    return $prefix . $year . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate a unique patient number
 */
function generatePatientNumber($pdo) {
    $prefix = 'P';
    $year = date('Y');
    
    // Get the highest patient number for current year
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(patient_number, 6) AS UNSIGNED)) as max_num 
        FROM tp_patients 
        WHERE patient_number LIKE '$prefix$year%'
    ");
    $result = $stmt->fetch();
    $nextNum = ($result['max_num'] ?? 0) + 1;
    
    return $prefix . $year . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

/**
 * API Success Response
 */
if (!function_exists('api_success')) {
    function api_success($data = null, $message = 'Success') {
        header('Content-Type: application/json; charset=utf-8');
        
        // Merge data if it's an array, otherwise wrap in data key
        if (is_array($data)) {
            $response = array_merge(['status' => 'success'], $data);
        } else {
            $response = [
                'status' => 'success',
                'message' => $message,
                'data' => $data
            ];
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

/**
 * API Error Response
 */
if (!function_exists('api_error')) {
    function api_error($message = 'Error', $code = 400) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode([
            'status' => 'error',
            'message' => $message,
            'data' => null
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

/**
 * Sanitize input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Check if request is AJAX
 */
function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Format date for display
 */
function format_date($date, $format = 'd.m.Y') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

/**
 * Format currency
 */
function format_currency($amount) {
    return number_format($amount, 2, ',', '.') . ' €';
}
