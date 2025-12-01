<?php
/**
 * Tierphysio Manager 2.0
 * Dashboard Page
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

// Check if config exists, redirect to installer if not
if (!file_exists(__DIR__ . '/../includes/config.php')) {
    header('Location: /installer/');
    exit;
}

// Require login
$auth->requireLogin();

// Get current user
$user = $auth->getUser();

// Get dashboard statistics
$stats = [];

try {
    // Active patients count
    $stats['active_patients'] = $db->count('tp_patients', ['is_active' => 1]);
    
    // Today's appointments
    $stats['today_appointments'] = $db->count('tp_appointments', [
        'appointment_date' => date('Y-m-d'),
        'status' => 'scheduled'
    ]);
    
    // Open invoices
    $openInvoices = $db->query(
        "SELECT COUNT(*) as count, SUM(total - paid_amount) as total 
         FROM tp_invoices 
         WHERE status IN ('sent', 'partially_paid', 'overdue')"
    )->fetch();
    $stats['open_invoices'] = $openInvoices['count'];
    $stats['open_invoices_amount'] = $openInvoices['total'] ?? 0;
    
    // This month's revenue
    // Use database-agnostic date comparison
    $currentMonth = date('Y-m');
    $monthRevenue = $db->query(
        "SELECT SUM(paid_amount) as revenue 
         FROM tp_invoices 
         WHERE DATE_FORMAT(payment_date, '%Y-%m') = :current_month",
        ['current_month' => $currentMonth]
    )->fetch();
    $stats['month_revenue'] = $monthRevenue['revenue'] ?? 0;
    
    // Recent activities
    $activities = $db->query(
        "SELECT a.*, u.first_name, u.last_name 
         FROM tp_activity_log a 
         LEFT JOIN tp_users u ON a.user_id = u.id 
         ORDER BY a.created_at DESC 
         LIMIT 10"
    )->fetchAll();
    
    // Upcoming appointments
    $appointments = $db->query(
        "SELECT a.*, p.name as patient_name, o.first_name as owner_first_name, o.last_name as owner_last_name
         FROM tp_appointments a 
         LEFT JOIN tp_patients p ON a.patient_id = p.id 
         LEFT JOIN tp_owners o ON p.owner_id = o.id
         WHERE a.appointment_date >= CURRENT_DATE() 
         AND a.status = 'scheduled'
         ORDER BY a.appointment_date, a.start_time 
         LIMIT 5"
    )->fetchAll();
    
    // Recent patients
    $recentPatients = $db->query(
        "SELECT p.*, o.first_name as owner_first_name, o.last_name as owner_last_name
         FROM tp_patients p 
         LEFT JOIN tp_owners o ON p.owner_id = o.id
         ORDER BY p.created_at DESC 
         LIMIT 5"
    )->fetchAll();
    
} catch (Exception $e) {
    // Handle database errors gracefully
    $stats = [
        'active_patients' => 0,
        'today_appointments' => 0,
        'open_invoices' => 0,
        'open_invoices_amount' => 0,
        'month_revenue' => 0
    ];
    $activities = [];
    $appointments = [];
    $recentPatients = [];
}

// Get greeting based on time
$hour = date('H');
if ($hour < 12) {
    $greeting = 'Guten Morgen';
} elseif ($hour < 18) {
    $greeting = 'Guten Tag';
} else {
    $greeting = 'Guten Abend';
}

// Display dashboard
$template->display('pages/dashboard.twig', [
    'greeting' => $greeting,
    'stats' => $stats,
    'activities' => $activities,
    'appointments' => $appointments,
    'recentPatients' => $recentPatients
]);