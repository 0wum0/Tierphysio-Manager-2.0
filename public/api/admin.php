<?php
/**
 * Tierphysio Manager 2.0
 * Admin API Endpoint
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Catch any output errors
ob_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check authentication and admin rights
checkApiAuth();
if (!is_admin()) {
    ob_end_clean();
    json_error('Admin-Rechte erforderlich', 403);
}

// Get action from request
$action = $_REQUEST['action'] ?? 'stats';

try {
    $pdo = pdo();
    
    switch ($action) {
        case 'stats':
            // Get system statistics
            $stats = [];
            
            // Count users
            $stmt = $pdo->query("SELECT COUNT(*) as total, 
                                 SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
                                 SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
                                 FROM tp_users");
            $stats['users'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Count patients
            $stmt = $pdo->query("SELECT COUNT(*) as total,
                                 SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
                                 FROM tp_patients");
            $stats['patients'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Count owners
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM tp_owners");
            $stats['owners'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Count appointments
            $stmt = $pdo->query("SELECT COUNT(*) as total,
                                 SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                                 SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                                 SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                                 SUM(CASE WHEN appointment_date = CURDATE() THEN 1 ELSE 0 END) as today
                                 FROM tp_appointments");
            $stats['appointments'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Count treatments
            $stmt = $pdo->query("SELECT COUNT(*) as total,
                                 COUNT(DISTINCT patient_id) as unique_patients,
                                 COUNT(DISTINCT therapist_id) as unique_therapists
                                 FROM tp_treatments");
            $stats['treatments'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Count invoices
            $stmt = $pdo->query("SELECT COUNT(*) as total,
                                 SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                                 SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                                 SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                                 SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                                 SUM(total) as total_amount,
                                 SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) as paid_amount
                                 FROM tp_invoices");
            $stats['invoices'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            json_success($stats);
            break;
            
        case 'users':
            // Get all users (admin only)
            $sql = "SELECT id, username, email, first_name, last_name, role, 
                    phone, is_active, last_login, created_at, updated_at
                    FROM tp_users 
                    ORDER BY last_name, first_name";
            
            $stmt = $pdo->query($sql);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Remove sensitive data
            foreach ($users as &$user) {
                unset($user['password_hash']);
            }
            
            json_success($users);
            break;
            
        case 'user_toggle':
            // Toggle user active status
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            
            $userId = intval($data['user_id'] ?? 0);
            if (!$userId) {
                json_error('User ID fehlt', 400);
            }
            
            // Cannot deactivate yourself
            if ($userId == $_SESSION['user_id']) {
                json_error('Sie können sich nicht selbst deaktivieren', 400);
            }
            
            $stmt = $pdo->prepare("UPDATE tp_users SET is_active = NOT is_active, updated_at = NOW() WHERE id = :id");
            if ($stmt->execute(['id' => $userId])) {
                $stmt = $pdo->prepare("SELECT is_active FROM tp_users WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                json_success($user, 'Benutzer-Status erfolgreich geändert');
            } else {
                json_error('Fehler beim Ändern des Benutzer-Status', 500);
            }
            break;
            
        case 'backup':
            // Create database backup (structure only for demo)
            $tables = [];
            $stmt = $pdo->query("SHOW TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                if (strpos($row[0], 'tp_') === 0) {
                    $tables[] = $row[0];
                }
            }
            
            $backup = [
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $_SESSION['user']['username'],
                'tables' => $tables,
                'table_count' => count($tables),
                'note' => 'For full backup, use database management tools'
            ];
            
            json_success($backup, 'Backup-Information erstellt');
            break;
            
        case 'logs':
            // Get recent activity logs (simplified)
            $logs = [];
            
            // Recent logins
            $stmt = $pdo->query("SELECT 'login' as type, CONCAT(first_name, ' ', last_name) as user, 
                                last_login as timestamp 
                                FROM tp_users 
                                WHERE last_login IS NOT NULL 
                                ORDER BY last_login DESC 
                                LIMIT 10");
            $logs = array_merge($logs, $stmt->fetchAll(PDO::FETCH_ASSOC));
            
            // Recent appointments
            $stmt = $pdo->query("SELECT 'appointment' as type, 
                                CONCAT('Termin erstellt für Patient #', patient_id) as user,
                                created_at as timestamp 
                                FROM tp_appointments 
                                ORDER BY created_at DESC 
                                LIMIT 10");
            $logs = array_merge($logs, $stmt->fetchAll(PDO::FETCH_ASSOC));
            
            // Sort by timestamp
            usort($logs, function($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });
            
            json_success(array_slice($logs, 0, 20));
            break;
            
        default:
            json_error('Unbekannte Aktion: ' . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("API admin " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Datenbankfehler aufgetreten', 500);
} catch (Throwable $e) {
    error_log("API admin " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Unerwarteter Fehler: ' . $e->getMessage(), 500);
}

// Ensure no further output
exit;