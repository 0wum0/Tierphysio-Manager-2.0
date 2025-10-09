<?php
/**
 * Tierphysio Manager 2.0
 * Integrity Test for Patients API
 */

require_once __DIR__ . '/../includes/config.php';

// Test configuration
$base_url = 'http://localhost';
$api_base = '/api';
$tests_passed = 0;
$tests_failed = 0;
$errors = [];

// Color output helpers
function success($msg) {
    echo "\033[32m✓ $msg\033[0m\n";
}

function error($msg) {
    echo "\033[31m✗ $msg\033[0m\n";
}

function info($msg) {
    echo "\033[36mℹ $msg\033[0m\n";
}

function test_endpoint($url, $expected_code = 200, $check_json = true) {
    global $tests_passed, $tests_failed, $errors;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    curl_close($ch);
    
    // Check HTTP status code
    if ($http_code !== $expected_code) {
        $tests_failed++;
        $errors[] = "URL: $url - Expected HTTP $expected_code, got $http_code";
        return false;
    }
    
    // Check Content-Type header
    if ($check_json && !preg_match('/Content-Type:\s*application\/json/i', $headers)) {
        $tests_failed++;
        $errors[] = "URL: $url - Missing or invalid Content-Type header";
        return false;
    }
    
    // Try to parse JSON
    if ($check_json) {
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $tests_failed++;
            $errors[] = "URL: $url - Invalid JSON response: " . json_last_error_msg();
            return false;
        }
        
        // Check for 'ok' field in response
        if (!isset($data['ok'])) {
            $tests_failed++;
            $errors[] = "URL: $url - Missing 'ok' field in JSON response";
            return false;
        }
    }
    
    $tests_passed++;
    return true;
}

// Start tests
echo "\n";
info("Starting Patients API Integrity Tests");
echo str_repeat("=", 50) . "\n\n";

// Test 1: Patients List
info("Test 1: GET /api/patients.php?action=list");
if (test_endpoint("$base_url$api_base/patients.php?action=list")) {
    success("Patients list endpoint returns valid JSON");
} else {
    error("Patients list endpoint failed");
}

// Test 2: Owners List
info("\nTest 2: GET /api/owners.php?action=list");
if (test_endpoint("$base_url$api_base/owners.php?action=list")) {
    success("Owners list endpoint returns valid JSON");
} else {
    error("Owners list endpoint failed");
}

// Test 3: Get non-existent patient (should return 404/400)
info("\nTest 3: GET /api/patients.php?action=get&id=99999");
if (test_endpoint("$base_url$api_base/patients.php?action=get&id=99999", 404)) {
    success("Non-existent patient returns proper error");
} else if (test_endpoint("$base_url$api_base/patients.php?action=get&id=99999", 400)) {
    success("Non-existent patient returns proper error");
} else {
    error("Non-existent patient error handling failed");
}

// Test 4: Invalid action
info("\nTest 4: GET /api/patients.php?action=invalid");
if (test_endpoint("$base_url$api_base/patients.php?action=invalid", 400)) {
    success("Invalid action returns proper error");
} else {
    error("Invalid action error handling failed");
}

// Test 5: Appointments List
info("\nTest 5: GET /api/appointments.php?action=list");
if (test_endpoint("$base_url$api_base/appointments.php?action=list")) {
    success("Appointments list endpoint returns valid JSON");
} else {
    error("Appointments list endpoint failed");
}

// Test 6: Treatments List
info("\nTest 6: GET /api/treatments.php?action=list");
if (test_endpoint("$base_url$api_base/treatments.php?action=list")) {
    success("Treatments list endpoint returns valid JSON");
} else {
    error("Treatments list endpoint failed");
}

// Test 7: Check database table prefixes
info("\nTest 7: Checking database table prefixes");
try {
    require_once __DIR__ . '/../includes/db.php';
    $pdo = get_pdo();
    
    // Try to query tp_patients table
    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_patients");
    $count = $stmt->fetchColumn();
    success("tp_patients table accessible (contains $count records)");
    
    // Try to query tp_owners table
    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_owners");
    $count = $stmt->fetchColumn();
    success("tp_owners table accessible (contains $count records)");
    
    $tests_passed += 2;
} catch (PDOException $e) {
    error("Database table check failed: " . $e->getMessage());
    $errors[] = "Database error: " . $e->getMessage();
    $tests_failed += 2;
}

// Test 8: Check response structure
info("\nTest 8: Checking response structure");
$ch = curl_init("$base_url$api_base/patients.php?action=list");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($data && isset($data['ok']) && isset($data['data'])) {
    if (isset($data['data']['items']) && is_array($data['data']['items'])) {
        success("Response structure is correct (ok, data.items)");
        $tests_passed++;
        
        // Check for owner_full_name field
        if (!empty($data['data']['items'])) {
            $first_patient = $data['data']['items'][0];
            if (isset($first_patient['owner_full_name'])) {
                success("Patient includes owner_full_name field");
                $tests_passed++;
            } else {
                error("Patient missing owner_full_name field");
                $tests_failed++;
            }
        }
    } else {
        error("Response missing items array");
        $tests_failed++;
    }
} else {
    error("Invalid response structure");
    $tests_failed++;
}

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
info("Test Summary");
echo str_repeat("-", 50) . "\n";
success("Tests Passed: $tests_passed");
if ($tests_failed > 0) {
    error("Tests Failed: $tests_failed");
    echo "\n";
    error("Errors:");
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
} else {
    echo "\n";
    success("All tests passed successfully!");
}

echo "\n" . str_repeat("=", 50) . "\n\n";

// Exit with appropriate code
exit($tests_failed > 0 ? 1 : 0);