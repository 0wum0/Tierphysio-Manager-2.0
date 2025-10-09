<?php
/**
 * Stats API
 * Provides system statistics and metrics for admin dashboard
 */

require_once __DIR__ . '/_bootstrap.php';

// Set JSON headers
header('Content-Type: application/json; charset=UTF-8');

// Admin-only endpoint
if (!$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Admin-Rechte erforderlich'
    ]);
    exit;
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => 'Nur GET-Methode erlaubt'
        ]);
        exit;
    }
    
    $type = $_GET['type'] ?? 'all';
    $data = [];
    
    switch ($type) {
        case 'overview':
            // Basic counts
            $data['patients'] = $db->query("SELECT COUNT(*) FROM tp_patients")->fetchColumn();
            $data['owners'] = $db->query("SELECT COUNT(*) FROM tp_owners")->fetchColumn();
            $data['appointments'] = $db->query("SELECT COUNT(*) FROM tp_appointments")->fetchColumn();
            $data['treatments'] = $db->query("SELECT COUNT(*) FROM tp_treatments")->fetchColumn();
            $data['invoices'] = $db->query("SELECT COUNT(*) FROM tp_invoices")->fetchColumn();
            $data['notes'] = $db->query("SELECT COUNT(*) FROM tp_notes")->fetchColumn();
            $data['users'] = $db->query("SELECT COUNT(*) FROM tp_users")->fetchColumn();
            $data['active_users'] = $db->query("SELECT COUNT(*) FROM tp_users WHERE is_active = 1")->fetchColumn();
            
            // Revenue calculation
            $revenue = $db->query("
                SELECT COALESCE(SUM(total_amount), 0) as revenue 
                FROM tp_invoices 
                WHERE status = 'paid'
            ")->fetchColumn();
            $data['revenue'] = number_format($revenue, 2, '.', '');
            
            break;
            
        case 'treatments':
            // Treatments last 7 days
            $stmt = $db->query("
                SELECT DATE(created_at) as date, COUNT(*) as count 
                FROM tp_treatments 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $data['daily'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Treatment types distribution
            $stmt = $db->query("
                SELECT treatment_type, COUNT(*) as count 
                FROM tp_treatments 
                GROUP BY treatment_type
                ORDER BY count DESC
                LIMIT 5
            ");
            $data['types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            break;
            
        case 'users':
            // User list with details
            $stmt = $db->query("
                SELECT 
                    u.*,
                    (SELECT MAX(created_at) FROM tp_activity_log WHERE user_id = u.id AND action = 'login') as last_login
                FROM tp_users u
                ORDER BY u.last_name, u.first_name
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Remove sensitive data
            foreach ($users as &$user) {
                unset($user['password_hash']);
                unset($user['remember_token']);
                $user['is_active'] = (bool) $user['is_active'];
            }
            
            $data = $users;
            break;
            
        case 'activity':
            // Recent activity log
            $stmt = $db->query("
                SELECT 
                    a.*,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name
                FROM tp_activity_log a
                LEFT JOIN tp_users u ON a.user_id = u.id
                ORDER BY a.created_at DESC
                LIMIT 50
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'database':
            // Database table information
            $tables = [];
            $stmt = $db->query("SHOW TABLE STATUS WHERE Name LIKE 'tp_%'");
            $tableInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($tableInfo as $table) {
                $tables[] = [
                    'name' => $table['Name'],
                    'rows' => $table['Rows'],
                    'size' => $this->formatBytes($table['Data_length'] + $table['Index_length']),
                    'engine' => $table['Engine']
                ];
            }
            
            $data = $tables;
            break;
            
        case 'charts':
            // Chart data for admin dashboard
            
            // Treatments over last 7 days
            $stmt = $db->query("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count
                FROM tp_treatments
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $treatmentData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fill in missing dates
            $treatments = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $found = false;
                foreach ($treatmentData as $td) {
                    if ($td['date'] === $date) {
                        $treatments[] = [
                            'date' => $date,
                            'label' => date('d.m', strtotime($date)),
                            'count' => (int) $td['count']
                        ];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $treatments[] = [
                        'date' => $date,
                        'label' => date('d.m', strtotime($date)),
                        'count' => 0
                    ];
                }
            }
            
            // Treatment types distribution
            $stmt = $db->query("
                SELECT 
                    COALESCE(treatment_type, 'Andere') as type,
                    COUNT(*) as count
                FROM tp_treatments
                GROUP BY treatment_type
                ORDER BY count DESC
                LIMIT 5
            ");
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $data = [
                'treatments' => $treatments,
                'types' => $types
            ];
            break;
            
        case 'all':
        default:
            // Combined data
            $data = [
                'overview' => $this->getOverviewStats($db),
                'charts' => $this->getChartData($db),
                'activity' => $this->getRecentActivity($db),
                'database' => $this->getDatabaseInfo($db)
            ];
            break;
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $data
    ]);
    
} catch (Exception $e) {
    error_log('Stats API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Serverfehler: ' . $e->getMessage()
    ]);
}

// Helper functions
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getOverviewStats($db) {
    return [
        'patients' => $db->query("SELECT COUNT(*) FROM tp_patients")->fetchColumn(),
        'appointments' => $db->query("SELECT COUNT(*) FROM tp_appointments")->fetchColumn(),
        'treatments' => $db->query("SELECT COUNT(*) FROM tp_treatments")->fetchColumn(),
        'users' => $db->query("SELECT COUNT(*) FROM tp_users")->fetchColumn(),
        'active_users' => $db->query("SELECT COUNT(*) FROM tp_users WHERE is_active = 1")->fetchColumn(),
        'revenue' => number_format($db->query("
            SELECT COALESCE(SUM(total_amount), 0) 
            FROM tp_invoices 
            WHERE status = 'paid'
        ")->fetchColumn(), 2, '.', '')
    ];
}

function getChartData($db) {
    // Treatments over last 7 days
    $stmt = $db->query("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as count
        FROM tp_treatments
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $treatmentData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fill in missing dates
    $treatments = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $found = false;
        foreach ($treatmentData as $td) {
            if ($td['date'] === $date) {
                $treatments[] = [
                    'date' => $date,
                    'label' => date('d.m', strtotime($date)),
                    'count' => (int) $td['count']
                ];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $treatments[] = [
                'date' => $date,
                'label' => date('d.m', strtotime($date)),
                'count' => 0
            ];
        }
    }
    
    // Treatment types
    $stmt = $db->query("
        SELECT 
            COALESCE(treatment_type, 'Andere') as type,
            COUNT(*) as count
        FROM tp_treatments
        GROUP BY treatment_type
        ORDER BY count DESC
        LIMIT 5
    ");
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'treatments' => $treatments,
        'types' => $types
    ];
}

function getRecentActivity($db) {
    $stmt = $db->query("
        SELECT 
            a.*,
            CONCAT(u.first_name, ' ', u.last_name) as user_name
        FROM tp_activity_log a
        LEFT JOIN tp_users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT 20
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDatabaseInfo($db) {
    $tables = [];
    $stmt = $db->query("SHOW TABLE STATUS WHERE Name LIKE 'tp_%'");
    $tableInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tableInfo as $table) {
        $tables[] = [
            'name' => $table['Name'],
            'rows' => $table['Rows'],
            'size' => formatBytes($table['Data_length'] + $table['Index_length'])
        ];
    }
    
    return $tables;
}