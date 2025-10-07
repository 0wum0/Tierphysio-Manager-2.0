<?php
/**
 * Tierphysio Manager 2.0
 * API JSON Integrity Check
 * 
 * This script tests all API endpoints to ensure they return valid JSON
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Catch any output errors
ob_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check authentication
checkApiAuth();

// Define API endpoints to test
$endpoints = [
    [
        'name' => 'Patients API',
        'url' => '/api/patients.php',
        'test_actions' => ['list']
    ],
    [
        'name' => 'Owners API',
        'url' => '/api/owners.php',
        'test_actions' => ['list']
    ],
    [
        'name' => 'Appointments API',
        'url' => '/api/appointments.php',
        'test_actions' => ['list']
    ],
    [
        'name' => 'Treatments API',
        'url' => '/api/treatments.php',
        'test_actions' => ['list']
    ],
    [
        'name' => 'Invoices API',
        'url' => '/api/invoices.php',
        'test_actions' => ['list']
    ],
    [
        'name' => 'Notes API',
        'url' => '/api/notes.php',
        'test_actions' => ['list']
    ]
];

$results = [];
$allPassed = true;

// Get the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$basePath = dirname(dirname($_SERVER['REQUEST_URI']));
$baseUrl = $protocol . "://" . $host . $basePath;

// Test each endpoint
foreach ($endpoints as $endpoint) {
    $endpointResults = [
        'name' => $endpoint['name'],
        'url' => $endpoint['url'],
        'tests' => []
    ];
    
    foreach ($endpoint['test_actions'] as $action) {
        $testUrl = $baseUrl . $endpoint['url'] . '?action=' . $action;
        
        // Create a test context with cookies for authentication
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Cookie: ' . http_build_query($_COOKIE, '', '; '),
                    'Accept: application/json'
                ],
                'timeout' => 5
            ]
        ];
        
        $context = stream_context_create($opts);
        $testResult = [
            'action' => $action,
            'url' => $testUrl,
            'passed' => false,
            'error' => null,
            'autofix' => false
        ];
        
        try {
            // Suppress warnings to handle them properly
            $response = @file_get_contents($testUrl, false, $context);
            
            if ($response === false) {
                $testResult['error'] = 'Failed to fetch response';
                $allPassed = false;
            } else {
                // Check if response starts with {
                if (!preg_match('/^\s*{/', $response)) {
                    $testResult['error'] = 'Response does not start with JSON (Unexpected token)';
                    $testResult['autofix'] = true;
                    $allPassed = false;
                    
                    // Log the issue for debugging
                    error_log("API Integrity Check - Non-JSON response from {$endpoint['name']}: " . substr($response, 0, 100));
                } else {
                    // Try to decode JSON
                    $decoded = json_decode($response, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $testResult['error'] = 'Invalid JSON: ' . json_last_error_msg();
                        $testResult['autofix'] = true;
                        $allPassed = false;
                    } else {
                        // Check if it has the expected structure
                        if (!isset($decoded['status'])) {
                            $testResult['error'] = 'JSON missing "status" field';
                            $allPassed = false;
                        } else {
                            $testResult['passed'] = true;
                            $testResult['response_type'] = $decoded['status'];
                        }
                    }
                }
                
                // Check headers
                $headers = $http_response_header ?? [];
                $hasJsonHeader = false;
                foreach ($headers as $header) {
                    if (stripos($header, 'content-type:') !== false && stripos($header, 'application/json') !== false) {
                        $hasJsonHeader = true;
                        break;
                    }
                }
                
                if (!$hasJsonHeader) {
                    $testResult['error'] = ($testResult['error'] ? $testResult['error'] . ' + ' : '') . 'Missing JSON Content-Type header';
                    $testResult['autofix'] = true;
                    $allPassed = false;
                }
            }
        } catch (Exception $e) {
            $testResult['error'] = 'Exception: ' . $e->getMessage();
            $allPassed = false;
        }
        
        $endpointResults['tests'][] = $testResult;
    }
    
    $results[] = $endpointResults;
}

// Prepare summary
$summary = [
    'timestamp' => date('Y-m-d H:i:s'),
    'all_passed' => $allPassed,
    'total_endpoints' => count($endpoints),
    'failed_count' => 0,
    'autofix_needed' => []
];

foreach ($results as $result) {
    foreach ($result['tests'] as $test) {
        if (!$test['passed']) {
            $summary['failed_count']++;
            if ($test['autofix']) {
                $summary['autofix_needed'][] = [
                    'endpoint' => $result['name'],
                    'action' => $test['action'],
                    'error' => $test['error']
                ];
            }
        }
    }
}

// Clear any output buffer and return JSON
ob_end_clean();

json_success([
    'summary' => $summary,
    'results' => $results,
    'recommendation' => $allPassed ? 
        'All API endpoints are returning valid JSON!' : 
        'Some endpoints need fixing. AutoFix is ' . (count($summary['autofix_needed']) > 0 ? 'ON' : 'OFF')
]);

// Ensure no further output
exit;