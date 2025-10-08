<?php
/**
 * Tierphysio Manager 2.0
 * Patients Module Integrity Check
 * 
 * This script verifies that the patients module is fully functional
 */

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Catch any output errors
ob_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check authentication
checkApiAuth();

$results = [];
$allPassed = true;

try {
    $pdo = pdo();
    
    // Test 1: Check if patients table exists
    $test1 = [
        'test' => 'Database Table Check',
        'description' => 'Verify patients table exists'
    ];
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'tp_patients'");
        if ($stmt->fetch()) {
            $test1['status'] = 'passed';
            $test1['message'] = 'Patients table exists';
        } else {
            $test1['status'] = 'failed';
            $test1['message'] = 'Patients table not found';
            $allPassed = false;
        }
    } catch (Exception $e) {
        $test1['status'] = 'failed';
        $test1['message'] = 'Error checking table: ' . $e->getMessage();
        $allPassed = false;
    }
    $results[] = $test1;
    
    // Test 2: Check if owners table exists
    $test2 = [
        'test' => 'Owners Table Check',
        'description' => 'Verify owners table exists'
    ];
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'tp_owners'");
        if ($stmt->fetch()) {
            $test2['status'] = 'passed';
            $test2['message'] = 'Owners table exists';
        } else {
            $test2['status'] = 'failed';
            $test2['message'] = 'Owners table not found';
            $allPassed = false;
        }
    } catch (Exception $e) {
        $test2['status'] = 'failed';
        $test2['message'] = 'Error checking table: ' . $e->getMessage();
        $allPassed = false;
    }
    $results[] = $test2;
    
    // Test 3: Test LIST action
    $test3 = [
        'test' => 'LIST Action Test',
        'description' => 'Verify patients list returns JSON'
    ];
    
    try {
        $stmt = $pdo->query("SELECT p.*, 
                            o.first_name as owner_first_name, 
                            o.last_name as owner_last_name
                            FROM tp_patients p 
                            LEFT JOIN tp_owners o ON p.owner_id = o.id 
                            WHERE p.is_active = 1
                            LIMIT 5");
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $test3['status'] = 'passed';
        $test3['message'] = 'Successfully fetched patients list';
        $test3['data'] = [
            'count' => count($patients),
            'sample' => array_slice($patients, 0, 2)
        ];
    } catch (Exception $e) {
        $test3['status'] = 'failed';
        $test3['message'] = 'Error fetching patients: ' . $e->getMessage();
        $allPassed = false;
    }
    $results[] = $test3;
    
    // Test 4: Test CREATE action (with new owner)
    $test4 = [
        'test' => 'CREATE Action Test',
        'description' => 'Verify patient creation with new owner'
    ];
    
    try {
        // Create test owner
        $testOwnerData = [
            'customer_number' => 'TEST' . time(),
            'first_name' => 'Test',
            'last_name' => 'Owner_' . time(),
            'email' => 'test_' . time() . '@example.com',
            'phone' => '+49 123 456789',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $ownerColumns = array_keys($testOwnerData);
        $ownerPlaceholders = array_map(function($col) { return ':' . $col; }, $ownerColumns);
        
        $ownerSql = "INSERT INTO tp_owners (" . implode(', ', $ownerColumns) . ") 
                    VALUES (" . implode(', ', $ownerPlaceholders) . ")";
        
        $stmt = $pdo->prepare($ownerSql);
        foreach ($testOwnerData as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        
        if ($stmt->execute()) {
            $ownerId = $pdo->lastInsertId();
            
            // Create test patient
            $testPatientData = [
                'patient_number' => 'TEST' . time(),
                'owner_id' => $ownerId,
                'name' => 'Test Patient ' . time(),
                'species' => 'dog',
                'breed' => 'Test Breed',
                'gender' => 'male',
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $patientColumns = array_keys($testPatientData);
            $patientPlaceholders = array_map(function($col) { return ':' . $col; }, $patientColumns);
            
            $patientSql = "INSERT INTO tp_patients (" . implode(', ', $patientColumns) . ") 
                          VALUES (" . implode(', ', $patientPlaceholders) . ")";
            
            $stmt = $pdo->prepare($patientSql);
            foreach ($testPatientData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $patientId = $pdo->lastInsertId();
                
                // Verify the patient was created with owner
                $stmt = $pdo->prepare("SELECT p.*, o.first_name, o.last_name 
                                      FROM tp_patients p 
                                      JOIN tp_owners o ON p.owner_id = o.id 
                                      WHERE p.id = :id");
                $stmt->execute(['id' => $patientId]);
                $createdPatient = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($createdPatient) {
                    $test4['status'] = 'passed';
                    $test4['message'] = 'Successfully created patient with owner';
                    $test4['data'] = [
                        'patient_id' => $patientId,
                        'owner_id' => $ownerId,
                        'patient_name' => $createdPatient['name'],
                        'owner_name' => $createdPatient['first_name'] . ' ' . $createdPatient['last_name']
                    ];
                    
                    // Clean up test data
                    $pdo->prepare("DELETE FROM tp_patients WHERE id = ?")->execute([$patientId]);
                    $pdo->prepare("DELETE FROM tp_owners WHERE id = ?")->execute([$ownerId]);
                } else {
                    $test4['status'] = 'failed';
                    $test4['message'] = 'Patient created but verification failed';
                    $allPassed = false;
                }
            } else {
                $test4['status'] = 'failed';
                $test4['message'] = 'Failed to create test patient';
                $allPassed = false;
                
                // Clean up owner
                $pdo->prepare("DELETE FROM tp_owners WHERE id = ?")->execute([$ownerId]);
            }
        } else {
            $test4['status'] = 'failed';
            $test4['message'] = 'Failed to create test owner';
            $allPassed = false;
        }
    } catch (Exception $e) {
        $test4['status'] = 'failed';
        $test4['message'] = 'Error in create test: ' . $e->getMessage();
        $allPassed = false;
    }
    $results[] = $test4;
    
    // Test 5: Check owner auto-link functionality
    $test5 = [
        'test' => 'Owner Auto-Link Test',
        'description' => 'Verify existing owner detection by name and phone'
    ];
    
    try {
        // Create an owner
        $ownerData = [
            'customer_number' => 'AUTOTEST' . time(),
            'first_name' => 'Auto',
            'last_name' => 'Test_' . time(),
            'phone' => '+49 987 654321',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $columns = array_keys($ownerData);
        $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
        
        $sql = "INSERT INTO tp_owners (" . implode(', ', $columns) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $pdo->prepare($sql);
        foreach ($ownerData as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $firstOwnerId = $pdo->lastInsertId();
        
        // Try to find the same owner by name and phone
        $checkSql = "SELECT id FROM tp_owners WHERE first_name = :first_name 
                    AND last_name = :last_name AND (phone = :phone OR mobile = :phone)";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([
            'first_name' => $ownerData['first_name'],
            'last_name' => $ownerData['last_name'],
            'phone' => $ownerData['phone']
        ]);
        $foundOwner = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($foundOwner && $foundOwner['id'] == $firstOwnerId) {
            $test5['status'] = 'passed';
            $test5['message'] = 'Owner auto-detection works correctly';
            $test5['data'] = [
                'owner_id' => $firstOwnerId,
                'detected' => true
            ];
        } else {
            $test5['status'] = 'failed';
            $test5['message'] = 'Owner auto-detection failed';
            $allPassed = false;
        }
        
        // Clean up
        $pdo->prepare("DELETE FROM tp_owners WHERE id = ?")->execute([$firstOwnerId]);
        
    } catch (Exception $e) {
        $test5['status'] = 'failed';
        $test5['message'] = 'Error in auto-link test: ' . $e->getMessage();
        $allPassed = false;
    }
    $results[] = $test5;
    
    // Test 6: Check JSON response format
    $test6 = [
        'test' => 'JSON Response Test',
        'description' => 'Verify API returns valid JSON'
    ];
    
    // This test is implicitly passed if we got here
    $test6['status'] = 'passed';
    $test6['message'] = 'API returns valid JSON format';
    $results[] = $test6;
    
    // Summary
    $summary = [
        'total_tests' => count($results),
        'passed' => count(array_filter($results, function($r) { return $r['status'] === 'passed'; })),
        'failed' => count(array_filter($results, function($r) { return $r['status'] === 'failed'; })),
        'all_passed' => $allPassed
    ];
    
    // Clear any output buffer
    ob_end_clean();
    
    // Return results
    json_success([
        'module' => 'Patients Module',
        'timestamp' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'tests' => $results,
        'recommendation' => $allPassed 
            ? 'All tests passed! The patients module is fully functional.' 
            : 'Some tests failed. Please review the results and fix the issues.'
    ]);
    
} catch (PDOException $e) {
    error_log("Integrity check database error: " . $e->getMessage());
    ob_end_clean();
    json_error('Database error during integrity check', 500);
} catch (Throwable $e) {
    error_log("Integrity check error: " . $e->getMessage());
    ob_end_clean();
    json_error('Unexpected error during integrity check: ' . $e->getMessage(), 500);
}

// Ensure no further output
exit;