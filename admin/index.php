<?php
/**
 * Admin Index - Dashboard Entry Point
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/template.php';

// Auth ist bereits in bootstrap.php erstellt
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header('Location: /admin/login.php');
    exit;
}

// Generiere CSRF-Token
$csrf_token = $auth->generateCSRFToken();

// Sammle Dashboard-Statistiken
$stats = [];

try {
    // System-Statistiken
    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_users WHERE is_active = 1");
    $stats['users_count'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_patients WHERE deleted_at IS NULL");
    $stats['patients_count'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_owners WHERE deleted_at IS NULL");
    $stats['owners_count'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_appointments WHERE appointment_date >= CURDATE()");
    $stats['upcoming_appointments'] = $stmt->fetchColumn();

    // Letzte Aktivitäten
    $stmt = $pdo->query("
        SELECT 'patient' as type, name, created_at 
        FROM tp_patients 
        WHERE deleted_at IS NULL
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 'appointment' as type, 
               CONCAT(p.name, ' - ', a.appointment_date) as name,
               a.created_at
        FROM tp_appointments a
        JOIN tp_patients p ON a.patient_id = p.id
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $recent_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Kombiniere die letzten Aktivitäten
    $recent_activities = array_merge($recent_patients, $recent_appointments);
    usort($recent_activities, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $recent_activities = array_slice($recent_activities, 0, 10);

} catch (Exception $e) {
    error_log("Admin Dashboard Error: " . $e->getMessage());
    $stats = [
        'users_count' => 0,
        'patients_count' => 0,
        'owners_count' => 0,
        'upcoming_appointments' => 0
    ];
    $recent_activities = [];
}

// System-Info
$system_info = [
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time') . 's'
];

// Template-Daten vorbereiten
$templateData = [
    'title' => 'Admin Dashboard',
    'csrf_token' => $csrf_token,
    'stats' => $stats,
    'recent_activities' => $recent_activities,
    'system_info' => $system_info,
    'user' => $auth->getUser(),
    'session' => $_SESSION,
    'is_admin' => true
];

// Dashboard-Template rendern
echo render_template('admin/dashboard.twig', $templateData);