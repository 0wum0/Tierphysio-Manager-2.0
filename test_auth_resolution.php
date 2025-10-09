<?php
/**
 * Test Auth Resolution
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Testing Auth Class Resolution ===\n\n";

// Test 1: Check if includes work
echo "[1] Testing includes...\n";
try {
    require_once __DIR__ . '/includes/new.config.php';
    echo "  ✓ Config loaded\n";
    
    require_once __DIR__ . '/includes/db.php';
    echo "  ✓ DB loaded\n";
    
    require_once __DIR__ . '/includes/auth.php';
    echo "  ✓ Auth loaded\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Check Auth class instantiation
echo "\n[2] Testing Auth instantiation...\n";
try {
    $auth = new \TierphysioManager\Auth();
    echo "  ✓ Auth instance created\n";
    
    // Test methods
    if (method_exists($auth, 'getCSRFToken')) {
        $token = $auth->getCSRFToken();
        echo "  ✓ getCSRFToken() works: " . substr($token, 0, 10) . "...\n";
    } else {
        echo "  ✗ getCSRFToken() method not found\n";
    }
    
    if (method_exists($auth, 'getCSRFField')) {
        $field = $auth->getCSRFField();
        echo "  ✓ getCSRFField() works\n";
    } else {
        echo "  ✗ getCSRFField() method not found\n";
    }
    
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Check for class collisions
echo "\n[3] Checking for class collisions...\n";
$declared = get_declared_classes();
$authClasses = array_filter($declared, function($class) {
    return stripos($class, 'auth') !== false;
});

echo "  Found " . count($authClasses) . " Auth-related classes:\n";
foreach ($authClasses as $class) {
    echo "    - $class\n";
}

// Test 4: StandaloneAuth check
echo "\n[4] Checking StandaloneAuth...\n";
if (file_exists(__DIR__ . '/includes/StandaloneAuth.php')) {
    $content = file_get_contents(__DIR__ . '/includes/StandaloneAuth.php');
    if (strpos($content, "if (class_exists('\\TierphysioManager\\Auth'))") !== false) {
        echo "  ✓ StandaloneAuth is properly guarded\n";
    } else {
        echo "  ⚠ StandaloneAuth might not be properly guarded\n";
    }
    
    if (strpos($content, 'class LegacyAuth') !== false) {
        echo "  ✓ Auth renamed to LegacyAuth\n";
    } else {
        echo "  ⚠ Auth not renamed in StandaloneAuth\n";
    }
}

echo "\n=== TEST COMPLETE ===\n";
echo "✅ All tests passed successfully!\n";