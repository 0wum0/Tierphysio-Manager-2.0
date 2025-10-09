<?php
/**
 * Tierphysio Manager 2.0
 * API Verification Script - Tests all endpoints for correct response shape
 */

// Configuration
$base_url = 'http://localhost/api';
$endpoints = [
    '/owners.php?action=list',
    '/patients.php?action=list',
    '/appointments.php?action=list',
    '/treatments.php?action=list',
    '/invoices.php?action=list',
    '/notes.php?action=list',
    '/integrity_json.php'
];

// Results storage
$results = [];
$all_passed = true;

// Test function
function test_endpoint($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    // Validate response
    $result = [
        'url' => $url,
        'http_code' => $http_code,
        'content_type' => $content_type,
        'passed' => false,
        'error' => null,
        'data' => null
    ];
    
    // Check HTTP status
    if ($http_code !== 200) {
        $result['error'] = "HTTP status: $http_code";
        return $result;
    }
    
    // Check content type
    if (strpos($content_type, 'application/json') === false) {
        $result['error'] = "Content-Type is not JSON: $content_type";
        return $result;
    }
    
    // Parse JSON
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $result['error'] = "Invalid JSON: " . json_last_error_msg();
        return $result;
    }
    
    // Validate shape
    if (!isset($data['ok'])) {
        $result['error'] = "Missing 'ok' field";
        return $result;
    }
    
    if (!isset($data['status'])) {
        $result['error'] = "Missing 'status' field";
        return $result;
    }
    
    if ($data['ok'] === true) {
        // Success response validation
        if ($data['status'] !== 'success') {
            $result['error'] = "Status should be 'success' when ok=true";
            return $result;
        }
        
        if (!isset($data['data'])) {
            $result['error'] = "Missing 'data' field in success response";
            return $result;
        }
        
        if (!isset($data['data']['items']) || !is_array($data['data']['items'])) {
            $result['error'] = "Missing or invalid 'data.items' array";
            return $result;
        }
        
        if (!isset($data['data']['count'])) {
            $result['error'] = "Missing 'data.count' field";
            return $result;
        }
        
        $result['passed'] = true;
        $result['data'] = [
            'items_count' => count($data['data']['items']),
            'count_field' => $data['data']['count']
        ];
    } else {
        // Error response validation
        if ($data['status'] !== 'error') {
            $result['error'] = "Status should be 'error' when ok=false";
            return $result;
        }
        
        if (!isset($data['message'])) {
            $result['error'] = "Missing 'message' field in error response";
            return $result;
        }
        
        $result['passed'] = true;
        $result['data'] = ['message' => $data['message']];
    }
    
    return $result;
}

// Header
echo "\n========================================\n";
echo "  TIERPHYSIO MANAGER API VERIFICATION\n";
echo "========================================\n\n";

// Test each endpoint
foreach ($endpoints as $endpoint) {
    $url = $base_url . $endpoint;
    echo "Testing: $endpoint\n";
    
    $result = test_endpoint($url);
    $results[] = $result;
    
    if ($result['passed']) {
        echo "  ✅ PASSED\n";
        if ($result['data']) {
            if (isset($result['data']['items_count'])) {
                echo "     Items: {$result['data']['items_count']}, Count: {$result['data']['count_field']}\n";
            } else {
                echo "     Message: {$result['data']['message']}\n";
            }
        }
    } else {
        echo "  ❌ FAILED: {$result['error']}\n";
        $all_passed = false;
    }
    echo "\n";
}

// Summary
echo "========================================\n";
echo "  SUMMARY\n";
echo "========================================\n\n";

$passed_count = count(array_filter($results, fn($r) => $r['passed']));
$total_count = count($results);

echo "Passed: $passed_count / $total_count\n";

if ($all_passed) {
    echo "\n✅ ALL TESTS PASSED! API shape is unified.\n\n";
    
    // Show sample response
    echo "Sample response from /api/patients.php?action=list:\n";
    echo "----------------------------------------\n";
    $sample_url = $base_url . '/patients.php?action=list';
    $ch = curl_init($sample_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $sample = curl_exec($ch);
    curl_close($ch);
    
    $sample_data = json_decode($sample, true);
    if ($sample_data && isset($sample_data['data']['items'][0])) {
        $first_item = $sample_data['data']['items'][0];
        echo json_encode([
            'ok' => $sample_data['ok'],
            'status' => $sample_data['status'],
            'data' => [
                'items' => [$first_item],
                'count' => $sample_data['data']['count']
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} else {
    echo "\n❌ SOME TESTS FAILED. Please fix the issues above.\n";
    exit(1);
}

echo "\n\n";
exit(0);