<?php
/**
 * Tierphysio Manager 2.0
 * API Router
 */

require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;

// Set JSON headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance();

// Get route from request
$route = $_GET['route'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Parse route
$routeParts = explode('/', trim($route, '/'));
$resource = $routeParts[0] ?? '';
$id = $routeParts[1] ?? null;
$action = $routeParts[2] ?? null;

// API response helper
function apiResponse($data = null, $status = 200, $message = null) {
    http_response_code($status);
    $response = ['success' => $status < 400];
    
    if ($message) {
        $response['message'] = $message;
    }
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}

// API error helper
function apiError($message, $status = 400) {
    apiResponse(null, $status, $message);
}

// Check authentication for protected endpoints
// DISABLED FOR TESTING - All endpoints are public
/*
$publicEndpoints = ['auth/login', 'auth/register', 'auth/forgot-password', 'health'];
$currentEndpoint = $resource . ($action ? '/' . $action : '');

if (!in_array($currentEndpoint, $publicEndpoints) && !$auth->isLoggedIn()) {
    apiError('Unauthorized', 401);
}
*/

// Route handling
try {
    switch ($resource) {
        case 'health':
            apiResponse(['status' => 'ok', 'version' => APP_VERSION]);
            break;
            
        case 'integrity_json':
            // Integrity check endpoint
            try {
                require_once __DIR__ . '/../includes/db.php';
                
                // First ensure tp_notes table exists
                $db->exec("CREATE TABLE IF NOT EXISTS tp_notes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    patient_id INT NOT NULL,
                    user_id INT DEFAULT 1,
                    note_type VARCHAR(50) DEFAULT 'general',
                    content TEXT NOT NULL,
                    is_important TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_patient (patient_id),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $tables = [
                    'tp_users','tp_owners','tp_patients',
                    'tp_appointments','tp_treatments',
                    'tp_invoices','tp_notes'
                ];
                $db->query("SET NAMES utf8mb4");

                $stats = [];
                foreach ($tables as $tbl) {
                    $stmt = $db->query("SELECT COUNT(*) AS cnt FROM `$tbl`");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stats[$tbl] = intval($row['cnt']);
                }

                echo json_encode([
                    'ok' => true,
                    'status' => 'success',
                    'data' => [
                        'items' => [[
                            'check' => 'db',
                            'ok' => true,
                            'checked_tables' => count($tables),
                            'table_stats' => $stats
                        ]],
                        'count' => 1
                    ]
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            } catch (Throwable $e) {
                apiError('Integritätsprüfung fehlgeschlagen: '.$e->getMessage(), 500);
            }
            break;
            
        case 'auth':
            require_once __DIR__ . '/auth/index.php';
            break;
            
        case 'patients':
            require_once __DIR__ . '/patients/index.php';
            break;
            
        case 'owners':
            require_once __DIR__ . '/owners/index.php';
            break;
            
        case 'appointments':
            require_once __DIR__ . '/appointments/index.php';
            break;
            
        case 'treatments':
            require_once __DIR__ . '/treatments/index.php';
            break;
            
        case 'invoices':
            require_once __DIR__ . '/invoices/index.php';
            break;
            
        case 'notes':
            require_once __DIR__ . '/notes/index.php';
            break;
            
        case 'settings':
            if (!$auth->isAdmin()) {
                apiError('Forbidden', 403);
            }
            require_once __DIR__ . '/admin/settings.php';
            break;
            
        case 'statistics':
            require_once __DIR__ . '/statistics/index.php';
            break;
            
        case 'search':
            require_once __DIR__ . '/search/index.php';
            break;
            
        default:
            apiError('Endpoint not found', 404);
    }
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    apiError('Internal server error', 500);
}