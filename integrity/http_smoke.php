<?php
/**
 * Tierphysio Manager 2.0
 * HTTP API Smoke Test
 * 
 * Testet alle API-Endpunkte auf korrekte JSON-Antworten
 */

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Base URL for API endpoints
$base_url = 'http://localhost';
if (isset($_SERVER['HTTP_HOST'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
}

// API endpoints to test
$endpoints = [
    [
        'name' => 'Owners List',
        'url' => '/public/api/owners.php?action=list',
        'method' => 'GET',
        'expected_fields' => ['ok', 'data']
    ],
    [
        'name' => 'Patients List',
        'url' => '/public/api/patients.php?action=list',
        'method' => 'GET',
        'expected_fields' => ['ok', 'data']
    ],
    [
        'name' => 'Appointments List',
        'url' => '/public/api/appointments.php?action=list',
        'method' => 'GET',
        'expected_fields' => ['status']
    ],
    [
        'name' => 'Invoices List',
        'url' => '/public/api/invoices.php?action=list',
        'method' => 'GET',
        'expected_fields' => ['status']
    ],
    [
        'name' => 'Treatments List',
        'url' => '/public/api/treatments.php?action=list',
        'method' => 'GET',
        'expected_fields' => ['status']
    ],
    [
        'name' => 'Notes List',
        'url' => '/public/api/notes.php?action=list',
        'method' => 'GET',
        'expected_fields' => ['status']
    ]
];

$results = [
    'ok' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'base_url' => $base_url,
    'tests' => [],
    'summary' => [
        'total' => count($endpoints),
        'passed' => 0,
        'failed' => 0,
        'warnings' => 0
    ]
];

// Function to test a single endpoint
function testEndpoint($base_url, $endpoint) {
    $result = [
        'name' => $endpoint['name'],
        'url' => $endpoint['url'],
        'method' => $endpoint['method'],
        'status' => 'unknown',
        'http_code' => null,
        'response_type' => null,
        'json_valid' => false,
        'expected_fields_found' => false,
        'response_preview' => null,
        'error' => null,
        'warnings' => []
    ];
    
    try {
        // Build full URL
        $full_url = $base_url . $endpoint['url'];
        
        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $full_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Set method
        if ($endpoint['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, []);
        }
        
        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL error: " . $error);
        }
        
        // Parse response
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        $result['http_code'] = $http_code;
        
        // Check Content-Type header
        if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $matches)) {
            $result['response_type'] = trim($matches[1]);
            
            if (stripos($result['response_type'], 'application/json') === false) {
                $result['warnings'][] = "Response Content-Type is not application/json: " . $result['response_type'];
            }
        }
        
        // Store preview of response (first 500 chars)
        $result['response_preview'] = substr($body, 0, 500);
        if (strlen($body) > 500) {
            $result['response_preview'] .= '... [truncated]';
        }
        
        // Try to parse JSON
        $json_data = @json_decode($body, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            $result['json_valid'] = true;
            
            // Check for expected fields
            if (!empty($endpoint['expected_fields'])) {
                $missing_fields = [];
                foreach ($endpoint['expected_fields'] as $field) {
                    if (!isset($json_data[$field])) {
                        $missing_fields[] = $field;
                    }
                }
                
                if (empty($missing_fields)) {
                    $result['expected_fields_found'] = true;
                } else {
                    $result['warnings'][] = "Missing expected fields: " . implode(', ', $missing_fields);
                }
            } else {
                $result['expected_fields_found'] = true;
            }
            
            // Check if response indicates an error
            if (isset($json_data['ok']) && $json_data['ok'] === false) {
                $result['warnings'][] = "API returned ok=false";
                if (isset($json_data['error'])) {
                    $result['warnings'][] = "API error: " . $json_data['error'];
                }
            } elseif (isset($json_data['status']) && $json_data['status'] === 'error') {
                $result['warnings'][] = "API returned status=error";
                if (isset($json_data['message'])) {
                    $result['warnings'][] = "API error: " . $json_data['message'];
                }
            }
            
            // Store parsed data for debugging
            $result['json_data'] = $json_data;
            
        } else {
            $result['json_valid'] = false;
            $result['error'] = "Invalid JSON: " . json_last_error_msg();
            
            // Check if response looks like HTML
            if (stripos($body, '<html') !== false || stripos($body, '<!DOCTYPE') !== false) {
                $result['error'] .= " - Response appears to be HTML instead of JSON";
                $result['warnings'][] = "HTML response detected - likely an error page";
            }
        }
        
        // Determine overall status
        if ($http_code >= 200 && $http_code < 300 && $result['json_valid']) {
            if (empty($result['warnings'])) {
                $result['status'] = 'passed';
            } else {
                $result['status'] = 'warning';
            }
        } else {
            $result['status'] = 'failed';
        }
        
    } catch (Exception $e) {
        $result['status'] = 'failed';
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

// Test each endpoint
foreach ($endpoints as $endpoint) {
    $test_result = testEndpoint($base_url, $endpoint);
    $results['tests'][] = $test_result;
    
    // Update summary
    if ($test_result['status'] === 'passed') {
        $results['summary']['passed']++;
    } elseif ($test_result['status'] === 'failed') {
        $results['summary']['failed']++;
        $results['ok'] = false;
    } elseif ($test_result['status'] === 'warning') {
        $results['summary']['warnings']++;
    }
}

// Add summary percentage
$results['summary']['success_rate'] = round(
    ($results['summary']['passed'] / $results['summary']['total']) * 100, 
    2
) . '%';

// Add recommendations
$results['recommendations'] = [];

if ($results['summary']['failed'] > 0) {
    $results['recommendations'][] = "Fix failed endpoints immediately - they are returning invalid JSON or errors";
}

if ($results['summary']['warnings'] > 0) {
    $results['recommendations'][] = "Review endpoints with warnings - they may have issues that could cause problems";
}

foreach ($results['tests'] as $test) {
    if ($test['status'] === 'failed' && stripos($test['response_preview'], '<html') !== false) {
        $results['recommendations'][] = "Endpoint '{$test['name']}' is returning HTML - check for PHP errors or missing files";
        break;
    }
}

// Output results
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;