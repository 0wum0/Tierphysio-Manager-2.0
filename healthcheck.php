<?php
/**
 * Tierphysio Manager 2.0 - System Health Check
 * Prüft die Systemintegrität und gibt einen Status zurück
 */

header('Content-Type: application/json; charset=utf-8');

$health = [
    'status' => 'healthy',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => [],
    'errors' => []
];

// 1. PHP Version Check
$health['checks']['php_version'] = [
    'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'error',
    'value' => PHP_VERSION,
    'required' => '7.4.0+'
];

// 2. Required Extensions
$required_extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl'];
foreach ($required_extensions as $ext) {
    $health['checks']['extension_' . $ext] = [
        'status' => extension_loaded($ext) ? 'ok' : 'error',
        'loaded' => extension_loaded($ext)
    ];
}

// 3. Config File Check
$health['checks']['config_file'] = [
    'status' => file_exists(__DIR__ . '/includes/config.php') ? 'ok' : 'error',
    'exists' => file_exists(__DIR__ . '/includes/config.php')
];

// 4. Database Connection
try {
    if (file_exists(__DIR__ . '/includes/config.php')) {
        require_once __DIR__ . '/includes/db.php';
        $pdo = pdo();
        
        // Test query
        $stmt = $pdo->query("SELECT 1");
        $health['checks']['database'] = [
            'status' => 'ok',
            'connected' => true
        ];
        
        // 5. Table Checks
        $required_tables = [
            'tp_users', 'tp_owners', 'tp_patients', 'tp_appointments', 
            'tp_treatments', 'tp_invoices', 'tp_invoice_items', 'tp_notes',
            'tp_documents', 'tp_settings', 'tp_activity_log', 'tp_sessions', 
            'tp_migrations'
        ];
        
        $missing_tables = [];
        foreach ($required_tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if (!$stmt->fetch()) {
                $missing_tables[] = $table;
            }
        }
        
        $health['checks']['database_tables'] = [
            'status' => empty($missing_tables) ? 'ok' : 'warning',
            'total_required' => count($required_tables),
            'missing' => $missing_tables,
            'missing_count' => count($missing_tables)
        ];
        
        // 6. Data Statistics
        try {
            $stats = [];
            
            // Owners count
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_owners");
            $result = $stmt->fetch();
            $stats['owners'] = $result['count'];
            
            // Patients count
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_patients WHERE is_active = 1");
            $result = $stmt->fetch();
            $stats['active_patients'] = $result['count'];
            
            // Today's appointments
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_appointments WHERE appointment_date = ?");
            $stmt->execute([date('Y-m-d')]);
            $result = $stmt->fetch();
            $stats['todays_appointments'] = $result['count'];
            
            // Open invoices
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_invoices WHERE status IN ('sent', 'partially_paid', 'overdue')");
            $result = $stmt->fetch();
            $stats['open_invoices'] = $result['count'];
            
            $health['statistics'] = $stats;
            
        } catch (Exception $e) {
            $health['statistics'] = ['error' => 'Could not fetch statistics'];
        }
        
        // 7. Last Migration Check
        try {
            $stmt = $pdo->query("SELECT version, name, executed_at FROM tp_migrations ORDER BY version DESC LIMIT 1");
            $last_migration = $stmt->fetch();
            
            $health['checks']['migrations'] = [
                'status' => 'ok',
                'last_migration' => $last_migration ?: 'none'
            ];
        } catch (Exception $e) {
            $health['checks']['migrations'] = [
                'status' => 'warning',
                'error' => 'Could not check migrations'
            ];
        }
        
    } else {
        $health['checks']['database'] = [
            'status' => 'error',
            'connected' => false,
            'error' => 'Config file not found'
        ];
    }
} catch (Exception $e) {
    $health['checks']['database'] = [
        'status' => 'error',
        'connected' => false,
        'error' => $e->getMessage()
    ];
}

// 8. Writable Directories Check
$writable_dirs = [
    'cache' => __DIR__ . '/cache',
    'logs' => __DIR__ . '/logs',
    'uploads' => __DIR__ . '/public/uploads',
    'backups' => __DIR__ . '/backups'
];

foreach ($writable_dirs as $name => $dir) {
    $health['checks']['writable_' . $name] = [
        'status' => is_writable($dir) ? 'ok' : 'warning',
        'writable' => is_writable($dir),
        'exists' => file_exists($dir)
    ];
}

// 9. Memory Usage
$health['checks']['memory'] = [
    'status' => 'ok',
    'current' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
    'peak' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
    'limit' => ini_get('memory_limit')
];

// 10. Disk Space
$free_space = disk_free_space(__DIR__);
$total_space = disk_total_space(__DIR__);
$used_percentage = round((($total_space - $free_space) / $total_space) * 100, 2);

$health['checks']['disk_space'] = [
    'status' => $used_percentage < 90 ? 'ok' : 'warning',
    'free' => round($free_space / 1024 / 1024 / 1024, 2) . ' GB',
    'total' => round($total_space / 1024 / 1024 / 1024, 2) . ' GB',
    'used_percentage' => $used_percentage . '%'
];

// Calculate overall status
$has_error = false;
$has_warning = false;

foreach ($health['checks'] as $check) {
    if ($check['status'] === 'error') {
        $has_error = true;
        break;
    } elseif ($check['status'] === 'warning') {
        $has_warning = true;
    }
}

if ($has_error) {
    $health['status'] = 'unhealthy';
    http_response_code(503);
} elseif ($has_warning) {
    $health['status'] = 'degraded';
    http_response_code(200);
} else {
    $health['status'] = 'healthy';
    http_response_code(200);
}

// Output
echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);