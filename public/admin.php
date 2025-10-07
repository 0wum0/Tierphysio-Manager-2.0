<?php
/**
 * Tierphysio Manager 2.0
 * Admin Panel
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance();
$template = Template::getInstance();

// Require admin rights
$auth->requireLogin();
if (!$auth->isAdmin()) {
    Template::setFlash('error', 'Zugriff verweigert. Admin-Rechte erforderlich.');
    header('Location: index.php');
    exit;
}

// Get current user
$user = $auth->getUser();

// Get action
$action = $_GET['action'] ?? 'overview';

// Initialize data array for template
$data = [
    'page_title' => 'Admin-Panel',
    'user' => $user,
    'action' => $action,
    'csrf_token' => $auth->getCSRFToken()
];

// Process based on action
switch ($action) {
    case 'overview':
    default:
        // Get system statistics
        $data['stats'] = [
            'users' => $db->count('tp_users'),
            'active_users' => $db->count('tp_users', ['is_active' => 1]),
            'patients' => $db->count('tp_patients'),
            'owners' => $db->count('tp_owners'),
            'appointments' => $db->count('tp_appointments'),
            'treatments' => $db->count('tp_treatments'),
            'invoices' => $db->count('tp_invoices'),
            'notes' => $db->count('tp_notes')
        ];
        
        // Get recent activities
        $data['recent_activities'] = $db->query(
            "SELECT a.*, u.first_name, u.last_name 
             FROM tp_activity_log a 
             LEFT JOIN tp_users u ON a.user_id = u.id 
             ORDER BY a.created_at DESC 
             LIMIT 20"
        )->fetchAll();
        
        // Get system settings
        $data['settings'] = $db->query(
            "SELECT * FROM tp_settings ORDER BY category, `key`"
        )->fetchAll();
        break;
        
    case 'users':
        // Get all users
        $data['users'] = $db->query(
            "SELECT u.*, 
             (SELECT MAX(last_login) FROM tp_users WHERE id = u.id) as last_login
             FROM tp_users u 
             ORDER BY u.last_name, u.first_name"
        )->fetchAll();
        break;
        
    case 'settings':
        // Get all settings grouped by category
        $settings = $db->query(
            "SELECT * FROM tp_settings ORDER BY category, `key`"
        )->fetchAll();
        
        $data['settings_grouped'] = [];
        foreach ($settings as $setting) {
            $data['settings_grouped'][$setting['category']][] = $setting;
        }
        break;
        
    case 'backup':
        // Backup functionality placeholder
        $data['message'] = 'Backup-Funktionalität wird in einer späteren Version implementiert.';
        break;
        
    case 'logs':
        // Get system logs
        $data['logs'] = $db->query(
            "SELECT a.*, u.first_name, u.last_name 
             FROM tp_activity_log a 
             LEFT JOIN tp_users u ON a.user_id = u.id 
             ORDER BY a.created_at DESC 
             LIMIT 100"
        )->fetchAll();
        break;
}

// Display template
$template->display('pages/admin.twig', $data);