<?php
/**
 * Tierphysio Manager 2.0
 * Integrity Check API Endpoint - Returns standardized JSON response
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Error reporting for production
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Clear any existing output
if (ob_get_length()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../includes/db.php';

// API Helper Functions
function api_success($data = [], $extra = []) {
    if (ob_get_length()) ob_end_clean();
    $response = array_merge(['status' => 'success', 'data' => $data], $extra);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error($message = 'Unbekannter Fehler', $code = 400, $extra = []) {
    if (ob_get_length()) ob_end_clean();
    http_response_code($code);
    $response = array_merge(['status' => 'error', 'message' => $message], $extra);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Get database connection to verify it's working
    $pdo = get_pdo();
    
    // Run basic integrity checks
    $checks = [];
    $all_passed = true;
    
    // Check 1: Database connection
    $checks['database'] = true;
    
    // Check 2: Required tables exist
    $required_tables = [
        'tp_patients',
        'tp_owners', 
        'tp_appointments',
        'tp_treatments',
        'tp_invoices',
        'tp_invoice_items',
        'tp_notes',
        'tp_users'
    ];
    
    $tables_check = true;
    foreach ($required_tables as $table) {
        try {
            $stmt = $pdo->query("SELECT 1 FROM $table LIMIT 1");
            if (!$stmt) {
                $tables_check = false;
                break;
            }
        } catch (PDOException $e) {
            $tables_check = false;
            break;
        }
    }
    $checks['tables'] = $tables_check;
    $all_passed = $all_passed && $tables_check;
    
    // Check 3: Can write to session
    @session_start();
    $_SESSION['integrity_test'] = time();
    $checks['session'] = isset($_SESSION['integrity_test']);
    unset($_SESSION['integrity_test']);
    
    // Check 4: API endpoints are accessible
    $api_endpoints = [
        'patients',
        'owners',
        'appointments',
        'treatments',
        'invoices',
        'notes'
    ];
    
    $endpoints_check = true;
    foreach ($api_endpoints as $endpoint) {
        if (!file_exists(__DIR__ . '/' . $endpoint . '.php')) {
            $endpoints_check = false;
            break;
        }
    }
    $checks['endpoints'] = $endpoints_check;
    $all_passed = $all_passed && $endpoints_check;
    
    // Check 5: Required directories exist and are writable
    $required_dirs = [
        __DIR__ . '/../uploads',
        __DIR__ . '/../logs'
    ];
    
    $dirs_check = true;
    foreach ($required_dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_writable($dir)) {
            $dirs_check = false;
            break;
        }
    }
    $checks['directories'] = $dirs_check;
    $all_passed = $all_passed && $dirs_check;
    
    // Check 6: PHP version
    $checks['php_version'] = version_compare(PHP_VERSION, '7.4.0', '>=');
    $all_passed = $all_passed && $checks['php_version'];
    
    // Check 7: Required PHP extensions
    $required_extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring'];
    $extensions_check = true;
    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $extensions_check = false;
            break;
        }
    }
    $checks['extensions'] = $extensions_check;
    $all_passed = $all_passed && $checks['extensions'];
    
    // Return success response with integrity data
    api_success([
        'integrity' => $all_passed ? 'ok' : 'failed',
        'tests' => count($checks),
        'passed' => count(array_filter($checks)),
        'checks' => $checks,
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'system' => PHP_OS
    ]);
    
} catch (PDOException $e) {
    error_log("Integrity Check PDO Error: " . $e->getMessage());
    api_error('Datenbankverbindung fehlgeschlagen', 500);
} catch (Throwable $e) {
    error_log("Integrity Check Error: " . $e->getMessage());
    api_error('Integritätsprüfung fehlgeschlagen', 500);
}

// Should never reach here
exit;