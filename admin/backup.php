<?php
/**
 * Admin Backup Management
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

// Get backup directory
$backup_dir = __DIR__ . '/../backups';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Get list of backups from database
$stmt = $pdo->query("
    SELECT b.*, u.name as created_by_name, u.email as created_by_email
    FROM tp_backups b
    LEFT JOIN tp_users u ON b.created_by = u.id
    ORDER BY b.created_at DESC
");
$backups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check actual files
foreach ($backups as &$backup) {
    $file_path = $backup_dir . '/' . $backup['file_name'];
    $backup['file_exists'] = file_exists($file_path);
    if ($backup['file_exists']) {
        $backup['actual_size'] = filesize($file_path);
    }
}

// Get database size estimate
$stmt = $pdo->query("
    SELECT 
        SUM(data_length + index_length) as size_bytes
    FROM information_schema.TABLES 
    WHERE table_schema = DATABASE()
        AND table_name LIKE 'tp_%'
");
$db_size = $stmt->fetchColumn() ?: 0;

// Prepare template data
$templateData = [
    'title' => 'Backup Verwaltung',
    'csrf_token' => $csrf_token,
    'backups' => $backups,
    'backup_dir' => $backup_dir,
    'db_size' => $db_size,
    'can_exec' => function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions'))),
    'user' => $auth->getUser()
];

// Render backup template
echo $twig->render('admin/pages/backup.twig', $templateData);