#!/usr/bin/env php
<?php
/**
 * Validate Patients API JSON Structure
 * This script validates that the API returns the expected JSON format
 */

// Mock the environment
$_SERVER['REQUEST_URI'] = '/api/patients.php?action=list';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'list';

// Capture API output
ob_start();
require_once __DIR__ . '/api/patients.php';
$output = ob_get_clean();

echo "=" . str_repeat("=", 60) . "\n";
echo "TIERPHYSIO MANAGER 2.0 - API Structure Validation\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Try to decode JSON
$data = json_decode($output, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ FEHLER: Ungültiges JSON\n";
    echo "   JSON Error: " . json_last_error_msg() . "\n";
    echo "   Raw output (first 500 chars):\n";
    echo "   " . substr($output, 0, 500) . "\n";
    exit(1);
}

echo "✅ JSON ist valide\n\n";

// Check expected structure
$errors = [];
$warnings = [];
$success = [];

// Check status field
if (!isset($data['status'])) {
    $errors[] = "Field 'status' is missing";
} elseif ($data['status'] !== 'success') {
    $errors[] = "Field 'status' is '" . $data['status'] . "' (expected 'success')";
} else {
    $success[] = "status = 'success'";
}

// Check data.items structure
if (!isset($data['data'])) {
    $errors[] = "Field 'data' is missing";
} elseif (!isset($data['data']['items'])) {
    $errors[] = "Field 'data.items' is missing";
} elseif (!is_array($data['data']['items'])) {
    $errors[] = "Field 'data.items' is not an array";
} else {
    $success[] = "data.items exists and is an array";
    $itemCount = count($data['data']['items']);
    $success[] = "Found $itemCount patient(s)";
    
    // Check first item structure
    if ($itemCount > 0) {
        $first = $data['data']['items'][0];
        
        // Required fields
        $requiredFields = ['id', 'name', 'species', 'owner_full_name'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $first)) {
                $errors[] = "Required field '$field' missing in patient items";
            }
        }
        
        // Check owner_full_name is string
        if (isset($first['owner_full_name'])) {
            if (!is_string($first['owner_full_name'])) {
                $errors[] = "Field 'owner_full_name' is " . gettype($first['owner_full_name']) . " (must be string)";
            } elseif (is_numeric($first['owner_full_name']) && $first['owner_full_name'] == '0') {
                $warnings[] = "Field 'owner_full_name' is '0' - should be empty string";
            } else {
                $success[] = "owner_full_name is string: '" . $first['owner_full_name'] . "'";
            }
        }
    }
}

// Check count field
if (!isset($data['count'])) {
    $errors[] = "Field 'count' is missing";
} elseif (!is_numeric($data['count'])) {
    $errors[] = "Field 'count' is not numeric";
} else {
    $success[] = "count = " . $data['count'];
}

// Output results
echo "VALIDATION RESULTS:\n";
echo str_repeat("-", 60) . "\n\n";

if (count($success) > 0) {
    echo "✅ SUCCESS CHECKS:\n";
    foreach ($success as $msg) {
        echo "   • $msg\n";
    }
    echo "\n";
}

if (count($warnings) > 0) {
    echo "⚠️  WARNINGS:\n";
    foreach ($warnings as $msg) {
        echo "   • $msg\n";
    }
    echo "\n";
}

if (count($errors) > 0) {
    echo "❌ ERRORS:\n";
    foreach ($errors as $msg) {
        echo "   • $msg\n";
    }
    echo "\n";
}

// Show expected vs actual structure
echo "EXPECTED JSON STRUCTURE:\n";
echo str_repeat("-", 60) . "\n";
echo json_encode([
    "status" => "success",
    "data" => [
        "items" => [
            ["id" => 1, "name" => "Luna", "owner_full_name" => "Max Mustermann", "..."]
        ]
    ],
    "count" => 1
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "ACTUAL JSON STRUCTURE:\n";
echo str_repeat("-", 60) . "\n";
if (isset($data['data']['items']) && count($data['data']['items']) > 0) {
    // Show only first item for brevity
    $display = $data;
    $display['data']['items'] = [$display['data']['items'][0]];
    echo json_encode($display, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

// Final verdict
echo "\n" . str_repeat("=", 60) . "\n";
if (count($errors) === 0) {
    echo "✅ API STRUCTURE IS CORRECT - Frontend compatibility confirmed!\n";
    exit(0);
} else {
    echo "❌ API STRUCTURE HAS ERRORS - Please fix the issues above\n";
    exit(1);
}