<?php
/**
 * Tierphysio Manager 2.0
 * Database Integrity Check
 * 
 * Prüft ob alle erforderlichen Tabellen existieren und zählt Einträge
 */

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/db.php';

// List of required tables
$required_tables = [
    'tp_users',
    'tp_owners',
    'tp_patients',
    'tp_invoices',
    'tp_invoice_items',
    'tp_treatments',
    'tp_appointments',
    'tp_notes',
    'tp_documents',
    'tp_settings',
    'tp_sessions',
    'tp_activity_log',
    'tp_migrations'
];

$results = [
    'ok' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'database' => [
        'connected' => false,
        'name' => DB_NAME,
        'host' => DB_HOST
    ],
    'tables' => [],
    'counts' => [],
    'missing_tables' => [],
    'errors' => []
];

try {
    // Test database connection
    $pdo = get_pdo();
    $results['database']['connected'] = true;
    
    // Get all tables with tp_ prefix
    $stmt = $pdo->query("SHOW TABLES LIKE 'tp_%'");
    $existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Check each required table
    foreach ($required_tables as $table) {
        $exists = in_array($table, $existing_tables);
        $results['tables'][$table] = $exists;
        
        if (!$exists) {
            $results['missing_tables'][] = $table;
            $results['ok'] = false;
        } else {
            // Count entries in existing tables
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
                $count = $stmt->fetch()['count'];
                $results['counts'][$table] = $count;
            } catch (Exception $e) {
                $results['counts'][$table] = 'error';
                $results['errors'][] = "Could not count entries in $table: " . $e->getMessage();
            }
        }
    }
    
    // Check table relationships (foreign keys)
    $relationships = [
        'tp_patients.owner_id' => 'tp_owners.id',
        'tp_appointments.patient_id' => 'tp_patients.id',
        'tp_treatments.patient_id' => 'tp_patients.id',
        'tp_invoices.owner_id' => 'tp_owners.id',
        'tp_invoice_items.invoice_id' => 'tp_invoices.id'
    ];
    
    $results['relationships'] = [];
    
    foreach ($relationships as $source => $target) {
        list($source_table, $source_column) = explode('.', $source);
        list($target_table, $target_column) = explode('.', $target);
        
        if (in_array($source_table, $existing_tables) && in_array($target_table, $existing_tables)) {
            // Check for orphaned records
            try {
                $sql = "SELECT COUNT(*) as count FROM `$source_table` s 
                        LEFT JOIN `$target_table` t ON s.`$source_column` = t.`$target_column` 
                        WHERE s.`$source_column` IS NOT NULL AND t.`$target_column` IS NULL";
                $stmt = $pdo->query($sql);
                $orphans = $stmt->fetch()['count'];
                
                $results['relationships'][$source] = [
                    'target' => $target,
                    'orphaned_records' => $orphans,
                    'ok' => $orphans === 0
                ];
                
                if ($orphans > 0) {
                    $results['errors'][] = "Found $orphans orphaned records in $source referencing non-existent $target";
                }
            } catch (Exception $e) {
                $results['relationships'][$source] = [
                    'target' => $target,
                    'error' => $e->getMessage()
                ];
            }
        }
    }
    
    // Check for common data integrity issues
    $integrity_checks = [];
    
    // Check for duplicate customer numbers
    if (in_array('tp_owners', $existing_tables)) {
        try {
            $stmt = $pdo->query("SELECT customer_number, COUNT(*) as count 
                                FROM tp_owners 
                                GROUP BY customer_number 
                                HAVING count > 1");
            $duplicates = $stmt->fetchAll();
            $integrity_checks['duplicate_customer_numbers'] = [
                'count' => count($duplicates),
                'ok' => count($duplicates) === 0
            ];
            if (count($duplicates) > 0) {
                $results['errors'][] = "Found " . count($duplicates) . " duplicate customer numbers";
            }
        } catch (Exception $e) {
            $integrity_checks['duplicate_customer_numbers'] = ['error' => $e->getMessage()];
        }
    }
    
    // Check for duplicate patient numbers
    if (in_array('tp_patients', $existing_tables)) {
        try {
            $stmt = $pdo->query("SELECT patient_number, COUNT(*) as count 
                                FROM tp_patients 
                                GROUP BY patient_number 
                                HAVING count > 1");
            $duplicates = $stmt->fetchAll();
            $integrity_checks['duplicate_patient_numbers'] = [
                'count' => count($duplicates),
                'ok' => count($duplicates) === 0
            ];
            if (count($duplicates) > 0) {
                $results['errors'][] = "Found " . count($duplicates) . " duplicate patient numbers";
            }
        } catch (Exception $e) {
            $integrity_checks['duplicate_patient_numbers'] = ['error' => $e->getMessage()];
        }
    }
    
    $results['integrity_checks'] = $integrity_checks;
    
    // Summary
    $results['summary'] = [
        'total_tables_required' => count($required_tables),
        'total_tables_found' => count($existing_tables),
        'missing_tables_count' => count($results['missing_tables']),
        'total_errors' => count($results['errors']),
        'database_ok' => $results['database']['connected'] && empty($results['missing_tables']),
        'integrity_ok' => empty($results['errors'])
    ];
    
    // Set overall status
    $results['ok'] = $results['summary']['database_ok'] && $results['summary']['integrity_ok'];
    
} catch (PDOException $e) {
    $results['ok'] = false;
    $results['database']['connected'] = false;
    $results['errors'][] = "Database connection failed: " . $e->getMessage();
} catch (Exception $e) {
    $results['ok'] = false;
    $results['errors'][] = "Unexpected error: " . $e->getMessage();
}

// Output results
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;