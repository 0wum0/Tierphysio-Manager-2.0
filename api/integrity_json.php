<?php
/**
 * Tierphysio Manager 2.0
 * Integrity Check API Endpoint - Unified JSON Response Format
 */

require_once __DIR__ . '/_bootstrap.php';

try {
    $pdo = get_pdo();
    
    // Define tables to check
    $tables = [
        'tp_users',
        'tp_owners',
        'tp_patients',
        'tp_appointments',
        'tp_treatments',
        'tp_invoices',
        'tp_notes'
    ];
    
    $checks = [];
    $allOk = true;
    
    // Check each table
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
            $count = $stmt->fetchColumn();
            
            $checks[] = [
                'check' => $table,
                'ok' => true,
                'count' => (int)$count
            ];
        } catch (PDOException $e) {
            $checks[] = [
                'check' => $table,
                'ok' => false,
                'error' => $e->getMessage()
            ];
            $allOk = false;
        }
    }
    
    // Add overall status check
    $checks[] = [
        'check' => 'overall',
        'ok' => $allOk,
        'tables_checked' => count($tables)
    ];
    
    api_success(['items' => $checks, 'count' => count($checks)]);
    
} catch (PDOException $e) {
    error_log("Integrity JSON API Error: " . $e->getMessage());
    api_error('Datenbankfehler bei Integritätsprüfung');
} catch (Throwable $e) {
    error_log("Integrity JSON API Error: " . $e->getMessage());
    api_error('Fehler bei Integritätsprüfung');
}