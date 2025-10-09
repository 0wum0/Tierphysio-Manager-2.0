<?php
/**
 * Admin Dashboard
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new StandaloneAuth($pdo);

// Require admin login
$auth->requireLogin();

// Check admin role
$userId = $auth->getUserId();
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM tp_user_roles ur 
    JOIN tp_roles r ON ur.role_id = r.id 
    WHERE ur.user_id = ? AND r.name = 'admin'
");
$stmt->execute([$userId]);

if ($stmt->fetchColumn() == 0) {
    header('Location: /admin/login.php');
    exit;
}

// Generate CSRF token
$csrf_token = $_SESSION['admin']['csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['admin']['csrf_token'] = $csrf_token;
}

// Gather dashboard statistics
$stats = [];

// System stats
$stmt = $pdo->query("SELECT COUNT(*) FROM tp_users");
$stats['users_count'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM tp_patients");
$stats['patients_count'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM tp_appointments WHERE date >= CURDATE()");
$stats['upcoming_appointments'] = $stmt->fetchColumn();

// Backup stats
$stmt = $pdo->query("SELECT COUNT(*) as count, MAX(created_at) as latest FROM tp_backups");
$backup_info = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['backups_count'] = $backup_info['count'];
$stats['latest_backup'] = $backup_info['latest'];

// Cron stats
$stmt = $pdo->query("SELECT COUNT(*) FROM tp_cron_jobs WHERE is_active = 1");
$stats['active_cron_jobs'] = $stmt->fetchColumn();

// Module stats
$stmt = $pdo->query("SELECT COUNT(*) FROM tp_modules WHERE enabled = 1");
$stats['active_modules'] = $stmt->fetchColumn();

// Get recent activities (last 10 cron logs)
$stmt = $pdo->query("
    SELECT cl.*, cj.key as job_name 
    FROM tp_cron_logs cl
    JOIN tp_cron_jobs cj ON cl.job_id = cj.id
    ORDER BY cl.started_at DESC
    LIMIT 10
");
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get system info
$system_info = [
    'php_version' => PHP_VERSION,
    'db_version' => $pdo->query("SELECT VERSION()")->fetchColumn(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time') . 's'
];

// Check for pending updates
$pending_migrations = [];
$migrations_dir = __DIR__ . '/../migrations';
if (is_dir($migrations_dir)) {
    $files = glob($migrations_dir . '/*.sql');
    foreach ($files as $file) {
        $migration_name = basename($file);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tp_migrations WHERE migration = ?");
        $stmt->execute([$migration_name]);
        if ($stmt->fetchColumn() == 0) {
            $pending_migrations[] = $migration_name;
        }
    }
}
$stats['pending_updates'] = count($pending_migrations);

// Prepare template data
$templateData = [
    'title' => 'Dashboard',
    'csrf_token' => $csrf_token,
    'stats' => $stats,
    'recent_activities' => $recent_activities,
    'system_info' => $system_info,
    'pending_migrations' => $pending_migrations,
    'user' => $auth->getUser()
];

// Render dashboard template
echo $twig->render('admin/pages/dashboard.twig', $templateData);