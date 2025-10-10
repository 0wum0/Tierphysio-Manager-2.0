<?php
/**
 * Tierphysio Manager 2.0
 * API Bootstrap - Centralized helpers and configuration
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Error reporting for production
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Clear any existing output
if (ob_get_length()) ob_end_clean();
ob_start();

// Load database connection
require_once __DIR__ . '/../includes/db.php';

/**
 * API Success Response
 * Returns standardized success envelope with items and count
 * 
 * @param array $payload Array with 'items' and optionally 'count'
 */
function api_success(array $payload = []): void {
    if (ob_get_length()) ob_end_clean();
    
    // Extract items and count from payload
    $items = $payload['items'] ?? ($payload['data'] ?? []);
    $count = isset($payload['count']) ? (int)$payload['count'] : (is_array($items) ? count($items) : 0);
    
    // Ensure items is always an array
    if (!is_array($items)) {
        $items = [$items];
    }
    
    // Build response
    $response = [
        'ok' => true,
        'status' => 'success',
        'data' => [
            'items' => $items,
            'count' => $count
        ]
    ];
    
    // Add any additional fields directly to response for backward compatibility
    foreach ($payload as $key => $value) {
        if (!in_array($key, ['items', 'data', 'count'])) {
            $response[$key] = $value;
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * API Error Response
 * Returns standardized error envelope
 * 
 * @param string $message Error message
 * @param int $code HTTP status code (kept at 200 for better client compatibility)
 */
function api_error(string $message, int $code = 400): void {
    if (ob_get_length()) ob_end_clean();
    
    // Keep HTTP 200 for better frontend compatibility
    http_response_code(200);
    
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Helper function to generate patient number
 */
function generatePatientNumber($pdo) {
    do {
        $patient_number = 'P' . date('ymd') . rand(1000, 9999);
        $stmt = $pdo->prepare("SELECT id FROM tp_patients WHERE patient_number = ?");
        $stmt->execute([$patient_number]);
    } while ($stmt->fetch());
    
    return $patient_number;
}

/**
 * Helper function to generate customer number for owner
 */
function generateCustomerNumber($pdo) {
    do {
        $customer_number = 'K' . date('ymd') . rand(1000, 9999);
        $stmt = $pdo->prepare("SELECT id FROM tp_owners WHERE customer_number = ?");
        $stmt->execute([$customer_number]);
    } while ($stmt->fetch());
    
    return $customer_number;
}

/**
 * Helper function to generate invoice number
 */
function generateInvoiceNumber($pdo) {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(invoice_number, 6) AS UNSIGNED)) as max_num FROM tp_invoices WHERE invoice_number LIKE ?");
    $stmt->execute([$year . '-%']);
    $result = $stmt->fetch();
    $next = ($result['max_num'] ?? 0) + 1;
    return sprintf('%s-%04d', $year, $next);
}

/**
 * Helper function to generate appointment number
 */
function generateAppointmentNumber($pdo) {
    do {
        $appointment_number = 'T' . date('ymd') . rand(1000, 9999);
        $stmt = $pdo->prepare("SELECT id FROM tp_appointments WHERE appointment_number = ?");
        $stmt->execute([$appointment_number]);
    } while ($stmt->fetch());
    
    return $appointment_number;
}