<?php
/**
 * Tierphysio Manager 2.0
 * Simple JSON Test Endpoint
 * 
 * Tests that JSON responses are working correctly
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
ob_start();

// Include required files
require_once __DIR__ . '/../../includes/response.php';

// Get action
$action = $_GET['action'] ?? 'test';

try {
    switch ($action) {
        case 'test':
            json_success(['message' => 'JSON API is working correctly!', 'timestamp' => date('Y-m-d H:i:s')], 'Test successful');
            break;
            
        case 'error':
            json_error('This is a test error message', 400);
            break;
            
        case 'list':
            $testData = [
                ['id' => 1, 'name' => 'Test Item 1', 'status' => 'active'],
                ['id' => 2, 'name' => 'Test Item 2', 'status' => 'inactive'],
                ['id' => 3, 'name' => 'Test Item 3', 'status' => 'active']
            ];
            json_success($testData, 'Test list retrieved');
            break;
            
        default:
            json_error('Unknown test action', 400);
    }
} catch (Throwable $e) {
    ob_clean();
    json_error('Test error: ' . $e->getMessage(), 500);
}

exit;