<?php
/**
 * Test script for patients API alignment
 */

// Test API directly
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

try {
    $pdo = get_pdo();
    
    // Test list endpoint
    echo "Testing /api/patients.php?action=list\n";
    echo "=====================================\n\n";
    
    $_GET['action'] = 'list';
    ob_start();
    include __DIR__ . '/api/patients.php';
    $output = ob_get_clean();
    
    $data = json_decode($output, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "❌ JSON decode error: " . json_last_error_msg() . "\n";
        echo "Raw output: " . substr($output, 0, 500) . "\n";
    } else {
        echo "✅ JSON valid\n";
        echo "Status: " . ($data['status'] ?? 'MISSING') . "\n";
        echo "Structure:\n";
        
        // Check expected structure
        if (isset($data['status']) && $data['status'] === 'success') {
            echo "  ✅ status = 'success'\n";
        } else {
            echo "  ❌ status missing or not 'success'\n";
        }
        
        if (isset($data['data']['items']) && is_array($data['data']['items'])) {
            echo "  ✅ data.items is array\n";
            echo "  Items count: " . count($data['data']['items']) . "\n";
            
            if (count($data['data']['items']) > 0) {
                $first = $data['data']['items'][0];
                echo "\n  First item structure:\n";
                foreach ($first as $key => $value) {
                    $type = gettype($value);
                    if ($key === 'owner_full_name') {
                        if (is_string($value)) {
                            echo "    ✅ $key: '$value' (string)\n";
                        } else {
                            echo "    ❌ $key: '$value' ($type - should be string!)\n";
                        }
                    } else {
                        echo "    - $key: " . (is_null($value) ? 'null' : "'$value'") . " ($type)\n";
                    }
                }
            }
        } else {
            echo "  ❌ data.items missing or not array\n";
        }
        
        if (isset($data['count'])) {
            echo "  ✅ count = " . $data['count'] . "\n";
        } else {
            echo "  ❌ count missing\n";
        }
        
        echo "\nJSON Output (pretty):\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}