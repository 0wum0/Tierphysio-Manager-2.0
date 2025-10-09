<?php
/**
 * Admin Cron Jobs Management
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new StandaloneAuth($pdo);
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

// Get all cron jobs
$stmt = $pdo->query("
    SELECT cj.*, 
           (SELECT COUNT(*) FROM tp_cron_logs WHERE job_id = cj.id) as log_count,
           (SELECT MAX(started_at) FROM tp_cron_logs WHERE job_id = cj.id) as last_log_date
    FROM tp_cron_jobs cj
    ORDER BY cj.key
");
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent cron logs (last 50)
$stmt = $pdo->query("
    SELECT cl.*, cj.key as job_name
    FROM tp_cron_logs cl
    JOIN tp_cron_jobs cj ON cl.job_id = cj.id
    ORDER BY cl.started_at DESC
    LIMIT 50
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare template data
$templateData = [
    'title' => 'Cron Jobs',
    'csrf_token' => $csrf_token,
    'jobs' => $jobs,
    'logs' => $logs,
    'user' => $auth->getUser()
];

// Render cron template
echo $twig->render('admin/pages/cron.twig', $templateData);