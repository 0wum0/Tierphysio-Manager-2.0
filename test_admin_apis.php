<?php
/**
 * Test script for Admin APIs
 * Run this to verify all endpoints are working
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance();

// Colors for output
$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$reset = "\033[0m";

echo "\n{$yellow}=== TierPhysio Manager 2.0 - Admin API Test ==={$reset}\n\n";

// Check if user is admin
$user = $auth->getUser();
if (!$user || !$auth->isAdmin()) {
    echo "{$red}✗ You must be logged in as admin to run this test{$reset}\n";
    exit(1);
}

echo "{$green}✓ Logged in as: {$user['username']} (Admin){$reset}\n\n";

// Test endpoints
$tests = [
    [
        'name' => 'Stats API - Overview',
        'url' => '/api/stats.php?type=overview',
        'method' => 'GET'
    ],
    [
        'name' => 'Stats API - Charts',
        'url' => '/api/stats.php?type=charts',
        'method' => 'GET'
    ],
    [
        'name' => 'Stats API - Database Info',
        'url' => '/api/stats.php?type=database',
        'method' => 'GET'
    ],
    [
        'name' => 'Settings API - Get',
        'url' => '/api/settings.php',
        'method' => 'GET'
    ],
    [
        'name' => 'Backup API - List',
        'url' => '/api/backup.php',
        'method' => 'GET'
    ],
    [
        'name' => 'Migration API - List',
        'url' => '/api/migrate.php',
        'method' => 'GET'
    ],
    [
        'name' => 'Users API - List',
        'url' => '/api/users.php',
        'method' => 'GET'
    ]
];

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    echo "Testing: {$test['name']} ... ";
    
    // Build full URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fullUrl = $protocol . '://' . $host . $test['url'];
    
    // Initialize cURL
    $ch = curl_init($fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $test['method']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '')
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Check response
    if ($httpCode === 200) {
        $json = json_decode($response, true);
        if ($json && isset($json['status']) && $json['status'] === 'success') {
            echo "{$green}✓ PASSED{$reset}\n";
            $passed++;
        } else {
            echo "{$red}✗ FAILED (Invalid JSON response){$reset}\n";
            $failed++;
        }
    } else {
        echo "{$red}✗ FAILED (HTTP {$httpCode}){$reset}\n";
        $failed++;
    }
}

echo "\n{$yellow}=== Test Results ==={$reset}\n";
echo "{$green}Passed: {$passed}{$reset}\n";
echo "{$red}Failed: {$failed}{$reset}\n";

if ($failed === 0) {
    echo "\n{$green}✓ All tests passed!{$reset}\n";
} else {
    echo "\n{$red}✗ Some tests failed. Please check the APIs.{$reset}\n";
}

// Check database tables
echo "\n{$yellow}=== Database Check ==={$reset}\n";

$requiredTables = [
    'tp_users',
    'tp_patients',
    'tp_owners',
    'tp_appointments',
    'tp_treatments',
    'tp_invoices',
    'tp_notes',
    'tp_settings',
    'tp_activity_log'
];

foreach ($requiredTables as $table) {
    try {
        $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "{$green}✓ {$table}: {$count} records{$reset}\n";
    } catch (Exception $e) {
        echo "{$red}✗ {$table}: Table not found{$reset}\n";
    }
}

echo "\n{$green}Test completed!{$reset}\n\n";